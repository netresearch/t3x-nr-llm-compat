<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlmCompat\Tests\Functional;

use Netresearch\NrLlm\Domain\Model\UsageStatistics;
use Netresearch\NrLlm\Domain\Model\VisionResponse;
use Netresearch\NrLlm\Service\Feature\VisionServiceInterface;
use Netresearch\NrLlm\Testing\FakeVisionService;
use Netresearch\NrLlmCompat\Bridge\AiFilemetadata\OpenAiClient as OpenAiClientBridge;
use PHPUnit\Framework\Attributes\Test;

/**
 * The integration enabled: ai_filemetadata's consumers receive the vision
 * bridge under the ORIGINAL service id, and buildAltText() runs through
 * nr-llm — with the extension's own OpenAI API key empty.
 */
final class AiFilemetadataInterceptionTest extends AbstractAiFilemetadataTestCase
{
    protected array $configurationToUseInTestInstance = [
        'EXTENSIONS' => [
            'ai_filemetadata' => self::AI_FILEMETADATA_CONFIGURATION,
            'nr_llm_compat' => [
                'integrations' => [
                    'ai_filemetadata' => '1',
                ],
            ],
        ],
    ];

    #[Test]
    public function containerWiresTheBridgeUnderTheOriginalServiceId(): void
    {
        self::assertInstanceOf(OpenAiClientBridge::class, $this->wiredOpenAiClient());
    }

    #[Test]
    public function buildAltTextRunsThroughNrLlmWithEmptyOpenAiKey(): void
    {
        $fake = $this->get(VisionServiceInterface::class);
        self::assertInstanceOf(FakeVisionService::class, $fake);
        $fake->analyzeImageFullResult = new VisionResponse(
            'A red bicycle leaning on a wall.',
            'served-model',
            new UsageStatistics(120, 30, 150),
        );

        $altText = $this->wiredOpenAiClient()->buildAltText('raw-image-bytes', 'de_DE');

        self::assertSame('A red bicycle leaning on a wall.', $altText);
        self::assertCount(1, $fake->analyzeImageFullCalls);
        $call = $fake->analyzeImageFullCalls[0];
        self::assertSame('data:image/jpeg;base64,' . base64_encode('raw-image-bytes'), $call['imageUrl']);
        self::assertStringContainsString('Answer in German.', $call['prompt']);
    }
}
