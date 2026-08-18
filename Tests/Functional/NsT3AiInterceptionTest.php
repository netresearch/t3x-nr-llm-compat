<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlmCompat\Tests\Functional;

use Netresearch\NrLlm\Domain\Model\CompletionResponse;
use Netresearch\NrLlm\Domain\Model\UsageStatistics;
use Netresearch\NrLlm\Service\Feature\CompletionServiceInterface;
use Netresearch\NrLlm\Testing\FakeCompletionService;
use Netresearch\NrLlmCompat\Bridge\NsT3Ai\NsT3AiContentService as ContentServiceBridge;
use PHPUnit\Framework\Attributes\Test;

/**
 * The integration enabled: ns_t3ai's AJAX controller receives the bridge
 * under the ORIGINAL service id, and both provider methods run through
 * nr-llm — with the extension's own OpenAI API key empty.
 */
final class NsT3AiInterceptionTest extends AbstractNsT3AiTestCase
{
    protected array $configurationToUseInTestInstance = [
        'EXTENSIONS' => [
            'ns_t3ai' => self::NS_T3AI_CONFIGURATION,
            'nr_llm_compat' => [
                'integrations' => [
                    'ns_t3ai' => '1',
                ],
            ],
        ],
    ];

    #[Test]
    public function controllerReceivesTheBridgeUnderTheOriginalServiceId(): void
    {
        self::assertInstanceOf(ContentServiceBridge::class, $this->contentServiceInjectedIntoController());
    }

    #[Test]
    public function requestAiRunsThroughNrLlmWithEmptyOpenAiKey(): void
    {
        $fake = $this->get(CompletionServiceInterface::class);
        self::assertInstanceOf(FakeCompletionService::class, $fake);
        $fake->responses[] = new CompletionResponse('Generated title', 'test-model', new UsageStatistics(1, 1, 2));

        $service = $this->contentServiceInjectedIntoController();

        $result = $service->requestAi('Some page content', 'openAiPromptPrefixPageTitle', '', 'en');

        self::assertSame('Generated title', $result);
        self::assertCount(1, $fake->completeCalls);
        self::assertSame(
            'Act as an SEO expert and write five an optimized title tag for a web page about Some page content in English',
            $fake->completeCalls[0]['prompt'],
        );
    }

    #[Test]
    public function rteContentGenerationRunsThroughNrLlm(): void
    {
        $fake = $this->get(CompletionServiceInterface::class);
        self::assertInstanceOf(FakeCompletionService::class, $fake);
        $fake->responses[] = new CompletionResponse('Generated paragraph', 'test-model', new UsageStatistics(1, 1, 2));

        $service = $this->contentServiceInjectedIntoController();

        $result = $service->requestAiForRteContent([
            'prompt' => 'Write about TYPO3',
            'max_tokens' => 400,
            'model' => 'gpt-4o',
            'temperature' => 0.5,
            'top_p' => 1,
            'n' => 1,
            'frequency_penalty' => 0,
            'presence_penalty' => 0,
        ]);

        self::assertSame(['choices' => [['message' => ['content' => 'Generated paragraph']]]], $result);
        self::assertCount(1, $fake->completeCalls);
    }
}
