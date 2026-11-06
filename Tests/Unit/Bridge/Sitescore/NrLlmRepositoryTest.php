<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlmCompat\Tests\Unit\Bridge\Sitescore;

use Netresearch\NrLlm\Domain\Model\CompletionResponse;
use Netresearch\NrLlm\Domain\Model\UsageStatistics;
use Netresearch\NrLlm\Testing\FakeCompletionService;
use Netresearch\NrLlmCompat\Bridge\Sitescore\NrLlmRepository;
use Netresearch\NrLlmCompat\Exception\UnexpectedAiResponseException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use RuntimeException;
use TYPO3\CMS\Core\Http\RequestFactory;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

#[CoversClass(NrLlmRepository::class)]
final class NrLlmRepositoryTest extends UnitTestCase
{
    private const ANALYSIS = [
        'scores' => [
            'geo'           => 85,
            'performance'   => 70,
            'semantics'     => 95,
            'keywords'      => 60,
            'accessibility' => 90,
        ],
        'suggestions' => [
            ['type' => 'warning', 'message' => 'Keyword density too low'],
        ],
    ];

    private FakeCompletionService $completionService;

    private RequestFactory&MockObject $requestFactory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->completionService = new FakeCompletionService();
        $this->requestFactory    = $this->createMock(RequestFactory::class);
        // The whole point of the bridge: the analysis must never reach the
        // original Gemini HTTP transport.
        $this->requestFactory->expects(self::never())->method('request');
    }

    private function createSubject(): NrLlmRepository
    {
        return new NrLlmRepository($this->completionService, $this->requestFactory);
    }

    private function answer(string $content): void
    {
        $this->completionService->responses[] = new CompletionResponse($content, 'test-model', new UsageStatistics(1, 1, 2));
    }

    #[Test]
    public function analyzePageContentSendsSitescoresPromptThroughNrLlm(): void
    {
        $this->answer((string) json_encode(self::ANALYSIS));

        $result = $this->createSubject()->analyzePageContent('<main><h1>TYPO3 hosting</h1></main>', 'Hosting page', 'typo3 hosting');

        self::assertSame(self::ANALYSIS, $result);
        self::assertCount(1, $this->completionService->completeCalls);
        $prompt = $this->completionService->completeCalls[0]['prompt'];
        self::assertStringContainsString('<main><h1>TYPO3 hosting</h1></main>', $prompt);
        self::assertStringContainsString('**Page Title**: Hosting page', $prompt);
        self::assertStringContainsString('**Target Keyword**: typo3 hosting', $prompt);
    }

    /**
     * The prompt comes from sitescore's own AbstractRepository::getPrompt(),
     * not a copy: its "no keyword" instruction is part of the request.
     */
    #[Test]
    public function anEmptyKeywordKeepsSitescoresOwnNoKeywordInstruction(): void
    {
        $this->answer((string) json_encode(self::ANALYSIS));

        $this->createSubject()->analyzePageContent('<p>Text</p>', 'Page', '');

        $prompt = $this->completionService->completeCalls[0]['prompt'];
        self::assertStringContainsString('**Target Keyword**: Not specified', $prompt);
        self::assertStringContainsString("Set the 'keywords' score to 0", $prompt);
    }

    #[Test]
    public function theRequestAsksForJsonWithGeminisSystemInstructionAndOutputBudget(): void
    {
        $this->answer((string) json_encode(self::ANALYSIS));

        $this->createSubject()->analyzePageContent('<p>Text</p>', 'Page', 'keyword');

        $options = $this->completionService->completeCalls[0]['options'];
        self::assertNotNull($options);
        self::assertSame('json', $options->getResponseFormat());
        // GeminiRepository sends no temperature; neither does the bridge.
        self::assertNull($options->getTemperature());
        self::assertSame(4096, $options->getMaxTokens());
        self::assertStringStartsWith('You are an expert SEO consultant and content quality analyst.', (string) $options->getSystemPrompt());
        self::assertSame('sitescore', $options->getCallerSourceExtension());
        self::assertSame('analyzePageContent', $options->getCallerSourceOperation());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function wrappedAnswers(): iterable
    {
        $json = (string) json_encode(self::ANALYSIS);

        yield 'json fence, as the prompt shows it' => ["```json\n" . $json . "\n```"];
        yield 'plain fence' => ["```\n" . $json . "\n```"];
        yield 'prose around the object' => ['Here is the analysis: ' . $json . ' Hope this helps.'];
    }

    #[Test]
    #[DataProvider('wrappedAnswers')]
    public function theJsonObjectIsTakenFromInsideAFenceOrProse(string $answer): void
    {
        $this->answer($answer);

        self::assertSame(self::ANALYSIS, $this->createSubject()->analyzePageContent('<p>Text</p>', 'Page', 'keyword'));
    }

    #[Test]
    public function onlyScoresAndSuggestionsReachSitescore(): void
    {
        $this->answer((string) json_encode(self::ANALYSIS + ['reasoning' => 'not part of the contract']));

        self::assertSame(self::ANALYSIS, $this->createSubject()->analyzePageContent('<p>Text</p>', 'Page', 'keyword'));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unusableAnswers(): iterable
    {
        yield 'no JSON at all' => ['I cannot analyse this page.'];
        yield 'broken JSON' => ['{"scores": {"geo": 85}, "suggestions": ['];
        yield 'scores missing' => ['{"suggestions": []}'];
        yield 'suggestions missing' => ['{"scores": {"geo": 85}}'];
        yield 'suggestions not a list' => ['{"scores": {"geo": 85}, "suggestions": "none"}'];
        yield 'scores not an object' => ['{"scores": 85, "suggestions": []}'];
    }

    #[Test]
    #[DataProvider('unusableAnswers')]
    public function anAnswerWithoutScoresAndSuggestionsIsRejected(string $answer): void
    {
        $this->answer($answer);

        $this->expectException(UnexpectedAiResponseException::class);
        $this->expectExceptionCode(1755500005);

        $this->createSubject()->analyzePageContent('<p>Text</p>', 'Page', 'keyword');
    }

    #[Test]
    public function analyzePageContentFailsClosedWhenNrLlmIsUnavailable(): void
    {
        $this->completionService->throwable = new RuntimeException('no provider available');

        try {
            // setUp's never() expectation on the request factory asserts the
            // other half: no silent fallback to the original Gemini call.
            $this->createSubject()->analyzePageContent('<p>Text</p>', 'Page', 'keyword');
            self::fail('Expected the nr-llm exception to propagate.');
        } catch (RuntimeException $exception) {
            self::assertSame('no provider available', $exception->getMessage());
        }
    }

    #[Test]
    public function interfaceObligationsAreServedWithoutTouchingAnyProvider(): void
    {
        $subject = $this->createSubject();

        $subject->checkApiKey();

        self::assertSame('nr-llm', $subject->getApiUrl());

        $options = $subject->getOptions('<p>Text</p>', 'Page', 'keyword');
        self::assertStringContainsString('<p>Text</p>', $options['prompt']);
        self::assertSame(4096, $options['maxTokens']);
        self::assertSame([], $this->completionService->completeCalls);
    }
}
