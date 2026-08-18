<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlmCompat\Integration;

use Netresearch\NrLlmCompat\Bridge\NsT3Ai\NsT3AiContentService as ContentServiceBridge;
use Netresearch\NrLlmCompat\Integration\Contract\ClassContract;
use Netresearch\NrLlmCompat\Integration\Contract\MethodContract;
use Netresearch\NrLlmCompat\Integration\Contract\PropertyContract;
use TYPO3\CMS\Core\Domain\Repository\PageRepository;
use TYPO3\CMS\Core\Http\RequestFactory;
use TYPO3\CMS\Core\Routing\SiteMatcher;
use TYPO3\CMS\Extbase\Mvc\Web\Routing\UriBuilder;

/**
 * Integration for nitsan/ns-t3ai (extension key "ns_t3ai").
 *
 * The extension registers `NsT3AiContentService` as an explicit DI service
 * and injects it into its AJAX controller. TWO methods contain OpenAI HTTP
 * calls and both are bridged: `requestAi()` (SEO suggestions) and
 * `requestAiForRteContent()` (the CKEditor 5 RTE content generator, which
 * posts through the backend route on TYPO3 12+). The extension's remaining
 * direct provider call sits in its CKEditor 4 browser plugin — dead code on
 * TYPO3 13, and inert as long as the extension's own API key stays empty.
 *
 * The prompt construction is NOT replicated: the bridge calls the original
 * protected `addModelSpecificPrompt()` helper, so the [Content]-placeholder
 * and prompt-override semantics stay upstream code. That helper is therefore
 * part of the verified contract (protected suffices).
 *
 * Contracts mirror the verified 14.0.0 source.
 */
final class NsT3AiIntegration implements IntegrationInterface
{
    private const CONTENT_SERVICE = \NITSAN\NsT3Ai\Service\NsT3AiContentService::class;

    public function getPackageName(): string
    {
        return 'nitsan/ns-t3ai';
    }

    public function getExtensionKey(): string
    {
        return 'ns_t3ai';
    }

    public function getSupportedVersions(): string
    {
        return '^14.0';
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

    public function getClassContracts(): array
    {
        return [
            new ClassContract(self::CONTENT_SERVICE, isReadonly: false),
        ];
    }

    public function getMethodContracts(): array
    {
        return [
            new MethodContract(
                self::CONTENT_SERVICE,
                'requestAi',
                ['string', null, null, null, null],
                'string',
            ),
            new MethodContract(
                self::CONTENT_SERVICE,
                'requestAiForRteContent',
                ['array'],
                'array',
            ),
            new MethodContract(
                self::CONTENT_SERVICE,
                'addModelSpecificPrompt',
                ['array', 'string', 'string', 'string', 'array'],
                null,
                mustBePublic: false,
            ),
            new MethodContract(
                self::CONTENT_SERVICE,
                '__construct',
                [PageRepository::class, SiteMatcher::class, RequestFactory::class, UriBuilder::class, 'bool', 'array', 'array'],
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
