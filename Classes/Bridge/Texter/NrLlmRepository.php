<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlmCompat\Bridge\Texter;

use In2code\Texter\Domain\Repository\Llm\AbstractRepository;
use In2code\Texter\Domain\Repository\Llm\RepositoryInterface;
use In2code\Texter\Domain\Service\ConversationHistory;
use Netresearch\NrLlm\Service\LlmServiceManagerInterface;
use Netresearch\NrLlm\Service\Option\ChatOptions;
use TYPO3\CMS\Core\Http\RequestFactory;

/**
 * texter's OFFICIAL custom-repository hook, pointed at nr-llm: registered as
 * $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['texter']['llmRepositoryClass']
 * by the TexterIntegration's runtime configuration. No third-party internals
 * are overridden — this class implements the extension's own
 * RepositoryInterface.
 *
 * Behavior mirrors the shipped GeminiRepository up to the provider boundary:
 * the configured promptPrefix is applied via the ORIGINAL protected
 * AbstractRepository::extendPrompt(), and the per-page conversation history
 * (backend-user session, Gemini message shape) keeps working — its entries
 * are mapped onto nr-llm chat messages, so follow-up prompts keep their
 * context. Fail closed: an nr-llm failure propagates, there is no fallback
 * to Gemini, and the extension's own API key is never read.
 *
 * Registered as a PUBLIC service by the compiler pass (texter's factory
 * resolves the configured class via $container->get()).
 */
final class NrLlmRepository extends AbstractRepository implements RepositoryInterface
{
    private readonly LlmServiceManagerInterface $llmServiceManager;

    public function __construct(
        LlmServiceManagerInterface $llmServiceManager,
        RequestFactory $requestFactory,
        ConversationHistory $conversationHistory,
    ) {
        parent::__construct($requestFactory, $conversationHistory);
        $this->llmServiceManager = $llmServiceManager;
    }

    /**
     * nr-llm manages provider credentials; there is no extension-local key
     * to check. Deliberately empty — as the interface allows.
     */
    public function checkApiKey(): void {}

    public function getApiUrl(): string
    {
        // No fixed endpoint: nr-llm routes per configuration. The interface
        // demands a string; nothing in the extension consumes it.
        return 'nr-llm';
    }

    /**
     * Mirrors texter 3.0.0, GeminiRepository::getText — same prompt
     * extension, same conversation-history lifecycle; only the transport is
     * nr-llm.
     */
    public function getText(string $prompt, string $pageId = '0'): string
    {
        $this->checkApiKey();
        $history = $this->conversationHistory->getHistory($pageId);
        $this->conversationHistory->addUserMessage($history, $this->extendPrompt($prompt));
        $options = (new ChatOptions())->withCallerSource('texter', 'getText');
        $response = $this->llmServiceManager->chat($this->toChatMessages($history), $options)->getText();
        $this->conversationHistory->addModelResponse($history, $response);
        $this->conversationHistory->saveHistory($history, $pageId);

        return $response;
    }

    /**
     * Maps texter's Gemini-shaped history entries
     * (['role' => 'user'|'model', 'parts' => [['text' => …]]]) onto nr-llm
     * chat messages. Unknown shapes are skipped rather than guessed.
     *
     * @param array<mixed> $history
     *
     * @return list<array{role: string, content: string}>
     */
    private function toChatMessages(array $history): array
    {
        $messages = [];

        foreach ($history as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $role = ($entry['role'] ?? '') === 'model' ? 'assistant' : 'user';

            $texts = [];
            foreach (is_array($entry['parts'] ?? null) ? $entry['parts'] : [] as $part) {
                if (is_array($part) && is_string($part['text'] ?? null)) {
                    $texts[] = $part['text'];
                }
            }

            if ($texts === []) {
                continue;
            }

            $messages[] = ['role' => $role, 'content' => implode("\n", $texts)];
        }

        return $messages;
    }
}
