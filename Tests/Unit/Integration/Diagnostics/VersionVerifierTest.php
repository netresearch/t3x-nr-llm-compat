<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlmCompat\Tests\Unit\Integration\Diagnostics;

use Netresearch\NrLlmCompat\Integration\Diagnostics\VersionVerifier;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

#[CoversClass(VersionVerifier::class)]
final class VersionVerifierTest extends UnitTestCase
{
    private VersionVerifier $subject;

    protected function setUp(): void
    {
        parent::setUp();
        $this->subject = new VersionVerifier();
    }

    #[Test]
    public function reportsAnInstalledPackage(): void
    {
        self::assertTrue($this->subject->isInstalled('typo3/cms-core'));
        self::assertNotNull($this->subject->getInstalledVersion('typo3/cms-core'));
    }

    #[Test]
    public function reportsANotInstalledPackage(): void
    {
        self::assertFalse($this->subject->isInstalled('acme/definitely-not-installed'));
        self::assertNull($this->subject->getInstalledVersion('acme/definitely-not-installed'));
    }

    #[Test]
    public function satisfiesMatchesTheInstalledVersionAgainstAConstraint(): void
    {
        self::assertTrue($this->subject->satisfies('typo3/cms-core', '>=13.0'));
        self::assertFalse($this->subject->satisfies('typo3/cms-core', '<1.0'));
        self::assertFalse($this->subject->satisfies('acme/definitely-not-installed', '*'));
    }
}
