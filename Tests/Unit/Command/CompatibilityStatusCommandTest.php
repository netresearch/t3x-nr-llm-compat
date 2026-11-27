<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlmCompat\Tests\Unit\Command;

use Mfd\Ai\FileMetadata\Event\ModifyRequestDataEvent;
use Netresearch\NrLlmCompat\Command\CompatibilityStatusCommand;
use Netresearch\NrLlmCompat\Integration\AiFilemetadataIntegration;
use Netresearch\NrLlmCompat\Integration\Diagnostics\StatusReporter;
use Netresearch\NrLlmCompat\Integration\IntegrationInterface;
use Netresearch\NrLlmCompat\Integration\IntegrationRegistry;
use Netresearch\NrLlmCompat\Tests\Unit\Fixtures\ConfigurableIntegration;
use Netresearch\NrLlmCompat\Tests\Unit\Fixtures\ConfigurableToolIntegration;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Psr\Container\ContainerInterface;
use RuntimeException;
use stdClass;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use TYPO3\CMS\Core\EventDispatcher\ListenerProvider;
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

    private function runCommand(IntegrationInterface $integration): CommandTester
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
        $integration                                                                                    = new ConfigurableIntegration(packageName: self::INSTALLED_PACKAGE);

        $tester = $this->runCommand($integration);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('ACTIVE', $tester->getDisplay());
    }

    #[Test]
    public function reportsAToolProvisionIntegrationWithItsStrategyAndToolName(): void
    {
        $integration = new ConfigurableToolIntegration(packageName: self::INSTALLED_PACKAGE);

        $tester = $this->runCommand($integration);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        $display = $tester->getDisplay();
        self::assertStringContainsString('tool provision', $display);
        self::assertStringContainsString('fixture_tool', $display);
    }

    #[Test]
    public function enabledButNotInstalledIntegrationFailsTheCommand(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['nr_llm_compat']['integrations']['fixture_extension'] = '1';
        $integration                                                                                    = new ConfigurableIntegration(packageName: 'acme/definitely-not-installed');

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

    #[Test]
    public function namesRegisteredRequestEventListenersOfAiFilemetadata(): void
    {
        if (!class_exists(ModifyRequestDataEvent::class)) {
            self::markTestSkipped('The installed ai_filemetadata predates ModifyRequestDataEvent (1.7.1).');
        }

        $listeners = new ListenerProvider(self::createStub(ContainerInterface::class));
        $listeners->addListener(ModifyRequestDataEvent::class, 'Vendor\\Site\\AddWebSearchTool', 'addTool');

        $command = new CompatibilityStatusCommand(
            new IntegrationRegistry(new AiFilemetadataIntegration()),
            new StatusReporter(),
            $listeners,
        );
        $command->setName('nrllm:compat:status');

        $tester = new CommandTester($command);
        $tester->execute([]);

        // SymfonyStyle wraps a note and prefixes each line with "! ".
        $display = preg_replace('/\s+(?:!\s+)?/', ' ', $tester->getDisplay()) ?? '';
        self::assertStringContainsString('1 ModifyRequestDataEvent listener(s) registered: Vendor\\Site\\AddWebSearchTool::addTool', $display);
        self::assertStringContainsString('refuses the request', $display);
    }

    #[Test]
    public function skipsTheNotesWhenTheListenersCannotBeListed(): void
    {
        $listeners = new class (self::createStub(ContainerInterface::class)) extends ListenerProvider {
            /**
             * @return array<mixed>
             */
            public function getAllListenerDefinitions(): array
            {
                throw new RuntimeException('internal API changed', 4795390175);
            }
        };

        $command = new CompatibilityStatusCommand(
            new IntegrationRegistry(new AiFilemetadataIntegration(stdClass::class)),
            new StatusReporter(),
            $listeners,
        );
        $command->setName('nrllm:compat:status');

        $tester = new CommandTester($command);
        $tester->execute([]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        $display = $tester->getDisplay();
        // The status table stays; its state depends on the installed release.
        self::assertStringContainsString('ai_filemetadata', $display);
        self::assertStringContainsString('mfd/ai-filemetadata', $display);
        self::assertMatchesRegularExpression('/status\s+[A-Z]/', $display);
        self::assertStringContainsString('The registered event listeners could not be listed', $display);
        self::assertStringNotContainsString('[NOTE]', $display);
    }

    /**
     * Before 1.7.1 there are no listener notes to give, so a failing
     * listener list is not worth a line either.
     */
    #[Test]
    public function saysNothingAboutListenersWhenTheIntegrationReadsNone(): void
    {
        $listeners = new class (self::createStub(ContainerInterface::class)) extends ListenerProvider {
            /**
             * @return array<mixed>
             */
            public function getAllListenerDefinitions(): array
            {
                throw new RuntimeException('internal API changed', 1795800001);
            }
        };

        $command = new CompatibilityStatusCommand(
            new IntegrationRegistry(new AiFilemetadataIntegration('Netresearch\\NrLlmCompat\\Tests\\Unit\\Fixtures\\MissingRequestEvent')),
            new StatusReporter(),
            $listeners,
        );
        $command->setName('nrllm:compat:status');

        $tester = new CommandTester($command);
        $tester->execute([]);

        self::assertStringContainsString('ai_filemetadata', $tester->getDisplay());
        self::assertStringNotContainsString('could not be listed', $tester->getDisplay());
    }
}
