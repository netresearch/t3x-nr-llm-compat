<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlmCompat\Tests\Unit\Fixtures;

use Netresearch\NrLlmCompat\Integration\IntegrationInterface;
use Netresearch\NrLlmCompat\Integration\IntegrationStrategy;

/**
 * Tool-provision fixture: names one class the compiler pass must register
 * as an nr_llm.tool service when the integration is Active.
 */
final readonly class ConfigurableToolIntegration implements IntegrationInterface
{
    public function __construct(
        private string $packageName = 'acme/fixture-extension',
        private string $extensionKey = 'fixture_extension',
        private string $supportedVersions = '*',
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
        return IntegrationStrategy::ToolProvision;
    }

    public function getCapabilities(): array
    {
        return ['fixture_tool'];
    }

    public function getServiceReplacements(): array
    {
        return [FixtureContractInterface::class => FixtureContractImplementation::class];
    }

    public function getClassContracts(): array
    {
        return [];
    }

    public function getMethodContracts(): array
    {
        return [];
    }

    public function getPropertyContracts(): array
    {
        return [];
    }
}
