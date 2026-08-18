<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlmCompat\Tests\Unit\Integration;

use Netresearch\NrLlmCompat\Integration\AiSeoHelperIntegration;
use Netresearch\NrLlmCompat\Integration\IntegrationRegistry;
use Netresearch\NrLlmCompat\Tests\Unit\Fixtures\ConfigurableIntegration;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

#[CoversClass(IntegrationRegistry::class)]
final class IntegrationRegistryTest extends UnitTestCase
{
    #[Test]
    public function allReturnsTheConstructedIntegrations(): void
    {
        $first = new ConfigurableIntegration(extensionKey: 'first');
        $second = new ConfigurableIntegration(extensionKey: 'second');

        self::assertSame([$first, $second], (new IntegrationRegistry($first, $second))->all());
    }

    #[Test]
    public function defaultIntegrationsContainAiSeoHelper(): void
    {
        $integrations = IntegrationRegistry::withDefaultIntegrations()->all();

        self::assertCount(1, $integrations);
        self::assertInstanceOf(AiSeoHelperIntegration::class, $integrations[0]);
    }
}
