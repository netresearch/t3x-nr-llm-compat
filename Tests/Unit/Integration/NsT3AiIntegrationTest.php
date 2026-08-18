<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlmCompat\Tests\Unit\Integration;

use Netresearch\NrLlmCompat\Bridge\NsT3Ai\NsT3AiContentService as ContentServiceBridge;
use Netresearch\NrLlmCompat\Integration\Diagnostics\ContractVerifier;
use Netresearch\NrLlmCompat\Integration\Diagnostics\VersionVerifier;
use Netresearch\NrLlmCompat\Integration\NsT3AiIntegration;
use NITSAN\NsT3Ai\Service\NsT3AiContentService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

#[CoversClass(NsT3AiIntegration::class)]
final class NsT3AiIntegrationTest extends UnitTestCase
{
    private NsT3AiIntegration $subject;

    protected function setUp(): void
    {
        parent::setUp();
        $this->subject = new NsT3AiIntegration();
    }

    #[Test]
    public function describesTheNsT3AiPackage(): void
    {
        self::assertSame('nitsan/ns-t3ai', $this->subject->getPackageName());
        self::assertSame('ns_t3ai', $this->subject->getExtensionKey());
        self::assertSame(['completion'], $this->subject->getCapabilities());
        self::assertSame(
            [NsT3AiContentService::class => ContentServiceBridge::class],
            $this->subject->getServiceReplacements(),
        );
    }

    /**
     * THE reference assertion of this integration: the contract declared in
     * the descriptor holds against the REAL, unmodified ns-t3ai package
     * installed from Packagist into this test environment.
     */
    #[Test]
    public function declaredContractMatchesTheInstalledNsT3AiPackage(): void
    {
        self::assertSame([], (new ContractVerifier())->verify($this->subject));
    }

    #[Test]
    public function installedNsT3AiVersionIsInsideTheSupportedRange(): void
    {
        $verifier = new VersionVerifier();

        self::assertTrue($verifier->isInstalled('nitsan/ns-t3ai'));
        self::assertTrue($verifier->satisfies('nitsan/ns-t3ai', $this->subject->getSupportedVersions()));
    }
}
