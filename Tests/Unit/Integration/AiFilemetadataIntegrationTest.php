<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlmCompat\Tests\Unit\Integration;

use Composer\Semver\Semver;
use Mfd\Ai\FileMetadata\Api\OpenAiClient;
use Mfd\Ai\FileMetadata\Event\ModifyRequestDataEvent;
use Mfd\Ai\FileMetadata\Services\TokenUsageService;
use Netresearch\NrLlmCompat\Bridge\AiFilemetadata\OpenAiClient as OpenAiClientBridge;
use Netresearch\NrLlmCompat\Integration\AiFilemetadataIntegration;
use Netresearch\NrLlmCompat\Integration\Diagnostics\ContractVerifier;
use Netresearch\NrLlmCompat\Integration\Diagnostics\VersionVerifier;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\LoggerInterface;
use stdClass;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\EventDispatcher\EventDispatcher;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

#[CoversClass(AiFilemetadataIntegration::class)]
final class AiFilemetadataIntegrationTest extends UnitTestCase
{
    /**
     * Stand-ins for the request event: a class that always exists, and one
     * that never does, so both cases run whichever release is installed.
     */
    private const PRESENT_EVENT_CLASS = stdClass::class;

    private const ABSENT_EVENT_CLASS = 'Netresearch\\NrLlmCompat\\Tests\\Unit\\Fixtures\\MissingRequestEvent';

    private AiFilemetadataIntegration $subject;

    protected function setUp(): void
    {
        parent::setUp();
        $this->subject = new AiFilemetadataIntegration();
    }

    #[Test]
    public function describesTheAiFilemetadataPackage(): void
    {
        self::assertSame('mfd/ai-filemetadata', $this->subject->getPackageName());
        self::assertSame('ai_filemetadata', $this->subject->getExtensionKey());
        self::assertSame(['vision'], $this->subject->getCapabilities());
        self::assertSame(
            [OpenAiClient::class => OpenAiClientBridge::class],
            $this->subject->getServiceReplacements(),
        );
    }

    /**
     * THE reference assertion of this integration: the contract declared in
     * the descriptor holds against the REAL, unmodified ai-filemetadata
     * package installed from Packagist into this test environment. This also
     * proves the readonly bridge still loads against the installed readonly
     * parent — a modifier change upstream surfaces here as a violation.
     */
    #[Test]
    public function declaredContractMatchesTheInstalledAiFilemetadataPackage(): void
    {
        self::assertSame([], (new ContractVerifier())->verify($this->subject));
    }

    /**
     * The bridge dispatches 1.7.1's ModifyRequestDataEvent and takes its
     * constructor change into account, so the range spans the verified
     * releases up to 1.6.6 (TER only for 1.6.5 and 1.6.6) and 1.7.1. Each
     * line ends at its verified release: the bridge rebuilds the request data
     * itself, upstream changed that contract in a patch release (1.7.1), and
     * each new release has to be diffed before the range is widened.
     */
    #[Test]
    public function supportedRangeIncludesTheConstructorChangeOf171(): void
    {
        $range = $this->subject->getSupportedVersions();

        self::assertTrue(Semver::satisfies('1.6.0', $range));
        self::assertTrue(Semver::satisfies('1.6.2', $range));
        self::assertTrue(Semver::satisfies('1.6.4', $range));
        self::assertTrue(Semver::satisfies('1.6.5', $range));
        self::assertTrue(Semver::satisfies('1.6.6', $range));
        self::assertFalse(Semver::satisfies('1.6.7', $range));
        self::assertFalse(Semver::satisfies('1.6.9', $range));
        self::assertTrue(Semver::satisfies('1.7.0', $range));
        self::assertTrue(Semver::satisfies('1.7.1', $range));
        self::assertFalse(Semver::satisfies('1.7.2', $range));
        self::assertFalse(Semver::satisfies('1.8.0', $range));
        self::assertFalse(Semver::satisfies('1.5.0', $range));
        self::assertFalse(Semver::satisfies('2.0.0', $range));
    }

