<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlmCompat\Tests\Functional;

use Netresearch\NrLlmCompat\Command\CompatibilityStatusCommand;
use PHPUnit\Framework\Attributes\Test;
use ReflectionProperty;
use TYPO3\CMS\Core\Console\CommandRegistry;
use TYPO3\CMS\Core\EventDispatcher\ListenerProvider;

/**
 * The status command reads the registered event listeners for its notes;
 * the container must hand it TYPO3's ListenerProvider.
 */
final class CompatibilityStatusCommandWiringTest extends AbstractAiFilemetadataTestCase
{
    #[Test]
    public function theStatusCommandReceivesTheListenerProvider(): void
    {
        $command = $this->get(CommandRegistry::class)->get('nrllm:compat:status');
        self::assertInstanceOf(CompatibilityStatusCommand::class, $command);

        $listenerProvider = (new ReflectionProperty(CompatibilityStatusCommand::class, 'listenerProvider'))->getValue($command);

        self::assertInstanceOf(ListenerProvider::class, $listenerProvider);
    }
}
