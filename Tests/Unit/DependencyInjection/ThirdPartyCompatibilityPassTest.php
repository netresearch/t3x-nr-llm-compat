<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlmCompat\Tests\Unit\DependencyInjection;

use Netresearch\NrLlmCompat\DependencyInjection\ThirdPartyCompatibilityPass;
use Netresearch\NrLlmCompat\Integration\IntegrationRegistry;
use Netresearch\NrLlmCompat\Tests\Unit\Fixtures\ConfigurableIntegration;
use Netresearch\NrLlmCompat\Tests\Unit\Fixtures\ConfigurableRuntimeIntegration;
use Netresearch\NrLlmCompat\Tests\Unit\Fixtures\FixtureBridge;
use Netresearch\NrLlmCompat\Tests\Unit\Fixtures\FixtureContractImplementation;
use Netresearch\NrLlmCompat\Tests\Unit\Fixtures\FixtureService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

#[CoversClass(ThirdPartyCompatibilityPass::class)]
final class ThirdPartyCompatibilityPassTest extends UnitTestCase
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

    private function buildContainerWithForeignService(): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $definition = new Definition(FixtureService::class);
        $definition->setArgument('$settings', ['keep' => 'me']);

        $container->setDefinition(FixtureService::class, $definition);

        return $container;
    }

    #[Test]
    public function activeIntegrationReplacesServiceClassAndKeepsServiceIdAndArguments(): void
    {
        $integration = new ConfigurableIntegration(packageName: self::INSTALLED_PACKAGE);
        $this->enable($integration->getExtensionKey());
        $container = $this->buildContainerWithForeignService();

        (new ThirdPartyCompatibilityPass(new IntegrationRegistry($integration)))->process($container);

        $definition = $container->getDefinition(FixtureService::class);
        self::assertSame(FixtureBridge::class, $definition->getClass());
        self::assertSame(['keep' => 'me'], $definition->getArgument('$settings'));
    }

    #[Test]
    public function disabledIntegrationLeavesServiceUntouched(): void
    {
        $integration = new ConfigurableIntegration(packageName: self::INSTALLED_PACKAGE);
        $container = $this->buildContainerWithForeignService();

        (new ThirdPartyCompatibilityPass(new IntegrationRegistry($integration)))->process($container);

        self::assertSame(FixtureService::class, $container->getDefinition(FixtureService::class)->getClass());
    }

    #[Test]
    public function notInstalledIntegrationLeavesServiceUntouched(): void
    {
        $integration = new ConfigurableIntegration(packageName: 'acme/definitely-not-installed');
        $this->enable($integration->getExtensionKey());
        $container = $this->buildContainerWithForeignService();

        (new ThirdPartyCompatibilityPass(new IntegrationRegistry($integration)))->process($container);

        self::assertSame(FixtureService::class, $container->getDefinition(FixtureService::class)->getClass());
    }

    #[Test]
    public function unsupportedVersionLeavesServiceUntouched(): void
    {
        $integration = new ConfigurableIntegration(
            packageName: self::INSTALLED_PACKAGE,
            supportedVersions: '<0.0.1',
        );
        $this->enable($integration->getExtensionKey());
        $container = $this->buildContainerWithForeignService();

        (new ThirdPartyCompatibilityPass(new IntegrationRegistry($integration)))->process($container);

        self::assertSame(FixtureService::class, $container->getDefinition(FixtureService::class)->getClass());
    }

    #[Test]
    public function activeProviderConfigurationIntegrationRegistersThePublicBridgeService(): void
    {
        $integration = new ConfigurableRuntimeIntegration(packageName: self::INSTALLED_PACKAGE);
        $this->enable($integration->getExtensionKey());
        $container = new ContainerBuilder();

        (new ThirdPartyCompatibilityPass(new IntegrationRegistry($integration)))->process($container);

        self::assertTrue($container->hasDefinition(FixtureContractImplementation::class));
        $definition = $container->getDefinition(FixtureContractImplementation::class);
        self::assertTrue($definition->isPublic());
        self::assertTrue($definition->isAutowired());
    }

    #[Test]
    public function disabledProviderConfigurationIntegrationRegistersNothing(): void
    {
        $integration = new ConfigurableRuntimeIntegration(packageName: self::INSTALLED_PACKAGE);
        $container = new ContainerBuilder();

        (new ThirdPartyCompatibilityPass(new IntegrationRegistry($integration)))->process($container);

        self::assertFalse($container->hasDefinition(FixtureContractImplementation::class));
    }

    #[Test]
    public function missingServiceDefinitionIsSkippedWithoutError(): void
    {
        $integration = new ConfigurableIntegration(packageName: self::INSTALLED_PACKAGE);
        $this->enable($integration->getExtensionKey());
        $container = new ContainerBuilder();

        (new ThirdPartyCompatibilityPass(new IntegrationRegistry($integration)))->process($container);

        self::assertFalse($container->hasDefinition(FixtureService::class));
    }
}
