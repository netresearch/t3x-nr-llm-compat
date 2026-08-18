<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlmCompat\Tests\Functional;

use Netresearch\NrLlm\Service\Feature\CompletionServiceInterface;
use Netresearch\NrLlm\Testing\FakeCompletionService;
use Netresearch\NrLlmCompat\Bridge\AiSeoHelper\ContentService as ContentServiceBridge;
use PHPUnit\Framework\Attributes\Test;

/**
 * The integration enabled: ai_seo_helper's AJAX controller receives the
 * bridge under the ORIGINAL service id, and requestAi() runs through nr-llm
 * — with the extension's own OpenAI API key empty.
 */
final class AiSeoHelperInterceptionTest extends AbstractAiSeoHelperTestCase
{
    protected array $configurationToUseInTestInstance = [
        'EXTENSIONS' => [
            'ai_seo_helper' => self::AI_SEO_HELPER_CONFIGURATION,
            'nr_llm_compat' => [
                'integrations' => [
                    'ai_seo_helper' => '1',
                ],
            ],
        ],
    ];

    #[Test]
    public function aiControllerReceivesTheBridgeUnderTheOriginalServiceId(): void
    {
        self::assertInstanceOf(ContentServiceBridge::class, $this->contentServiceInjectedIntoAiController());
    }

    #[Test]
    public function requestAiRunsThroughNrLlmWithEmptyOpenAiKey(): void
    {
        $fake = $this->get(CompletionServiceInterface::class);
        self::assertInstanceOf(FakeCompletionService::class, $fake);
        $fake->jsonResult = ['suggestions' => ['Title one', 'Title two', 'Title three', 'Title four', 'Title five']];

        $service = $this->contentServiceInjectedIntoAiController();

        $result = $service->requestAi('Some page content', 'openAiPromptPrefixPageTitle', '', 'en');

        self::assertSame(['Title one', 'Title two', 'Title three', 'Title four', 'Title five'], $result);
        self::assertCount(1, $fake->completeJsonCalls);
        self::assertStringContainsString(
            'Suggest page title ideas in bullet point list for in English',
            $fake->completeJsonCalls[0]['prompt'],
        );
        self::assertStringContainsString('Some page content', $fake->completeJsonCalls[0]['prompt']);
    }
}
