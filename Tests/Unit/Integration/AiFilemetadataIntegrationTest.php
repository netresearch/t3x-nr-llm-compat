<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlmCompat\Tests\Unit\Integration;

use Mfd\Ai\FileMetadata\Api\OpenAiClient;
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

    #[Test]
    public function installedAiFilemetadataVersionIsInsideTheSupportedRange(): void
    {
        $verifier = new VersionVerifier();

        self::assertTrue($verifier->isInstalled('mfd/ai-filemetadata'));
        self::assertTrue($verifier->satisfies('mfd/ai-filemetadata', $this->subject->getSupportedVersions()));
    }
}
