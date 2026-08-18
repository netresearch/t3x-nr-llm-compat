<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlmCompat\Tests\Unit\Integration;

use Netresearch\NrLlmCompat\Bridge\AiSeoHelper\ContentService as ContentServiceBridge;
use Netresearch\NrLlmCompat\Integration\AiSeoHelperIntegration;
use Netresearch\NrLlmCompat\Integration\Diagnostics\ContractVerifier;
use Netresearch\NrLlmCompat\Integration\Diagnostics\VersionVerifier;
use Passionweb\AiSeoHelper\Service\ContentService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

#[CoversClass(AiSeoHelperIntegration::class)]
final class AiSeoHelperIntegrationTest extends UnitTestCase
{
    private AiSeoHelperIntegration $subject;

    protected function setUp(): void
    {
        parent::setUp();
        $this->subject = new AiSeoHelperIntegration();
    }

    #[Test]
    public function describesTheAiSeoHelperPackage(): void
    {
        self::assertSame('passionweb/ai-seo-helper', $this->subject->getPackageName());
        self::assertSame('ai_seo_helper', $this->subject->getExtensionKey());
        self::assertSame(['completion'], $this->subject->getCapabilities());
        self::assertSame(
            [ContentService::class => ContentServiceBridge::class],
            $this->subject->getServiceReplacements(),
        );
    }

    /**
     * THE reference assertion of this integration: the contract declared in
     * the descriptor holds against the REAL, unmodified ai-seo-helper package
     * installed from Packagist into this test environment. When an upstream
     * release changes the signature, this test is the first thing that turns
     * red.
     */
    #[Test]
    public function declaredContractMatchesTheInstalledAiSeoHelperPackage(): void
    {
        self::assertSame([], (new ContractVerifier())->verify($this->subject));
    }

    /**
     * Companion guard: the version installed by Composer resolution is inside
     * the range the integration claims to support. If Composer starts
     * resolving a newer minor/major, the supported range (and the contract
     * above) must be re-verified deliberately, not silently.
     */
    #[Test]
    public function installedAiSeoHelperVersionIsInsideTheSupportedRange(): void
    {
        $verifier = new VersionVerifier();

        self::assertTrue($verifier->isInstalled('passionweb/ai-seo-helper'));
        self::assertTrue($verifier->satisfies('passionweb/ai-seo-helper', $this->subject->getSupportedVersions()));
    }
}
