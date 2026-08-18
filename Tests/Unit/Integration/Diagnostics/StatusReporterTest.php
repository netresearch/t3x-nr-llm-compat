<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlmCompat\Tests\Unit\Integration\Diagnostics;

use Netresearch\NrLlmCompat\Integration\Contract\MethodContract;
use Netresearch\NrLlmCompat\Integration\Diagnostics\IntegrationState;
use Netresearch\NrLlmCompat\Integration\Diagnostics\StatusReporter;
use Netresearch\NrLlmCompat\Tests\Unit\Fixtures\ConfigurableIntegration;
use Netresearch\NrLlmCompat\Tests\Unit\Fixtures\FixtureService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

/**
 * Uses the REAL verifiers against packages that are genuinely (not)
 * installed in this test environment — no seams, no fakes:
 * "netresearch/nr-llm" is a hard dependency and therefore always installed,
 * "acme/definitely-not-installed" never is.
 */
#[CoversClass(StatusReporter::class)]
final class StatusReporterTest extends UnitTestCase
{
    private const INSTALLED_PACKAGE = 'netresearch/nr-llm';

    private StatusReporter $subject;

    protected function setUp(): void
    {
        parent::setUp();
        $this->subject = new StatusReporter();
        unset($GLOBALS['TYPO3_CONF_VARS']);
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['TYPO3_CONF_VARS']);
        parent::tearDown();
    }

    private function enable(string $extensionKey): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['nr_llm_compat']['integrations'][$extensionKey] = '1';
    }

    #[Test]
    public function notInstalledPackageEvaluatesToNotInstalled(): void
    {
        $integration = new ConfigurableIntegration(packageName: 'acme/definitely-not-installed');

        $status = $this->subject->evaluate($integration);

        self::assertSame(IntegrationState::NotInstalled, $status->state);
        self::assertNull($status->installedVersion);
        self::assertFalse($status->enabled);
    }

    #[Test]
    public function unsupportedVersionEvaluatesToUnsupportedVersion(): void
    {
        $integration = new ConfigurableIntegration(
            packageName: self::INSTALLED_PACKAGE,
            supportedVersions: '<0.0.1',
        );

        $status = $this->subject->evaluate($integration);

        self::assertSame(IntegrationState::UnsupportedVersion, $status->state);
        self::assertNotNull($status->installedVersion);
    }

    #[Test]
    public function contractViolationEvaluatesToIncompatibleContract(): void
    {
        $integration = new ConfigurableIntegration(
            packageName: self::INSTALLED_PACKAGE,
            methodContracts: [new MethodContract(FixtureService::class, 'vanishedMethod', [], null)],
        );
        $this->enable($integration->getExtensionKey());

        $status = $this->subject->evaluate($integration);

        self::assertSame(IntegrationState::IncompatibleContract, $status->state);
        self::assertTrue($status->enabled);
        self::assertNotSame([], $status->violations);
    }

    #[Test]
    public function compatibleButNotEnabledEvaluatesToAvailable(): void
    {
        $integration = new ConfigurableIntegration(packageName: self::INSTALLED_PACKAGE);

        $status = $this->subject->evaluate($integration);

        self::assertSame(IntegrationState::Available, $status->state);
        self::assertFalse($status->enabled);
    }

    #[Test]
    public function compatibleAndEnabledEvaluatesToActive(): void
    {
        $integration = new ConfigurableIntegration(packageName: self::INSTALLED_PACKAGE);
        $this->enable($integration->getExtensionKey());

        $status = $this->subject->evaluate($integration);

        self::assertSame(IntegrationState::Active, $status->state);
        self::assertTrue($status->enabled);
    }

    #[Test]
    public function disabledValueZeroStringDoesNotEnable(): void
    {
        $integration = new ConfigurableIntegration(packageName: self::INSTALLED_PACKAGE);
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['nr_llm_compat']['integrations'][$integration->getExtensionKey()] = '0';

        $status = $this->subject->evaluate($integration);

        self::assertSame(IntegrationState::Available, $status->state);
        self::assertFalse($status->enabled);
    }
}
