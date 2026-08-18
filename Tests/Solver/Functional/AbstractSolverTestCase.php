<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlmCompat\Tests\Solver\Functional;

use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * Base for functional tests against the REAL, unmodified typo3-solver
 * package — installed from Packagist into the isolated environment under
 * Tests/SolverEnvironment/ (see #8 for why it cannot join the main one).
 */
abstract class AbstractSolverTestCase extends FunctionalTestCase
{
    protected array $testExtensionsToLoad = [
        'netresearch/nr-vault',
        'netresearch/nr-llm',
        'eliashaeussler/typo3-solver',
        'netresearch/nr-llm-compat',
        __DIR__ . '/../../Functional/Fixtures/Extensions/nr_llm_compat_fake',
    ];

    protected array $coreExtensionsToLoad = [
        'extbase',
        'fluid',
    ];

    /**
     * typo3-solver's ext_conf_template defaults (3.3.4) with an EMPTY OpenAI
     * API key: the definition of transparent interception is that the
     * extension works without its own provider credential.
     *
     * @var array<string, string|array<string, string>>
     */
    protected const SOLVER_CONFIGURATION = [
        'provider' => 'EliasHaeussler\Typo3Solver\Solution\Provider\OpenAISolutionProvider',
        'prompt' => 'EliasHaeussler\Typo3Solver\Prompt\DefaultPrompt',
        'ignoredCodes' => '',
        'api' => [
            'key' => '',
        ],
        'attributes' => [
            'model' => 'gpt-4o-mini',
            'maxTokens' => '300',
            'temperature' => '0.5',
            'numberOfCompletions' => '1',
        ],
        'cache' => [
            'lifetime' => '86400',
        ],
    ];
}
