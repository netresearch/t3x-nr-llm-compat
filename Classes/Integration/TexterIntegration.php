<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlmCompat\Integration;

use In2code\Texter\Domain\Repository\Llm\AbstractRepository;
use In2code\Texter\Domain\Repository\Llm\LlmRepositoryFactory;
use In2code\Texter\Domain\Repository\Llm\RepositoryInterface;
use In2code\Texter\Domain\Service\ConversationHistory;
use Netresearch\NrLlmCompat\Bridge\Texter\NrLlmRepository;
use Netresearch\NrLlmCompat\Integration\Contract\ClassContract;
use Netresearch\NrLlmCompat\Integration\Contract\MethodContract;
use Netresearch\NrLlmCompat\Integration\Contract\PropertyContract;
use TYPO3\CMS\Core\Http\RequestFactory;

/**
 * Integration for in2code/texter (extension key "texter") — the first
 * provider-configuration integration.
 *
 * texter officially supports custom LLM repositories: its
 * LlmRepositoryFactory reads
 * $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['texter']['llmRepositoryClass'],
 * requires the class to implement its RepositoryInterface, and resolves it
 * from the container. Nothing is overridden — the runtime configuration
 * points that hook at the bridge, which the compiler pass registers as a
 * public service.
 *
 * The bridge extends AbstractRepository to keep the ORIGINAL promptPrefix
 * handling (protected extendPrompt(), part of the verified contract) and
 * reuses the extension's ConversationHistory service, so per-page follow-up
 * context keeps working.
 *
 * Contracts mirror the verified 3.0.0 source.
 */
final class TexterIntegration implements IntegrationInterface, ProvidesRuntimeConfiguration
{
    private const REPOSITORY_INTERFACE = RepositoryInterface::class;

    private const ABSTRACT_REPOSITORY = AbstractRepository::class;

    private const REPOSITORY_FACTORY = LlmRepositoryFactory::class;

    private const CONVERSATION_HISTORY = ConversationHistory::class;

    public function getPackageName(): string
    {
        return 'in2code/texter';
    }

    public function getExtensionKey(): string
    {
        return 'texter';
    }

    public function getSupportedVersions(): string
    {
        return '^3.0';
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
        // satisfy; the value is registered as a public service so texter's
        // factory can resolve it from the container.
        return [
            self::REPOSITORY_INTERFACE => NrLlmRepository::class,
        ];
    }

    public function getClassContracts(): array
    {
        return [
            // The bridge EXTENDS AbstractRepository (for the original
            // extendPrompt semantics), so its modifiers are load-bearing.
            new ClassContract(self::ABSTRACT_REPOSITORY, isReadonly: false),
        ];
    }

    public function getMethodContracts(): array
    {
        return [
            new MethodContract(self::REPOSITORY_INTERFACE, 'getText', ['string', 'string'], 'string'),
            new MethodContract(self::REPOSITORY_INTERFACE, 'checkApiKey', [], 'void'),
            new MethodContract(self::REPOSITORY_INTERFACE, 'getApiUrl', [], 'string'),
            // The reading side of the hook: the factory must keep resolving
            // the configured class.
            new MethodContract(self::REPOSITORY_FACTORY, 'create', [], self::REPOSITORY_INTERFACE),
            // What the bridge inherits and calls.
            new MethodContract(self::ABSTRACT_REPOSITORY, '__construct', [RequestFactory::class, self::CONVERSATION_HISTORY], null),
            new MethodContract(self::ABSTRACT_REPOSITORY, 'extendPrompt', ['string'], 'string', mustBePublic: false),
            // The conversation-history lifecycle the bridge reuses.
            new MethodContract(self::CONVERSATION_HISTORY, 'getHistory', ['string'], 'array'),
            new MethodContract(self::CONVERSATION_HISTORY, 'addUserMessage', ['array', 'string'], 'void'),
            new MethodContract(self::CONVERSATION_HISTORY, 'addModelResponse', ['array', 'string'], 'void'),
            new MethodContract(self::CONVERSATION_HISTORY, 'saveHistory', ['array', 'string'], 'void'),
        ];
    }

    public function getPropertyContracts(): array
    {
        return [
            new PropertyContract(self::ABSTRACT_REPOSITORY, 'conversationHistory'),
        ];
    }

    public function applyRuntimeConfiguration(): void
    {
        $confVars = $GLOBALS['TYPO3_CONF_VARS'] ?? null;
        $confVars = is_array($confVars) ? $confVars : [];

        $extensions = is_array($confVars['EXTENSIONS'] ?? null) ? $confVars['EXTENSIONS'] : [];
        $texter = is_array($extensions['texter'] ?? null) ? $extensions['texter'] : [];

        $texter['llmRepositoryClass'] = NrLlmRepository::class;
        $extensions['texter'] = $texter;
        $confVars['EXTENSIONS'] = $extensions;
        $GLOBALS['TYPO3_CONF_VARS'] = $confVars;
    }
}
