<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlmCompat\Integration;

use Mfd\Ai\FileMetadata\Domain\Dto\TokenUsageResult;
use Mfd\Ai\FileMetadata\Event\ModifyRequestDataEvent;
use Mfd\Ai\FileMetadata\Services\TokenUsageService;
use Netresearch\NrLlmCompat\Bridge\AiFilemetadata\OpenAiClient as OpenAiClientBridge;
use Netresearch\NrLlmCompat\Integration\Contract\ClassContract;
use Netresearch\NrLlmCompat\Integration\Contract\MethodContract;
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
 * and event contracts therefore depend on whether that event class exists,
 * the same check the bridge makes.
 *
 * The supported range covers the verified releases, up to 1.6.6 and 1.7.1
 * (1.6.4's classes equal 1.6.3's, its OpenAiClient equals 1.7.0's; 1.6.5 and
 * 1.6.6 add only a suggestion cache and a DataHandler hook): the bridge
 * rebuilds the original's request data itself, and upstream already changed
 * that contract in a patch release (1.7.1). Diff each new release's
 * Api/OpenAiClient.php against the bridge before the range is widened.
 */
final readonly class AiFilemetadataIntegration implements IntegrationInterface, ProvidesStatusNotes
{
    private const OPENAI_CLIENT = \Mfd\Ai\FileMetadata\Api\OpenAiClient::class;

    private const TOKEN_USAGE_SERVICE = TokenUsageService::class;

    private const TOKEN_USAGE_RESULT = TokenUsageResult::class;

    private const MODIFY_REQUEST_DATA_EVENT = ModifyRequestDataEvent::class;

    /**
     * @param string $requestEventClass the class whose presence marks a release that takes an
     *                                  EventDispatcher and dispatches ModifyRequestDataEvent (1.7.1+);
     *                                  tests pass another name to describe either case
     */
    public function __construct(
        private string $requestEventClass = self::MODIFY_REQUEST_DATA_EVENT,
    ) {}

    /**
     * Whether the installed release takes an EventDispatcher in the
     * OpenAiClient constructor and dispatches ModifyRequestDataEvent: the
     * presence of that event class, the same check the bridge makes.
     */
    public function dispatchesRequestEvent(): bool
    {
        return class_exists($this->requestEventClass);
    }

    /**
     * From 1.7.1 on, names the registered ModifyRequestDataEvent listeners:
     * the bridge refuses every request in which a listener changes more than
     * the system prompt, so a site with such a listener gets no alt texts.
     */
    public function readsEventListeners(): bool
    {
        return $this->dispatchesRequestEvent();
    }

    public function getStatusNotes(array $eventListeners, bool $active): array
    {
        $notes = [];
        if ($this->temperatureIsConfigured()) {
            $notes[] = sprintf(
                "ai_filemetadata's temperature setting %s no effect: nr-llm's vision providers do not send a temperature yet (netresearch/t3x-nr-llm#1474).",
                $active ? 'has' : 'will have',
            );
        }

        if (!$this->dispatchesRequestEvent()) {
            return $notes;
        }

        $names = $this->requestEventListenerNames($eventListeners);
        if ($names === null) {
            $notes[] = "The registered ModifyRequestDataEvent listeners could not be read: TYPO3's listener definitions have an unexpected shape. A listener that changes more than the system prompt makes the bridge refuse the request.";

            return $notes;
        }

        if ($names === []) {
            return $notes;
        }

        $notes[] = sprintf(
            '%d ModifyRequestDataEvent listener(s) registered: %s. The bridge passes on a changed system prompt only, as nr-llm\'s prompt (sent in the user message; the original\'s fixed user text is not sent). Any other change (tools, model, token limits, an image detail level other than "auto", other request keys) %s. Then no alt text is generated for that file, ai:generate-alt-texts stops there, and the backend alt-text suggestion fails.',
            count($names),
            implode(', ', $names),
            $active ? 'makes the bridge refuse the request' : 'makes the bridge refuse the request once the integration is enabled',
        );

        return $notes;
    }

    /**
     * Whether ai_filemetadata's own temperature setting is set; read like
     * IntegrationSettings reads the toggles, straight from TYPO3_CONF_VARS.
     */
    private function temperatureIsConfigured(): bool
    {
        $confVars    = $GLOBALS['TYPO3_CONF_VARS'] ?? null;
        $extensions  = is_array($confVars) ? ($confVars['EXTENSIONS'] ?? null) : null;
        $settings    = is_array($extensions) ? ($extensions['ai_filemetadata'] ?? null) : null;
        $temperature = is_array($settings) ? ($settings['temperature'] ?? null) : null;

        return is_scalar($temperature) && trim((string) $temperature) !== '';
    }

    /**
     * The listeners of ModifyRequestDataEvent as "service::method", or null
     * when that entry does not have the shape TYPO3 13.4 and 14.3 use: an
     * array keyed by listener identifier (ListenerProvider::addListener()),
     * each value ['service' => string, 'method' => ?string]. Other events
     * are not inspected.
     *
     * @param array<mixed> $eventListeners
     *
     * @return list<string>|null
     */
    private function requestEventListenerNames(array $eventListeners): ?array
    {
        $listeners = $eventListeners[self::MODIFY_REQUEST_DATA_EVENT] ?? [];
        if (!is_array($listeners)) {
            return null;
        }

        $names = [];
        foreach ($listeners as $listener) {
            $service = is_array($listener) ? ($listener['service'] ?? null) : null;
            $method  = is_array($listener) ? ($listener['method'] ?? null) : null;
            if (!is_string($service) || ($method !== null && !is_string($method))) {
                return null;
            }

            $names[] = $service . ($method !== null ? '::' . $method : '');
        }

        return $names;
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
        return '>=1.6.0 <1.6.7 || >=1.7.0 <1.7.2';
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
