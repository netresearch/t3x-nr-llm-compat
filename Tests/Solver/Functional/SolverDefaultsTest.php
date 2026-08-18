<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlmCompat\Tests\Solver\Functional;

use EliasHaeussler\Typo3Solver\Configuration\Configuration;
use EliasHaeussler\Typo3Solver\ProblemSolving\Solution\Provider\OpenAISolutionProvider;
use Netresearch\NrLlmCompat\Bridge\Solver\NrLlmSolutionProvider;
use PHPUnit\Framework\Attributes\Test;

/**
 * Default state: nr_llm_compat installed but the integration NOT enabled —
 * the provider setting stays untouched and the solver keeps its own OpenAI
 * provider. A dummy API key is configured because the ORIGINAL provider's
 * create() refuses to instantiate without one — which is exactly the
 * behavior the integration removes.
 */
final class SolverDefaultsTest extends AbstractSolverTestCase
{
    protected array $configurationToUseInTestInstance = [
        'EXTENSIONS' => [
            'solver' => [
                'api' => [
                    'key' => 'sk-dummy-for-instantiation-only',
                ],
            ] + self::SOLVER_CONFIGURATION,
        ],
    ];

    #[Test]
    public function solverKeepsItsDefaultOpenAiProvider(): void
    {
        $provider = (new Configuration())->getProvider();

        self::assertNotInstanceOf(NrLlmSolutionProvider::class, $provider);
        self::assertInstanceOf(OpenAISolutionProvider::class, $provider);
    }
}
