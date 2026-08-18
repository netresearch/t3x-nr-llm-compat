<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlmCompat\Integration;

use Netresearch\NrLlmCompat\Bridge\Solver\NrLlmSolutionProvider;
use Netresearch\NrLlmCompat\Integration\Contract\MethodContract;

/**
 * Integration for eliashaeussler/typo3-solver (extension key "solver").
 *
 * The solver officially supports custom providers: its Configuration reads
 * the extension-configuration `provider` class, requires it to implement
 * SolutionProvider, and instantiates it via `$class::create()`. Nothing is
 * overridden — the runtime configuration points that setting at the bridge,
 * which the compiler pass registers as a public service for create() to
 * resolve.
 *
 * Class names are deliberately plain strings: unlike the other third-party
 * packages, typo3-solver cannot live in this repo's require-dev set — its
 * `openai-php/client ^0.18+` floor conflicts with ai-filemetadata's `^0.10`
 * pin (issue #8), so its classes are absent from the main test environment.
 * The dedicated environment under Tests/SolverEnvironment/ installs the
 * unmodified package and runs this integration's suites; this file and the
 * bridge are excluded from the main environment's static analysis for the
 * same reason.
 *
 * Contracts mirror the verified 3.3.4 source.
 */
final class SolverIntegration implements IntegrationInterface, ProvidesRuntimeConfiguration
{
    private const SOLUTION_PROVIDER = 'EliasHaeussler\Typo3Solver\ProblemSolving\Solution\Provider\SolutionProvider';

    private const CONFIGURATION = 'EliasHaeussler\Typo3Solver\Configuration\Configuration';

    private const PROBLEM = 'EliasHaeussler\Typo3Solver\ProblemSolving\Problem\Problem';

    private const SOLUTION = 'EliasHaeussler\Typo3Solver\ProblemSolving\Solution\Solution';

    private const COMPLETION_RESPONSE = 'EliasHaeussler\Typo3Solver\ProblemSolving\Solution\Model\CompletionResponse';

    private const MESSAGE = 'EliasHaeussler\Typo3Solver\ProblemSolving\Solution\Model\Message';

    public function getPackageName(): string
    {
        return 'eliashaeussler/typo3-solver';
    }

    public function getExtensionKey(): string
    {
        return 'solver';
    }

    public function getSupportedVersions(): string
    {
        return '^3.3';
    }

    public function getStrategy(): IntegrationStrategy
    {
        return IntegrationStrategy::ProviderConfiguration;
    }

    public function getCapabilities(): array
    {
        return ['completion'];
    }

    public function getServiceReplacements(): array
    {
        return [
            self::SOLUTION_PROVIDER => NrLlmSolutionProvider::class,
        ];
    }

    public function getClassContracts(): array
    {
        return [];
    }

    public function getMethodContracts(): array
    {
        return [
            // The provider interface the bridge implements.
            new MethodContract(self::SOLUTION_PROVIDER, 'create', [], 'static'),
            new MethodContract(self::SOLUTION_PROVIDER, 'getSolution', [self::PROBLEM], self::SOLUTION),
            new MethodContract(self::SOLUTION_PROVIDER, 'canBeUsed', ['Throwable'], 'bool'),
            new MethodContract(self::SOLUTION_PROVIDER, 'isCacheable', [], 'bool'),
            new MethodContract(self::SOLUTION_PROVIDER, 'listModels', ['bool'], 'array'),
            // The reading side of the hook.
            new MethodContract(self::CONFIGURATION, 'getProvider', [], self::SOLUTION_PROVIDER),
            // The settings the bridge honors.
            new MethodContract(self::CONFIGURATION, 'getMaxTokens', [], 'int'),
            new MethodContract(self::CONFIGURATION, 'getTemperature', [], 'float'),
            new MethodContract(self::CONFIGURATION, 'getNumberOfCompletions', [], 'int'),
            new MethodContract(self::CONFIGURATION, 'getIgnoredCodes', [], 'array'),
            // The shapes the bridge constructs and consumes.
            new MethodContract(self::PROBLEM, 'getPrompt', [], 'string'),
            new MethodContract(self::SOLUTION, '__construct', ['array', 'string', 'string'], null),
            new MethodContract(self::COMPLETION_RESPONSE, '__construct', ['int', self::MESSAGE, 'string'], null),
            new MethodContract(self::MESSAGE, '__construct', ['string', 'string'], null),
        ];
    }

    public function getPropertyContracts(): array
    {
        return [];
    }

    public function applyRuntimeConfiguration(): void
    {
        $confVars = $GLOBALS['TYPO3_CONF_VARS'] ?? null;
        $confVars = is_array($confVars) ? $confVars : [];

        $extensions = is_array($confVars['EXTENSIONS'] ?? null) ? $confVars['EXTENSIONS'] : [];
        $solver = is_array($extensions['solver'] ?? null) ? $extensions['solver'] : [];

        $solver['provider'] = NrLlmSolutionProvider::class;
        $extensions['solver'] = $solver;
        $confVars['EXTENSIONS'] = $extensions;
        $GLOBALS['TYPO3_CONF_VARS'] = $confVars;
    }
}