    /**
     * @return iterable<string, array{string, list<string>, list<string>}>
     */
    public static function constructorAndEventContractsPerVersion(): iterable
    {
        $before171 = [ExtensionConfiguration::class, LoggerInterface::class, TokenUsageService::class];

        yield 'event class absent (up to 1.7.0)' => [self::ABSENT_EVENT_CLASS, $before171, []];
        yield 'event class present (1.7.1+)' => [self::PRESENT_EVENT_CLASS, [...$before171, EventDispatcher::class], ['__construct', 'getRequestData']];
    }

    /**
     * The constructor contract follows the presence of the request event:
     * three parameters without it (up to 1.7.0), the EventDispatcher as
     * fourth with it (1.7.1 on), where the event contracts join.
     *
     * @param list<string> $constructorParameters
     * @param list<string> $eventMethods
     */
    #[Test]
    #[DataProvider('constructorAndEventContractsPerVersion')]
    public function constructorAndEventContractsFollowTheVersion(string $requestEventClass, array $constructorParameters, array $eventMethods): void
    {
        $constructor = null;
        $events      = [];
        foreach ((new AiFilemetadataIntegration($requestEventClass))->getMethodContracts() as $contract) {
            if ($contract->className === OpenAiClient::class && $contract->methodName === '__construct') {
                $constructor = $contract;
            }

            if ($contract->className === ModifyRequestDataEvent::class) {
                $events[] = $contract->methodName;
            }
        }

        self::assertNotNull($constructor);
        self::assertSame($constructorParameters, $constructor->parameterTypes);
        self::assertSame($eventMethods, $events);
    }

    /**
     * By default the descriptor checks ModifyRequestDataEvent, the class the
     * bridge checks too.
     */
    #[Test]
    public function theInstalledEventClassDecidesByDefault(): void
    {
        self::assertSame(class_exists(ModifyRequestDataEvent::class), $this->subject->dispatchesRequestEvent());
        self::assertTrue((new AiFilemetadataIntegration(self::PRESENT_EVENT_CLASS))->dispatchesRequestEvent());
        self::assertFalse((new AiFilemetadataIntegration(self::ABSENT_EVENT_CLASS))->dispatchesRequestEvent());
    }

    #[Test]
    public function statusNotesNameTheRequestEventListenersFrom171(): void
    {
        $listeners = [
            ModifyRequestDataEvent::class => [
                'Vendor\\Site\\AddWebSearchTool' => ['service' => 'Vendor\\Site\\AddWebSearchTool', 'method' => 'addTool'],
                'Vendor\\Site\\Other'            => ['service' => 'Vendor\\Site\\Other', 'method' => null],
            ],
        ];

        $notes = (new AiFilemetadataIntegration(self::PRESENT_EVENT_CLASS))->getStatusNotes($listeners, true);

        self::assertCount(1, $notes);
        self::assertStringContainsString('2 ModifyRequestDataEvent listener(s) registered: Vendor\\Site\\AddWebSearchTool::addTool, Vendor\\Site\\Other.', $notes[0]);
        self::assertStringContainsString('other request keys) makes the bridge refuse the request.', $notes[0]);
        self::assertStringContainsString('A changed or removed temperature and a detail level of "auto" are accepted and have no effect.', $notes[0]);
        self::assertStringContainsString('backend alt-text suggestion fails', $notes[0]);

        $before = (new AiFilemetadataIntegration(self::PRESENT_EVENT_CLASS))->getStatusNotes($listeners, false);
        self::assertCount(1, $before);
        self::assertStringContainsString('makes the bridge refuse the request once the integration is enabled', $before[0]);
    }

    /**
     * @return iterable<string, array{array<mixed>}>
     */
    public static function unexpectedListenerDefinitions(): iterable
    {
        yield 'listener entry not an array' => [[ModifyRequestDataEvent::class => ['x' => 'Vendor\\Site\\Listener']]];
        yield 'listener without service' => [[ModifyRequestDataEvent::class => ['x' => ['method' => 'run']]]];
        yield 'event entry not an array' => [[ModifyRequestDataEvent::class => 'Vendor\\Site\\Listener']];
    }

