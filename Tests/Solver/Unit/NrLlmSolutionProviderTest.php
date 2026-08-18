<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlmCompat\Tests\Solver\Unit;

use EliasHaeussler\Typo3Solver\Configuration\Configuration;
use EliasHaeussler\Typo3Solver\ProblemSolving\Problem\Problem;
use Netresearch\NrLlm\Domain\Model\CompletionResponse;
use Netresearch\NrLlm\Domain\Model\UsageStatistics;
use Netresearch\NrLlm\Testing\FakeCompletionService;
use Netresearch\NrLlmCompat\Bridge\Solver\NrLlmSolutionProvider;
use Netresearch\NrLlmCompat\Tests\Solver\Unit\Fixtures\ArrayConfigurationProvider;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[CoversClass(NrLlmSolutionProvider::class)]
final class NrLlmSolutionProviderTest extends TestCase
{
    private FakeCompletionService $completions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->completions = new FakeCompletionService();
    }

    /**
     * @param array<string, mixed> $settings
     */
    private function createSubject(array $settings = []): NrLlmSolutionProvider
    {
        return new NrLlmSolutionProvider(
            $this->completions,
            new Configuration(new ArrayConfigurationProvider($settings)),
        );
    }

    private function queueResponse(string $text): void
    {
        $this->completions->responses[] = new CompletionResponse($text, 'served-model', new UsageStatistics(1, 1, 2));
    }

    private function problem(string $prompt = 'Solve this exception'): Problem
    {
        return new Problem(new RuntimeException('boom', 1234567890), $this->createSubject(), $prompt);
    }

    #[Test]
    public function getSolutionRoutesThePromptThroughNrLlm(): void
    {
        $this->queueResponse('Check the configuration.');

        $solution = $this->createSubject()->getSolution($this->problem());

        self::assertSame('Solve this exception', $solution->prompt);
        self::assertSame('served-model', $solution->model);
        self::assertCount(1, $solution->responses);
        self::assertSame('Check the configuration.', $solution->responses[0]->message->content);
        self::assertSame('assistant', $solution->responses[0]->message->role);
        self::assertSame('Solve this exception', $this->completions->completeCalls[0]['prompt']);
    }

    #[Test]
    public function getSolutionHonorsTheExtensionsRequestShaping(): void
    {
        $this->queueResponse('Answer');

        $this->createSubject([
            'attributes/temperature' => 0.3,
            'attributes/maxTokens' => 450,
        ])->getSolution($this->problem());

        $options = $this->completions->completeCalls[0]['options'];
        self::assertNotNull($options);
        self::assertSame(0.3, $options->getTemperature());
        self::assertSame(450, $options->getMaxTokens());
    }

    #[Test]
    public function getSolutionProducesOneResponsePerConfiguredCompletion(): void
    {
        $this->queueResponse('First');
        $this->queueResponse('Second');
        $this->queueResponse('Third');

        $solution = $this->createSubject(['attributes/numberOfCompletions' => 3])
            ->getSolution($this->problem());

        self::assertCount(3, $solution->responses);
        self::assertSame([0, 1, 2], array_map(static fn($response) => $response->index, $solution->responses));
        self::assertCount(3, $this->completions->completeCalls);
    }

    #[Test]
    public function getSolutionCapsARunawayCompletionCount(): void
    {
        for ($i = 0; $i < 10; ++$i) {
            $this->queueResponse('Answer ' . $i);
        }

        $solution = $this->createSubject(['attributes/numberOfCompletions' => 5000])
            ->getSolution($this->problem());

        self::assertCount(10, $solution->responses);
    }

    #[Test]
    public function getSolutionFailsClosedWhenNrLlmIsUnavailable(): void
    {
        $this->completions->throwable = new RuntimeException('no provider available');

        try {
            $this->createSubject()->getSolution($this->problem());
            self::fail('Expected the nr-llm exception to propagate.');
        } catch (RuntimeException $exception) {
            self::assertSame('no provider available', $exception->getMessage());
        }
    }

    #[Test]
    public function canBeUsedHonorsTheConfiguredIgnoreListLikeTheOriginal(): void
    {
        $subject = $this->createSubject(['ignoredCodes' => '1234567890,42']);

        self::assertFalse($subject->canBeUsed(new RuntimeException('ignored', 1234567890)));
        self::assertTrue($subject->canBeUsed(new RuntimeException('handled', 99)));
    }

    #[Test]
    public function solutionsAreCacheableLikeTheOriginal(): void
    {
        self::assertTrue($this->createSubject()->isCacheable());
    }

    #[Test]
    public function listModelsIsHonestlyEmpty(): void
    {
        // The extension's model picker configures ITS model setting, which
        // this provider does not consume — model routing belongs to nr-llm.
        self::assertSame([], $this->createSubject()->listModels());
        self::assertSame([], $this->createSubject()->listModels(true));
    }
}
