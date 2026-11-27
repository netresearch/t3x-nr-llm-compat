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
use Netresearch\NrLlmCompat\Bridge\AiFilemetadata\OpenAiClient as OpenAiClientBridge;
use Netresearch\NrLlmCompat\Integration\AiFilemetadataIntegration;
use Netresearch\NrLlmCompat\Integration\Diagnostics\ContractVerifier;
use Netresearch\NrLlmCompat\Integration\Diagnostics\VersionVerifier;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

#[CoversClass(AiFilemetadataIntegration::class)]
final class AiFilemetadataIntegrationTest extends UnitTestCase
{
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
     * constructor change into account, so the range spans 1.6 and 1.7.1+.
     */
    #[Test]
    public function supportedRangeIncludesTheConstructorChangeOf171(): void
    {
        $range = $this->subject->getSupportedVersions();

        self::assertTrue(Semver::satisfies('1.6.2', $range));
        self::assertTrue(Semver::satisfies('1.7.0', $range));
        self::assertTrue(Semver::satisfies('1.7.1', $range));
        self::assertTrue(Semver::satisfies('1.8.0', $range));
        self::assertFalse(Semver::satisfies('1.5.0', $range));
        self::assertFalse(Semver::satisfies('2.0.0', $range));
    }

    /**
     * The constructor contract follows the installed version: three
     * parameters up to 1.7.0, the EventDispatcher as fourth from 1.7.1 on,
     * where the event contracts join.
     */
    #[Test]
    public function constructorAndEventContractsFollowTheInstalledVersion(): void
    {
        $dispatchesRequestEvent = (new VersionVerifier())->satisfies('mfd/ai-filemetadata', '>=1.7.1');

        $constructor  = null;
        $eventClasses = [];
        foreach ($this->subject->getMethodContracts() as $contract) {
            if ($contract->className === OpenAiClient::class && $contract->methodName === '__construct') {
                $constructor = $contract;
            }

            if ($contract->className === ModifyRequestDataEvent::class) {
                $eventClasses[] = $contract->methodName;
            }
        }

        self::assertNotNull($constructor);
        self::assertCount($dispatchesRequestEvent ? 4 : 3, $constructor->parameterTypes);
        self::assertSame($dispatchesRequestEvent ? ['__construct', 'getRequestData'] : [], $eventClasses);
    }

    #[Test]
    public function installedAiFilemetadataVersionIsInsideTheSupportedRange(): void
    {
        $verifier = new VersionVerifier();

        self::assertTrue($verifier->isInstalled('mfd/ai-filemetadata'));
        self::assertTrue($verifier->satisfies('mfd/ai-filemetadata', $this->subject->getSupportedVersions()));
    }
}
