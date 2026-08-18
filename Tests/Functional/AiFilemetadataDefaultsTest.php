<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlmCompat\Tests\Functional;

use Mfd\Ai\FileMetadata\Api\OpenAiClient;
use Netresearch\NrLlmCompat\Bridge\AiFilemetadata\OpenAiClient as OpenAiClientBridge;
use PHPUnit\Framework\Attributes\Test;

/**
 * Default state: nr_llm_compat installed but the integration NOT enabled —
 * nothing is intercepted, ai_filemetadata keeps its own OpenAiClient
 * (which constructs the openai-php client from its own configuration).
 */
final class AiFilemetadataDefaultsTest extends AbstractAiFilemetadataTestCase
{
    protected array $configurationToUseInTestInstance = [
        'EXTENSIONS' => [
            'ai_filemetadata' => self::AI_FILEMETADATA_CONFIGURATION,
        ],
    ];

    #[Test]
    public function containerKeepsTheOriginalOpenAiClient(): void
    {
        $client = $this->wiredOpenAiClient();

        self::assertNotInstanceOf(OpenAiClientBridge::class, $client);
        self::assertSame(OpenAiClient::class, $client::class);
    }
}
