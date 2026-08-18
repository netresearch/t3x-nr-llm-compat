<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlmCompat\Tests\Solver\Unit;

use EliasHaeussler\Typo3Solver\ProblemSolving\Solution\Provider\SolutionProvider;
use Netresearch\NrLlmCompat\Bridge\Solver\NrLlmSolutionProvider;
use Netresearch\NrLlmCompat\Integration\Diagnostics\ContractVerifier;
use Netresearch\NrLlmCompat\Integration\Diagnostics\VersionVerifier;
use Netresearch\NrLlmCompat\Integration\IntegrationStrategy;
use Netresearch\NrLlmCompat\Integration\SolverIntegration;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(SolverIntegration::class)]
final class SolverIntegrationTest extends TestCase
{
    private SolverIntegration $subject;

    protected function setUp(): void
    {
        parent::setUp();
        $this->subject = new SolverIntegration();
        unset($GLOBALS['TYPO3_CONF_VARS']);
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['TYPO3_CONF_VARS']);
        parent::tearDown();
    }

    #[Test]
    public function describesTheSolverPackage(): void
    {
        self::assertSame('eliashaeussler/typo3-solver', $this->subject->getPackageName());
        self::assertSame('solver', $this->subject->getExtensionKey());
        self::assertSame(IntegrationStrategy::ProviderConfiguration, $this->subject->getStrategy());
        self::assertSame(['completion'], $this->subject->getCapabilities());
        self::assertSame(
            [SolutionProvider::class => NrLlmSolutionProvider::class],
            $this->subject->getServiceReplacements(),
        );
    }

    /**
     * THE reference assertion of this integration: the contract declared in
     * the descriptor holds against the REAL, unmodified typo3-solver package
     * installed from Packagist into this (isolated, #8) test environment.
     */
    #[Test]
    public function declaredContractMatchesTheInstalledSolverPackage(): void
    {
        self::assertSame([], (new ContractVerifier())->verify($this->subject));
    }

    #[Test]
    public function installedSolverVersionIsInsideTheSupportedRange(): void
    {
        $verifier = new VersionVerifier();

        self::assertTrue($verifier->isInstalled('eliashaeussler/typo3-solver'));
        self::assertTrue($verifier->satisfies('eliashaeussler/typo3-solver', $this->subject->getSupportedVersions()));
    }

    #[Test]
    public function runtimeConfigurationPointsTheSolverAtTheBridge(): void
    {
        $this->subject->applyRuntimeConfiguration();

        $confVars = $GLOBALS['TYPO3_CONF_VARS'] ?? null;
        self::assertIsArray($confVars);
        self::assertIsArray($confVars['EXTENSIONS'] ?? null);
        self::assertIsArray($confVars['EXTENSIONS']['solver'] ?? null);
        self::assertSame(NrLlmSolutionProvider::class, $confVars['EXTENSIONS']['solver']['provider'] ?? null);
    }
}
