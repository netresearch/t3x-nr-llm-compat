<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlmCompat\Tests\Unit\Integration;

use Netresearch\NrLlmCompat\Integration\IntegrationRegistry;
use Netresearch\NrLlmCompat\Integration\RuntimeConfigurationApplier;
use Netresearch\NrLlmCompat\Tests\Unit\Fixtures\ConfigurableIntegration;
use Netresearch\NrLlmCompat\Tests\Unit\Fixtures\ConfigurableRuntimeIntegration;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

#[CoversClass(RuntimeConfigurationApplier::class)]
final class RuntimeConfigurationApplierTest extends UnitTestCase
{
    private const INSTALLED_PACKAGE = 'netresearch/nr-llm';

    protected function setUp(): void
    {
        parent::setUp();
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
    public function appliesAnActiveRuntimeConfigurationIntegration(): void
    {
        $integration = new ConfigurableRuntimeIntegration(packageName: self::INSTALLED_PACKAGE);
        $this->enable($integration->getExtensionKey());

        (new RuntimeConfigurationApplier(new IntegrationRegistry($integration)))->apply();

        self::assertSame(1, $integration->applyCalls);
    }

    #[Test]
    public function skipsADisabledIntegration(): void
    {
        $integration = new ConfigurableRuntimeIntegration(packageName: self::INSTALLED_PACKAGE);

        (new RuntimeConfigurationApplier(new IntegrationRegistry($integration)))->apply();

        self::assertSame(0, $integration->applyCalls);
    }

    #[Test]
    public function skipsAnEnabledButNotInstalledIntegration(): void
    {
        $integration = new ConfigurableRuntimeIntegration(packageName: 'acme/definitely-not-installed');
        $this->enable($integration->getExtensionKey());

        (new RuntimeConfigurationApplier(new IntegrationRegistry($integration)))->apply();

        self::assertSame(0, $integration->applyCalls);
    }

    #[Test]
    public function ignoresIntegrationsWithoutRuntimeConfiguration(): void
    {
        // Completing without an error IS the behavior: the applier must not
        // assume every integration provides runtime configuration.
        $this->expectNotToPerformAssertions();

        $integration = new ConfigurableIntegration(packageName: self::INSTALLED_PACKAGE);
        $this->enable($integration->getExtensionKey());

        (new RuntimeConfigurationApplier(new IntegrationRegistry($integration)))->apply();
    }
}
