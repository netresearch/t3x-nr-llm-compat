<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlmCompat\Integration;

use In2code\Sitescore\Domain\Repository\Llm\AbstractRepository;
use In2code\Sitescore\Domain\Repository\Llm\LlmRepositoryFactory;
use In2code\Sitescore\Domain\Repository\Llm\RepositoryInterface;
use Netresearch\NrLlmCompat\Bridge\Sitescore\NrLlmRepository;
use Netresearch\NrLlmCompat\Integration\Contract\ClassContract;
use Netresearch\NrLlmCompat\Integration\Contract\MethodContract;
use TYPO3\CMS\Core\Http\RequestFactory;

/**
 * Integration for in2code/sitescore (extension key "sitescore"), the page
 * module's SEO/GEO, keyword and accessibility score.
 *
 * sitescore officially supports custom LLM repositories, by the same pattern
 * as texter: its LlmRepositoryFactory reads
 * $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['sitescore']['llmRepositoryClass'],
 * requires the class to implement its RepositoryInterface, and resolves it
 * from the container; Services.yaml makes that factory the provider of the
 * RepositoryInterface that AnalysisService receives. Nothing is overridden —
 * the runtime configuration points the hook at the bridge, which the compiler
 * pass registers as a public service.
 *
 * The bridge extends AbstractRepository to keep the ORIGINAL prompt
 * (protected getPrompt(), part of the verified contract).
 *
 * Contracts mirror the verified 3.1.0 source.
 */
final class SitescoreIntegration implements IntegrationInterface, ProvidesRuntimeConfiguration
{
    private const REPOSITORY_INTERFACE = RepositoryInterface::class;

    private const ABSTRACT_REPOSITORY = AbstractRepository::class;

    private const REPOSITORY_FACTORY = LlmRepositoryFactory::class;

    public function getPackageName(): string
    {
        return 'in2code/sitescore';
    }

    public function getExtensionKey(): string
    {
        return 'sitescore';
    }

    public function getSupportedVersions(): string
    {
        return '^3.1';
    }

    public function getStrategy(): IntegrationStrategy
    {
        return IntegrationStrategy::ProviderConfiguration;
    }

    public function getCapabilities(): array
    {
        return ['completion'];
    }

    public function getServiceReplacements(): array
    {
        // For this strategy the key is the contract type the bridge must
        // satisfy; the value is registered as a public service so sitescore's
        // factory can resolve it from the container.
        return [
            self::REPOSITORY_INTERFACE => NrLlmRepository::class,
        ];
    }

    public function getClassContracts(): array
    {
        return [
            // The bridge EXTENDS AbstractRepository (for the original
            // getPrompt), so its modifiers are load-bearing.
            new ClassContract(self::ABSTRACT_REPOSITORY, isReadonly: false),
        ];
    }

    public function getMethodContracts(): array
    {
        return [
            // The interface the factory checks with is_a() and AnalysisService
            // calls.
            new MethodContract(self::REPOSITORY_INTERFACE, 'checkApiKey', [], 'void'),
            new MethodContract(self::REPOSITORY_INTERFACE, 'getApiUrl', [], 'string'),
            new MethodContract(self::REPOSITORY_INTERFACE, 'getOptions', ['string', 'string', 'string'], 'array'),
            new MethodContract(self::REPOSITORY_INTERFACE, 'analyzePageContent', ['string', 'string', 'string'], 'array'),
            // The reading side of the hook: the factory must keep resolving
            // the configured class.
            new MethodContract(self::REPOSITORY_FACTORY, 'create', [], self::REPOSITORY_INTERFACE),
            // What the bridge inherits and calls.
            new MethodContract(self::ABSTRACT_REPOSITORY, '__construct', [RequestFactory::class], null),
            new MethodContract(self::ABSTRACT_REPOSITORY, 'getPrompt', ['string', 'string', 'string'], 'string', mustBePublic: false),
        ];
    }

    public function getPropertyContracts(): array
    {
        return [];
    }

    public function applyRuntimeConfiguration(): void
    {
        $confVars = $GLOBALS['TYPO3_CONF_VARS'] ?? null;
        $confVars = is_array($confVars) ? $confVars : [];

        $extensions = is_array($confVars['EXTENSIONS'] ?? null) ? $confVars['EXTENSIONS'] : [];
        $sitescore  = is_array($extensions['sitescore'] ?? null) ? $extensions['sitescore'] : [];

        $sitescore['llmRepositoryClass'] = NrLlmRepository::class;
        $extensions['sitescore']         = $sitescore;
        $confVars['EXTENSIONS']          = $extensions;
        $GLOBALS['TYPO3_CONF_VARS']      = $confVars;
    }
}
