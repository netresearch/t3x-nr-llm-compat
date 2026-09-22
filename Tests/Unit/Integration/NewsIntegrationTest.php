<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlmCompat\Tests\Unit\Integration;

use Netresearch\NrLlm\Service\Tool\ToolInterface;
use Netresearch\NrLlmCompat\Bridge\News\CreateNewsDraftTool;
use Netresearch\NrLlmCompat\Integration\Diagnostics\ContractVerifier;
use Netresearch\NrLlmCompat\Integration\Diagnostics\VersionVerifier;
use Netresearch\NrLlmCompat\Integration\IntegrationStrategy;
use Netresearch\NrLlmCompat\Integration\NewsIntegration;
use Netresearch\NrLlmCompat\Integration\ProvidesRuntimeConfiguration;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

#[CoversClass(NewsIntegration::class)]
final class NewsIntegrationTest extends UnitTestCase
{
    private NewsIntegration $subject;

    protected function setUp(): void
    {
        parent::setUp();
        $this->subject = new NewsIntegration();
    }

    #[Test]
    public function describesTheNewsPackage(): void
    {
        self::assertSame('georgringer/news', $this->subject->getPackageName());
        self::assertSame('news', $this->subject->getExtensionKey());
        self::assertSame(IntegrationStrategy::ToolProvision, $this->subject->getStrategy());
        self::assertSame(['create_news_draft'], $this->subject->getCapabilities());
        self::assertSame(
            [ToolInterface::class => CreateNewsDraftTool::class],
            $this->subject->getServiceReplacements(),
        );
        // Nothing to configure at boot: the tool is registered by the
        // compiler pass, not by a hook.
        $implemented = class_implements($this->subject);
        self::assertIsArray($implemented);
        self::assertNotContains(ProvidesRuntimeConfiguration::class, $implemented);
    }

    /**
     * THE reference assertion of this integration: the contract declared in
     * the descriptor holds against the REAL, unmodified news package
     * installed from Packagist into this test environment.
     */
    #[Test]
    public function declaredContractMatchesTheInstalledNewsPackage(): void
    {
        self::assertSame([], (new ContractVerifier())->verify($this->subject));
    }

    #[Test]
    public function installedNewsVersionIsInsideTheSupportedRange(): void
    {
        $verifier = new VersionVerifier();

        self::assertTrue($verifier->isInstalled('georgringer/news'));
        self::assertTrue($verifier->satisfies('georgringer/news', $this->subject->getSupportedVersions()));
    }
}
