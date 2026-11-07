<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlmCompat\Tests\Unit\Integration;

use In2code\Sitescore\Domain\Repository\Llm\RepositoryInterface;
use Netresearch\NrLlmCompat\Bridge\Sitescore\NrLlmRepository;
use Netresearch\NrLlmCompat\Integration\Diagnostics\ContractVerifier;
use Netresearch\NrLlmCompat\Integration\Diagnostics\VersionVerifier;
use Netresearch\NrLlmCompat\Integration\IntegrationStrategy;
use Netresearch\NrLlmCompat\Integration\SitescoreIntegration;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

#[CoversClass(SitescoreIntegration::class)]
final class SitescoreIntegrationTest extends UnitTestCase
{
    private SitescoreIntegration $subject;

    protected function setUp(): void
    {
        parent::setUp();
        $this->subject = new SitescoreIntegration();
        unset($GLOBALS['TYPO3_CONF_VARS']);
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['TYPO3_CONF_VARS']);
        parent::tearDown();
    }

    #[Test]
    public function describesTheSitescorePackage(): void
    {
        self::assertSame('in2code/sitescore', $this->subject->getPackageName());
        self::assertSame('sitescore', $this->subject->getExtensionKey());
        self::assertSame(IntegrationStrategy::ProviderConfiguration, $this->subject->getStrategy());
        self::assertSame(['completion'], $this->subject->getCapabilities());
        self::assertSame(
            [RepositoryInterface::class => NrLlmRepository::class],
            $this->subject->getServiceReplacements(),
        );
    }

    /**
     * THE reference assertion of this integration: the contract declared in
     * the descriptor holds against the REAL, unmodified sitescore package
     * installed from Packagist into this test environment.
     */
    #[Test]
    public function declaredContractMatchesTheInstalledSitescorePackage(): void
    {
        self::assertSame([], (new ContractVerifier())->verify($this->subject));
    }

    #[Test]
    public function installedSitescoreVersionIsInsideTheSupportedRange(): void
    {
        $verifier = new VersionVerifier();

        self::assertTrue($verifier->isInstalled('in2code/sitescore'));
        self::assertTrue($verifier->satisfies('in2code/sitescore', $this->subject->getSupportedVersions()));
    }

    #[Test]
    public function runtimeConfigurationPointsSitescoreAtTheBridge(): void
    {
        $this->subject->applyRuntimeConfiguration();

        self::assertSame(
            NrLlmRepository::class,
            $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['sitescore']['llmRepositoryClass'],
        );
    }

    #[Test]
    public function runtimeConfigurationKeepsSitescoresOtherSettings(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['sitescore'] = ['geminiModel' => 'gemini-test'];

        $this->subject->applyRuntimeConfiguration();

        self::assertSame('gemini-test', $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['sitescore']['geminiModel']);
    }
}
