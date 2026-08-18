<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlmCompat\Integration;

use Netresearch\NrLlmCompat\Bridge\AiSeoHelper\ContentService as ContentServiceBridge;
use Netresearch\NrLlmCompat\Integration\Contract\MethodContract;
use Netresearch\NrLlmCompat\Integration\Contract\PropertyContract;
use TYPO3\CMS\Core\Domain\Repository\PageRepository;
use TYPO3\CMS\Core\Http\RequestFactory;
use TYPO3\CMS\Core\Routing\SiteMatcher;

/**
 * Integration for passionweb/ai-seo-helper (extension key "ai_seo_helper").
 *
 * The extension registers `ContentService` as an explicit DI service and
 * injects it into its AJAX controller; `ContentService::requestAi()` is the
 * single method containing the OpenAI HTTP call. The bridge subclasses
 * `ContentService` and overrides only `requestAi()` — content extraction,
 * language detection, prompt-prefix resolution and response rendering stay
 * original code.
 *
 * The contracts below mirror the verified 0.7.2 source; a mismatch on the
 * installed version deactivates the integration.
 */
final class AiSeoHelperIntegration implements IntegrationInterface
{
    private const CONTENT_SERVICE = \Passionweb\AiSeoHelper\Service\ContentService::class;

    public function getPackageName(): string
    {
        return 'passionweb/ai-seo-helper';
    }

    public function getExtensionKey(): string
    {
        return 'ai_seo_helper';
    }

    public function getSupportedVersions(): string
    {
        return '~0.7.2';
    }

    public function getStrategy(): IntegrationStrategy
    {
        return IntegrationStrategy::DiClassReplacement;
    }

    public function getCapabilities(): array
    {
        return ['completion'];
    }

    public function getServiceReplacements(): array
    {
        return [
            self::CONTENT_SERVICE => ContentServiceBridge::class,
        ];
    }

    public function getMethodContracts(): array
    {
        return [
            new MethodContract(
                self::CONTENT_SERVICE,
                'requestAi',
                ['string', null, null, null],
                'array',
            ),
            new MethodContract(
                self::CONTENT_SERVICE,
                '__construct',
                [PageRepository::class, SiteMatcher::class, RequestFactory::class, 'array', 'array'],
                null,
            ),
        ];
    }

    public function getPropertyContracts(): array
    {
        return [
            new PropertyContract(self::CONTENT_SERVICE, 'languages'),
            new PropertyContract(self::CONTENT_SERVICE, 'extConf'),
        ];
    }
}
