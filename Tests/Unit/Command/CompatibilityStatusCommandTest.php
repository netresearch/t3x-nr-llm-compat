<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlmCompat\Tests\Unit\Command;

use Netresearch\NrLlmCompat\Command\CompatibilityStatusCommand;
use Netresearch\NrLlmCompat\Integration\Diagnostics\StatusReporter;
use Netresearch\NrLlmCompat\Integration\IntegrationRegistry;
use Netresearch\NrLlmCompat\Tests\Unit\Fixtures\ConfigurableIntegration;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

#[CoversClass(CompatibilityStatusCommand::class)]
final class CompatibilityStatusCommandTest extends UnitTestCase
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

    private function runCommand(ConfigurableIntegration $integration): CommandTester
    {
        $command = new CompatibilityStatusCommand(
            new IntegrationRegistry($integration),
            new StatusReporter(),
        );
        $command->setName('nrllm:compat:status');

        $tester = new CommandTester($command);
        $tester->execute([]);

        return $tester;
    }

    #[Test]
    public function reportsAnAvailableIntegrationAndSucceeds(): void
    {
        $integration = new ConfigurableIntegration(packageName: self::INSTALLED_PACKAGE);

        $tester = $this->runCommand($integration);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        $display = $tester->getDisplay();
        self::assertStringContainsString('fixture_extension', $display);
        self::assertStringContainsString('AVAILABLE', $display);
        self::assertStringContainsString('DI class replacement', $display);
    }

    #[Test]
    public function reportsAnActiveIntegrationAndSucceeds(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['nr_llm_compat']['integrations']['fixture_extension'] = '1';
        $integration = new ConfigurableIntegration(packageName: self::INSTALLED_PACKAGE);

        $tester = $this->runCommand($integration);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('ACTIVE', $tester->getDisplay());
    }

    #[Test]
    public function enabledButNotInstalledIntegrationFailsTheCommand(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['nr_llm_compat']['integrations']['fixture_extension'] = '1';
        $integration = new ConfigurableIntegration(packageName: 'acme/definitely-not-installed');

        $tester = $this->runCommand($integration);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        $display = $tester->getDisplay();
        self::assertStringContainsString('NOT INSTALLED', $display);
        self::assertStringContainsString('does NOT intercept', $display);
    }

    #[Test]
    public function notEnabledAndNotInstalledIntegrationStillSucceeds(): void
    {
        $integration = new ConfigurableIntegration(packageName: 'acme/definitely-not-installed');

        $tester = $this->runCommand($integration);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('NOT INSTALLED', $tester->getDisplay());
    }
}
