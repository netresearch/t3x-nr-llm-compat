<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlmCompat\Tests\Unit\Integration;

use In2code\Texter\Domain\Repository\Llm\RepositoryInterface;
use Netresearch\NrLlmCompat\Bridge\Texter\NrLlmRepository;
use Netresearch\NrLlmCompat\Integration\Diagnostics\ContractVerifier;
use Netresearch\NrLlmCompat\Integration\Diagnostics\VersionVerifier;
use Netresearch\NrLlmCompat\Integration\IntegrationStrategy;
use Netresearch\NrLlmCompat\Integration\TexterIntegration;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

#[CoversClass(TexterIntegration::class)]
final class TexterIntegrationTest extends UnitTestCase
{
    private TexterIntegration $subject;

    protected function setUp(): void
    {
        parent::setUp();
        $this->subject = new TexterIntegration();
        unset($GLOBALS['TYPO3_CONF_VARS']);
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['TYPO3_CONF_VARS']);
        parent::tearDown();
    }

    #[Test]
    public function describesTheTexterPackage(): void
    {
        self::assertSame('in2code/texter', $this->subject->getPackageName());
        self::assertSame('texter', $this->subject->getExtensionKey());
        self::assertSame(IntegrationStrategy::ProviderConfiguration, $this->subject->getStrategy());
        self::assertSame(['completion'], $this->subject->getCapabilities());
        self::assertSame(
            [RepositoryInterface::class => NrLlmRepository::class],
            $this->subject->getServiceReplacements(),
        );
    }

    /**
     * THE reference assertion of this integration: the contract declared in
     * the descriptor holds against the REAL, unmodified texter package
     * installed from Packagist into this test environment.
     */
    #[Test]
    public function declaredContractMatchesTheInstalledTexterPackage(): void
    {
        self::assertSame([], (new ContractVerifier())->verify($this->subject));
    }

    #[Test]
    public function installedTexterVersionIsInsideTheSupportedRange(): void
    {
        $verifier = new VersionVerifier();

        self::assertTrue($verifier->isInstalled('in2code/texter'));
        self::assertTrue($verifier->satisfies('in2code/texter', $this->subject->getSupportedVersions()));
    }

    #[Test]
    public function runtimeConfigurationPointsTexterAtTheBridge(): void
    {
        $this->subject->applyRuntimeConfiguration();

        self::assertSame(
            NrLlmRepository::class,
            $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['texter']['llmRepositoryClass'],
        );
    }
}