    /**
     * @param array<mixed> $eventListeners
     */
    #[Test]
    #[DataProvider('unexpectedListenerDefinitions')]
    public function anUnexpectedListenerShapeYieldsANoteThatTheyCouldNotBeRead(array $eventListeners): void
    {
        $notes = (new AiFilemetadataIntegration(self::PRESENT_EVENT_CLASS))->getStatusNotes($eventListeners, true);

        self::assertCount(1, $notes);
        self::assertStringContainsString('could not be read', $notes[0]);
        self::assertStringContainsString('the temperature (no effect) or a detail level of "auto" (no effect)', $notes[0]);
    }

    #[Test]
    public function noStatusNoteWithoutListenersOrBefore171(): void
    {
        $listeners = [ModifyRequestDataEvent::class => ['x' => ['service' => 'Vendor\\Site\\AddWebSearchTool', 'method' => null]]];

        self::assertSame([], (new AiFilemetadataIntegration(self::PRESENT_EVENT_CLASS))->getStatusNotes([], true));
        self::assertSame([], (new AiFilemetadataIntegration(self::PRESENT_EVENT_CLASS))->getStatusNotes(['Other\\Event' => $listeners[ModifyRequestDataEvent::class]], true));
        self::assertSame([], (new AiFilemetadataIntegration(self::ABSENT_EVENT_CLASS))->getStatusNotes($listeners, true));
        self::assertFalse((new AiFilemetadataIntegration(self::ABSENT_EVENT_CLASS))->readsEventListeners());
    }

    /**
     * Only the ModifyRequestDataEvent entry is inspected; another event's
     * malformed entry is none of this integration's business.
     */
    #[Test]
    public function aMalformedEntryOfAnotherEventIsIgnored(): void
    {
        $notes = (new AiFilemetadataIntegration(self::PRESENT_EVENT_CLASS))->getStatusNotes(
            [
                'Vendor\\Site\\OtherEvent'    => 'not a list',
                0                             => ['service' => 'Vendor\\Site\\Listener'],
                ModifyRequestDataEvent::class => ['x' => ['service' => 'Vendor\\Site\\AddWebSearchTool', 'method' => null]],
            ],
            true,
        );

        self::assertCount(1, $notes);
        self::assertStringContainsString('1 ModifyRequestDataEvent listener(s) registered: Vendor\\Site\\AddWebSearchTool.', $notes[0]);
    }

    #[Test]
    public function notesThatTheConfiguredTemperatureHasNoEffect(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['ai_filemetadata']['temperature'] = '0.4';

        try {
            $active = (new AiFilemetadataIntegration(self::ABSENT_EVENT_CLASS))->getStatusNotes([], true);
            $before = (new AiFilemetadataIntegration(self::ABSENT_EVENT_CLASS))->getStatusNotes([], false);
        } finally {
            unset($GLOBALS['TYPO3_CONF_VARS']);
        }

        self::assertCount(1, $active);
        self::assertStringContainsString("ai_filemetadata's temperature setting has no effect", $active[0]);
        self::assertStringContainsString('netresearch/t3x-nr-llm#1474', $active[0]);
        self::assertStringContainsString('will have no effect', $before[0]);
        self::assertSame([], (new AiFilemetadataIntegration(self::ABSENT_EVENT_CLASS))->getStatusNotes([], true));
    }

    #[Test]
    public function installedAiFilemetadataVersionIsInsideTheSupportedRange(): void
    {
        $verifier = new VersionVerifier();

        self::assertTrue($verifier->isInstalled('mfd/ai-filemetadata'));
        self::assertTrue($verifier->satisfies('mfd/ai-filemetadata', $this->subject->getSupportedVersions()));
    }
}
