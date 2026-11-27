<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlmCompat\Integration;

use Composer\Semver\Semver;
use Mfd\Ai\FileMetadata\Domain\Dto\TokenUsageResult;
use Mfd\Ai\FileMetadata\Event\ModifyRequestDataEvent;
use Mfd\Ai\FileMetadata\Services\TokenUsageService;
use Netresearch\NrLlmCompat\Bridge\AiFilemetadata\OpenAiClient as OpenAiClientBridge;
use Netresearch\NrLlmCompat\Integration\Contract\ClassContract;
use Netresearch\NrLlmCompat\Integration\Contract\MethodContract;
use Netresearch\NrLlmCompat\Integration\Diagnostics\VersionVerifier;
use Psr\Log\LoggerInterface;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\EventDispatcher\EventDispatcher;

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
 * Contracts mirror the verified 1.6.2 and 1.7.1 sources. 1.7.1 adds an
 * EventDispatcher to the OpenAiClient constructor and dispatches
 * ModifyRequestDataEvent, which the bridge dispatches too; the constructor
 * and event contracts therefore depend on the installed version.
 */
final readonly class AiFilemetadataIntegration implements IntegrationInterface, ProvidesStatusNotes
{
    private const OPENAI_CLIENT = \Mfd\Ai\FileMetadata\Api\OpenAiClient::class;

    private const TOKEN_USAGE_SERVICE = TokenUsageService::class;

    private const TOKEN_USAGE_RESULT = TokenUsageResult::class;

    private const MODIFY_REQUEST_DATA_EVENT = ModifyRequestDataEvent::class;

    /**
     * The first release whose OpenAiClient takes an EventDispatcher and
     * dispatches ModifyRequestDataEvent.
     */
    private const REQUEST_EVENT_VERSIONS = '>=1.7.1';

    /**
     * @param string|null $assumedVersion the ai_filemetadata version to
     *                                    describe; null reads the installed one
     */
    public function __construct(
        private ?string $assumedVersion = null,
    ) {}

    /**
     * Whether the described version takes an EventDispatcher in the
     * OpenAiClient constructor and dispatches ModifyRequestDataEvent.
     */
    public function dispatchesRequestEvent(): bool
    {
        if ($this->assumedVersion !== null) {
            return Semver::satisfies($this->assumedVersion, self::REQUEST_EVENT_VERSIONS);
        }

        return (new VersionVerifier())->satisfies($this->getPackageName(), self::REQUEST_EVENT_VERSIONS);
    }

    /**
     * From 1.7.1 on, names the registered ModifyRequestDataEvent listeners:
     * the bridge refuses every request in which a listener changes more than
     * the system prompt, so a site with such a listener gets no alt texts.
     */
    public function getStatusNotes(array $eventListeners): array
    {
        $listeners = $eventListeners[self::MODIFY_REQUEST_DATA_EVENT] ?? null;
        if (!$this->dispatchesRequestEvent() || !is_array($listeners) || $listeners === []) {
            return [];
        }

        $names = [];
        foreach ($listeners as $identifier => $listener) {
            $service = is_array($listener) ? ($listener['service'] ?? null) : null;
            $method  = is_array($listener) ? ($listener['method'] ?? null) : null;
            $names[] = is_string($service)
                ? $service . (is_string($method) ? '::' . $method : '')
                : (string) $identifier;
        }

        return [sprintf(
            '%d ModifyRequestDataEvent listener(s) registered: %s. While this integration is active, the bridge passes on a changed system prompt only; any other change (tools, model, temperature, token limits, image detail level, other request keys) refuses the request, and no alt text is generated for that file.',
            count($names),
            implode(', ', $names),
        )];
    }

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
        $dispatchesRequestEvent = $this->dispatchesRequestEvent();

        $constructorParameters = [ExtensionConfiguration::class, LoggerInterface::class, self::TOKEN_USAGE_SERVICE];
        if ($dispatchesRequestEvent) {
            $constructorParameters[] = EventDispatcher::class;
        }

        $contracts = [
            new MethodContract(
                self::OPENAI_CLIENT,
                'buildAltText',
                ['string', 'string', 'string', 'int'],
                'string',
            ),
            new MethodContract(
                self::OPENAI_CLIENT,
                '__construct',
                $constructorParameters,
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

        if ($dispatchesRequestEvent) {
            // The bridge builds the event with the request data and reads
            // the listeners' result back.
            $contracts[] = new MethodContract(self::MODIFY_REQUEST_DATA_EVENT, '__construct', ['array'], null);
            $contracts[] = new MethodContract(self::MODIFY_REQUEST_DATA_EVENT, 'getRequestData', [], 'array');
        }

        return $contracts;
    }

    public function getPropertyContracts(): array
    {
        return [];
    }
}
