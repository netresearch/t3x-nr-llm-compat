<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlmCompat\Tests\Unit\Fixtures;

use Netresearch\NrLlmCompat\Integration\Contract\MethodContract;
use Netresearch\NrLlmCompat\Integration\IntegrationInterface;
use Netresearch\NrLlmCompat\Integration\IntegrationStrategy;
use Netresearch\NrLlmCompat\Integration\ProvidesRuntimeConfiguration;

/**
 * Provider-configuration fixture: counts its runtime applications so the
 * applier's Active gate is observable.
 */
final class ConfigurableRuntimeIntegration implements IntegrationInterface, ProvidesRuntimeConfiguration
{
    public int $applyCalls = 0;

    public function __construct(
        private readonly string $packageName = 'acme/fixture-extension',
        private readonly string $extensionKey = 'fixture_extension',
        private readonly string $supportedVersions = '*',
    ) {}

    public function getPackageName(): string
    {
        return $this->packageName;
    }

    public function getExtensionKey(): string
    {
        return $this->extensionKey;
    }

    public function getSupportedVersions(): string
    {
        return $this->supportedVersions;
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
        return [FixtureContractInterface::class => FixtureContractImplementation::class];
    }

    public function getClassContracts(): array
    {
        return [];
    }

    /**
     * @return list<MethodContract>
     */
    public function getMethodContracts(): array
    {
        return [];
    }

    public function getPropertyContracts(): array
    {
        return [];
    }

    public function applyRuntimeConfiguration(): void
    {
        ++$this->applyCalls;
    }
}
