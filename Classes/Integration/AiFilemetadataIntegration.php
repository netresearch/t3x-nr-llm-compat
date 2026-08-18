<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlmCompat\Integration;

use Mfd\Ai\FileMetadata\Domain\Dto\TokenUsageResult;
use Mfd\Ai\FileMetadata\Services\TokenUsageService;
use Netresearch\NrLlmCompat\Bridge\AiFilemetadata\OpenAiClient as OpenAiClientBridge;
use Netresearch\NrLlmCompat\Integration\Contract\ClassContract;
use Netresearch\NrLlmCompat\Integration\Contract\MethodContract;
use Psr\Log\LoggerInterface;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;

/**
 * Integration for mfd/ai-filemetadata (extension key "ai_filemetadata") —
 * the first vision integration.
 *
 * The extension autoregisters its whole Classes/ tree as DI services;
 * `Api\OpenAiClient` is a readonly class that builds the openai-php client
 * in its constructor and exposes `buildAltText()` as the single provider
 * entry point. The bridge defines its OWN constructor and never calls the
 * parent's, so no OpenAI client is built and the extension's API key is
 * never read.
 *
 * The extension has its own token-usage statistics (dashboard widgets); the
 * bridge mirrors nr-llm's usage result into its `TokenUsageService` so those
 * statistics keep working — hence the tracker's contract below.
 *
 * Contracts mirror the verified 1.6.2 source.
 */
final class AiFilemetadataIntegration implements IntegrationInterface
{
    private const OPENAI_CLIENT = \Mfd\Ai\FileMetadata\Api\OpenAiClient::class;

    private const TOKEN_USAGE_SERVICE = TokenUsageService::class;

    private const TOKEN_USAGE_RESULT = TokenUsageResult::class;

    public function getPackageName(): string
    {
        return 'mfd/ai-filemetadata';
    }

    public function getExtensionKey(): string
    {
        return 'ai_filemetadata';
    }

    public function getSupportedVersions(): string
    {
        return '^1.6';
    }

    public function getStrategy(): IntegrationStrategy
    {
        return IntegrationStrategy::DiClassReplacement;
    }

    public function getCapabilities(): array
    {
        return ['vision'];
    }

    public function getServiceReplacements(): array
    {
        return [
            self::OPENAI_CLIENT => OpenAiClientBridge::class,
        ];
    }

    public function getClassContracts(): array
    {
        return [
            // The bridge is a readonly class; the installed parent must stay
            // readonly or the bridge would fatal at load time.
            new ClassContract(self::OPENAI_CLIENT, isReadonly: true),
        ];
    }

    public function getMethodContracts(): array
    {
        return [
            new MethodContract(
                self::OPENAI_CLIENT,
                'buildAltText',
                ['string', 'string', 'string', 'int'],
                'string',
            ),
            new MethodContract(
                self::OPENAI_CLIENT,
                '__construct',
                [ExtensionConfiguration::class, LoggerInterface::class, self::TOKEN_USAGE_SERVICE],
                null,
            ),
            new MethodContract(
                self::TOKEN_USAGE_SERVICE,
                'track',
                [self::TOKEN_USAGE_RESULT, 'string', 'int', 'string'],
                'void',
            ),
            new MethodContract(
                self::TOKEN_USAGE_RESULT,
                '__construct',
                ['int', 'int', 'int', 'string'],
                null,
            ),
        ];
    }

    public function getPropertyContracts(): array
    {
        return [];
    }
}
