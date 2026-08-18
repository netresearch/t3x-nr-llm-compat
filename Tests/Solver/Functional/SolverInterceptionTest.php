<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlmCompat\Tests\Solver\Functional;

use EliasHaeussler\Typo3Solver\Configuration\Configuration;
use EliasHaeussler\Typo3Solver\ProblemSolving\Problem\Problem;
use Netresearch\NrLlm\Domain\Model\CompletionResponse;
use Netresearch\NrLlm\Domain\Model\UsageStatistics;
use Netresearch\NrLlm\Service\Feature\CompletionServiceInterface;
use Netresearch\NrLlm\Testing\FakeCompletionService;
use Netresearch\NrLlmCompat\Bridge\Solver\NrLlmSolutionProvider;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;

/**
 * The integration enabled: the solver's own Configuration resolves the
 * bridge through its official provider setting and ::create() — with the
 * extension's OpenAI API key EMPTY — and a solution runs end-to-end through
 * nr-llm's completion surface.
 */
final class SolverInterceptionTest extends AbstractSolverTestCase
{
    protected array $configurationToUseInTestInstance = [
        'EXTENSIONS' => [
            'solver' => self::SOLVER_CONFIGURATION,
            'nr_llm_compat' => [
                'integrations' => [
                    'solver' => '1',
                ],
            ],
        ],
    ];

    #[Test]
    public function solverResolvesTheBridgeThroughItsOwnProviderSetting(): void
    {
        $provider = (new Configuration())->getProvider();

        self::assertInstanceOf(NrLlmSolutionProvider::class, $provider);
    }

    #[Test]
    public function aSolutionRunsThroughNrLlmWithEmptyOpenAiKey(): void
    {
        $fake = $this->get(CompletionServiceInterface::class);
        self::assertInstanceOf(FakeCompletionService::class, $fake);
        $fake->responses[] = new CompletionResponse('Check the configuration.', 'served-model', new UsageStatistics(1, 1, 2));

        $provider = (new Configuration())->getProvider();
        $problem = new Problem(new RuntimeException('boom', 1234567890), $provider, 'Solve this exception');

        $solution = $provider->getSolution($problem);

        self::assertSame('served-model', $solution->model);
        self::assertSame('Check the configuration.', $solution->responses[0]->message->content);
        self::assertSame('Solve this exception', $fake->completeCalls[0]['prompt']);
    }
}
