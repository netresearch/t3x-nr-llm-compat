<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlmCompat\Tests\Unit\Fixtures;

use Netresearch\NrLlmCompat\Integration\Contract\ClassContract;
use Netresearch\NrLlmCompat\Integration\Contract\MethodContract;
use Netresearch\NrLlmCompat\Integration\Contract\PropertyContract;
use Netresearch\NrLlmCompat\Integration\IntegrationInterface;
use Netresearch\NrLlmCompat\Integration\IntegrationStrategy;

/**
 * Integration descriptor with every field injectable, for testing the
 * diagnostics and the compiler pass without a real third-party package.
 */
final readonly class ConfigurableIntegration implements IntegrationInterface
{
    /**
     * @param array<string, class-string> $serviceReplacements
     * @param list<ClassContract>         $classContracts
     * @param list<MethodContract>        $methodContracts
     * @param list<PropertyContract>      $propertyContracts
     */
    public function __construct(
        private string $packageName = 'acme/fixture-extension',
        private string $extensionKey = 'fixture_extension',
        private string $supportedVersions = '*',
        private array $serviceReplacements = [FixtureService::class => FixtureBridge::class],
        private array $classContracts = [],
        private array $methodContracts = [],
        private array $propertyContracts = [],
    ) {}

    public function getClassContracts(): array
    {
        return $this->classContracts;
    }

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
        return IntegrationStrategy::DiClassReplacement;
    }

    public function getCapabilities(): array
    {
        return ['completion'];
    }

    public function getServiceReplacements(): array
    {
        return $this->serviceReplacements;
    }

    public function getMethodContracts(): array
    {
        return $this->methodContracts;
    }

    public function getPropertyContracts(): array
    {
        return $this->propertyContracts;
    }
}
