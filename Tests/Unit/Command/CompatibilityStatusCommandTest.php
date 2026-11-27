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
use Netresearch\NrLlmCompat\Tests\Unit\Fixtures\ConfigurableNotesIntegration;
use Netresearch\NrLlmCompat\Tests\Unit\Fixtures\ConfigurableToolIntegration;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Psr\Container\ContainerInterface;
use RuntimeException;
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

    private function runAiFilemetadataStatus(ListenerProvider $listeners): CommandTester
    {
        $command = new CompatibilityStatusCommand(
            new IntegrationRegistry(new AiFilemetadataIntegration()),
            new StatusReporter(),
            $listeners,
        );
        $command->setName('nrllm:compat:status');

        $tester = new CommandTester($command);
        $tester->execute([]);

        return $tester;
    }

    private function requireRequestEvent(): void
    {
        if (!class_exists(ModifyRequestDataEvent::class)) {
            self::markTestSkipped('The installed ai_filemetadata predates ModifyRequestDataEvent (1.7.1).');
        }
    }

    #[Test]
    public function namesRegisteredRequestEventListenersOfAnActiveAiFilemetadataIntegration(): void
    {
        $this->requireRequestEvent();
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['nr_llm_compat']['integrations']['ai_filemetadata'] = '1';
        $listeners                                                                                    = new ListenerProvider(self::createStub(ContainerInterface::class));
        $listeners->addListener(ModifyRequestDataEvent::class, 'Vendor\\Site\\AddWebSearchTool', 'addTool');

        $tester = $this->runAiFilemetadataStatus($listeners);

        // SymfonyStyle wraps a note and prefixes each line with "! ".
        $display = preg_replace('/\s+(?:!\s+)?/', ' ', $tester->getDisplay()) ?? '';
        self::assertStringContainsString('ACTIVE', $display);
        self::assertStringContainsString('1 ModifyRequestDataEvent listener(s) registered: Vendor\\Site\\AddWebSearchTool::addTool', $display);
        self::assertStringContainsString('other request keys) makes the bridge refuse the request.', $display);
        self::assertStringContainsString('A changed or removed temperature and a detail level of "auto" are accepted and have no effect.', $display);
    }

    #[Test]
    public function warnsThatListenerChangesWouldBeRefusedBeforeTheIntegrationIsEnabled(): void
    {
        $this->requireRequestEvent();
        $listeners = new ListenerProvider(self::createStub(ContainerInterface::class));
        $listeners->addListener(ModifyRequestDataEvent::class, 'Vendor\\Site\\AddWebSearchTool', 'addTool');

        $tester = $this->runAiFilemetadataStatus($listeners);

        $display = preg_replace('/\s+(?:!\s+)?/', ' ', $tester->getDisplay()) ?? '';
        self::assertStringContainsString('AVAILABLE', $display);
        self::assertStringContainsString('Vendor\\Site\\AddWebSearchTool::addTool', $display);
        self::assertStringContainsString('makes the bridge refuse the request once the integration is enabled', $display);
    }

    #[Test]
    public function skipsTheNotesWhenTheListenersCannotBeListed(): void
    {
        $this->requireRequestEvent();
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['nr_llm_compat']['integrations']['ai_filemetadata'] = '1';

        $tester = $this->runAiFilemetadataStatus(self::throwingListenerProvider());

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        $display = $tester->getDisplay();
        self::assertStringContainsString('ai_filemetadata', $display);
        self::assertStringContainsString('ACTIVE', $display);
        self::assertStringContainsString('The registered event listeners could not be listed (internal API changed)', $display);
        self::assertStringNotContainsString('[NOTE]', $display);
    }

    private function throwingListenerProvider(): ListenerProvider
    {
        return new class (self::createStub(ContainerInterface::class)) extends ListenerProvider {
            /**
             * @return array<mixed>
             */
            public function getAllListenerDefinitions(): array
            {
                throw new RuntimeException('internal API changed', 1795800001);
            }
        };
    }

    /**
     * An active integration that reads no listeners never triggers the
     * listener lookup, so a failing lookup is not worth a line, and its
     * static notes are still printed.
     */
    #[Test]
    public function anIntegrationThatReadsNoListenersGetsItsStaticNotesWithoutALookup(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['nr_llm_compat']['integrations']['fixture_extension'] = '1';
        $integration                                                                                    = new ConfigurableNotesIntegration(self::INSTALLED_PACKAGE, readsEventListeners: false);

        $command = new CompatibilityStatusCommand(
            new IntegrationRegistry($integration),
            new StatusReporter(),
            $this->throwingListenerProvider(),
        );
        $command->setName('nrllm:compat:status');

        $tester = new CommandTester($command);
        $tester->execute([]);

        $display = $tester->getDisplay();
        self::assertStringContainsString('ACTIVE', $display);
        self::assertStringNotContainsString('could not be listed', $display);
        self::assertStringContainsString('A static note.', $display);
        self::assertSame([[]], $integration->receivedListeners);
    }

    /**
     * A failed lookup skips the listener notes, not the static ones.
     */
    #[Test]
    public function aFailedLookupStillPrintsTheStaticNotes(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['nr_llm_compat']['integrations']['fixture_extension'] = '1';
        $integration                                                                                    = new ConfigurableNotesIntegration(self::INSTALLED_PACKAGE, readsEventListeners: true);

        $command = new CompatibilityStatusCommand(new IntegrationRegistry($integration), new StatusReporter(), self::throwingListenerProvider());
        $command->setName('nrllm:compat:status');

        $tester = new CommandTester($command);
        $tester->execute([]);

        self::assertStringContainsString('could not be listed (internal API changed)', $tester->getDisplay());
        self::assertStringContainsString('A static note.', $tester->getDisplay());
        self::assertSame([[]], $integration->receivedListeners);
    }

    #[Test]
    public function anIntegrationThatReadsListenersGetsTheDefinitions(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['nr_llm_compat']['integrations']['fixture_extension'] = '1';
        $integration                                                                                    = new ConfigurableNotesIntegration(self::INSTALLED_PACKAGE, readsEventListeners: true);
        $listeners                                                                                      = new ListenerProvider(self::createStub(ContainerInterface::class));
        $listeners->addListener('Vendor\\Site\\SomeEvent', 'Vendor\\Site\\SomeListener');

        $command = new CompatibilityStatusCommand(new IntegrationRegistry($integration), new StatusReporter(), $listeners);
        $command->setName('nrllm:compat:status');

        $tester = new CommandTester($command);
        $tester->execute([]);

        self::assertStringContainsString('A static note.', $tester->getDisplay());
        self::assertSame([$listeners->getAllListenerDefinitions()], $integration->receivedListeners);
    }
}
