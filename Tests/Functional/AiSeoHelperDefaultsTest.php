<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlmCompat\Tests\Functional;

use Netresearch\NrLlmCompat\Bridge\AiSeoHelper\ContentService as ContentServiceBridge;
use Passionweb\AiSeoHelper\Service\ContentService;
use PHPUnit\Framework\Attributes\Test;

/**
 * Default state: nr_llm_compat installed but the integration NOT enabled —
 * nothing is intercepted, ai_seo_helper keeps its own ContentService.
 */
final class AiSeoHelperDefaultsTest extends AbstractAiSeoHelperTestCase
{
    protected array $configurationToUseInTestInstance = [
        'EXTENSIONS' => [
            'ai_seo_helper' => self::AI_SEO_HELPER_CONFIGURATION,
        ],
    ];

    #[Test]
    public function aiControllerKeepsTheOriginalContentService(): void
    {
        $service = $this->contentServiceInjectedIntoAiController();

        self::assertNotInstanceOf(ContentServiceBridge::class, $service);
        self::assertSame(ContentService::class, $service::class);
    }
}
