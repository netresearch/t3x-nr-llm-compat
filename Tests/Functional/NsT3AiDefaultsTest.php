<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlmCompat\Tests\Functional;

use Netresearch\NrLlmCompat\Bridge\NsT3Ai\NsT3AiContentService as ContentServiceBridge;
use NITSAN\NsT3Ai\Service\NsT3AiContentService;
use PHPUnit\Framework\Attributes\Test;

/**
 * Default state: nr_llm_compat installed but the integration NOT enabled —
 * nothing is intercepted, ns_t3ai keeps its own NsT3AiContentService.
 */
final class NsT3AiDefaultsTest extends AbstractNsT3AiTestCase
{
    protected array $configurationToUseInTestInstance = [
        'EXTENSIONS' => [
            'ns_t3ai' => self::NS_T3AI_CONFIGURATION,
        ],
    ];

    #[Test]
    public function controllerKeepsTheOriginalContentService(): void
    {
        $service = $this->contentServiceInjectedIntoController();

        self::assertNotInstanceOf(ContentServiceBridge::class, $service);
        self::assertSame(NsT3AiContentService::class, $service::class);
    }
}
