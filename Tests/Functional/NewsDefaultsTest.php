<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlmCompat\Tests\Functional;

use PHPUnit\Framework\Attributes\Test;

/**
 * Default state: nr_llm_compat and news installed but the integration NOT
 * enabled — no tool is registered, and nr-llm's own tools are untouched.
 */
final class NewsDefaultsTest extends AbstractNewsTestCase
{
    protected array $configurationToUseInTestInstance = [
        'EXTENSIONS' => [
            'news' => self::NEWS_CONFIGURATION,
        ],
    ];

    #[Test]
    public function noNewsToolIsRegisteredWhileNrLlmsOwnToolsAre(): void
    {
        self::assertNull($this->toolFromRegistry('create_news_draft'));
        // The registry answers for the container it was built from: a null
        // above proves absence only because a known builtin is present.
        self::assertNotNull($this->toolFromRegistry('create_page_draft'));
    }
}
