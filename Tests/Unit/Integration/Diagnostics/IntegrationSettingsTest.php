<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlmCompat\Tests\Unit\Integration\Diagnostics;

use Netresearch\NrLlmCompat\Integration\Diagnostics\IntegrationSettings;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

#[CoversClass(IntegrationSettings::class)]
final class IntegrationSettingsTest extends UnitTestCase
{
    private IntegrationSettings $subject;

    protected function setUp(): void
    {
        parent::setUp();
        $this->subject = new IntegrationSettings();
        unset($GLOBALS['TYPO3_CONF_VARS']);
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['TYPO3_CONF_VARS']);
        parent::tearDown();
    }

    #[Test]
    public function missingConfigurationMeansDisabled(): void
    {
        self::assertFalse($this->subject->isEnabled('ai_seo_helper'));
    }

    /**
     * @return array<string, array{string|int|bool, bool}>
     */
    public static function toggleValues(): array
    {
        return [
            'string one enables' => ['1', true],
            'int one enables' => [1, true],
            'true enables' => [true, true],
            'string zero disables' => ['0', false],
            'int zero disables' => [0, false],
            'false disables' => [false, false],
            'empty string disables' => ['', false],
        ];
    }

    #[Test]
    #[DataProvider('toggleValues')]
    public function toggleValueIsInterpreted(string|int|bool $value, bool $expected): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['nr_llm_compat']['integrations']['ai_seo_helper'] = $value;

        self::assertSame($expected, $this->subject->isEnabled('ai_seo_helper'));
    }

    #[Test]
    public function nonScalarValueMeansDisabled(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['nr_llm_compat']['integrations']['ai_seo_helper'] = ['nested' => '1'];

        self::assertFalse($this->subject->isEnabled('ai_seo_helper'));
    }

    #[Test]
    public function otherExtensionToggleDoesNotLeak(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['nr_llm_compat']['integrations']['other_ext'] = '1';

        self::assertFalse($this->subject->isEnabled('ai_seo_helper'));
    }
}
