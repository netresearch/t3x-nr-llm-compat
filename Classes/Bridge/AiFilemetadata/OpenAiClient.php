<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlmCompat\Bridge\AiFilemetadata;

use Locale;
use Mfd\Ai\FileMetadata\Api\OpenAiClient as OriginalOpenAiClient;
use Mfd\Ai\FileMetadata\Domain\Dto\TokenUsageResult;
use Mfd\Ai\FileMetadata\Event\ModifyRequestDataEvent;
use Mfd\Ai\FileMetadata\Services\TokenUsageService;
use Netresearch\NrLlm\Service\Feature\VisionServiceInterface;
use Netresearch\NrLlm\Service\Option\VisionOptions;
use Netresearch\NrLlmCompat\Exception\UnmappableRequestChangeException;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use UnexpectedValueException;

/**
 * Bridge for ai_filemetadata's OpenAiClient — the first vision bridge:
 * identical service id, buildAltText() routes through nr-llm's vision
 * surface instead of the openai-php client.
 *
 * Deliberately does NOT call the parent constructor: the original builds the
 * openai-php client there and reads the extension's API key — neither must
 * happen. The parent's (private, uninitialized) properties are never touched
 * because the single provider-calling method is fully overridden.
 *
 * The prompt construction (configured altTextPrompt or the original default,
 * plus the "Answer in {language}" suffix) and the temperature validation
 * mirror the original. The extension's "model" setting is nr-llm's job and
 * deliberately ignored; the usage row mirrored into the extension's own
 * TokenUsageService names the model that ACTUALLY served the request.
 * Fail closed: an nr-llm failure propagates — no fallback to OpenAI.
 *
 * From 1.7.1 on (detected by the presence of ModifyRequestDataEvent), the
 * bridge also mirrors two behaviours of the original buildAltText():
 *
 * - It builds the same OpenAI request data and dispatches
 *   ModifyRequestDataEvent with it. A changed system prompt is passed on as
 *   nr-llm's prompt, which every nr-llm vision provider sends. nr-llm sends
 *   it as the text part of the user message, not as a system message, and
 *   the original's fixed user text ("Generate alt text.") is not sent; this
 *   holds for the extension's own prompt as well. A detail level of "auto"
 *   counts as unchanged, since nr-llm sends "auto" when none is set
 *   (VisionService::analyzeImageFull(), v0.55.1). Any other change cannot be
 *   expressed reliably through nr-llm, so the request is refused with an
 *   UnmappableRequestChangeException naming the keys. That includes tools,
 *   another model, other messages and further keys, and also:
 *   temperature, which nr-llm's vision providers do not send
 *   (netresearch/t3x-nr-llm#1474); an image detail level other than "auto",
 *   which only the OpenAI-style providers send, and the bridge cannot know
 *   which provider nr-llm resolves; an empty system prompt; and
 *   max_tokens/max_completion_tokens, because without
 *   a finish reason (netresearch/t3x-nr-llm#1473) an answer cut off at a
 *   listener's token limit could not be told apart and would be stored.
 *   A refused request is never sent to OpenAI instead: that needs the
 *   extension's own API key and bypasses nr-llm's budgets and policies.
 * - An empty completion throws instead of returning '', as 1.7.1 does. A
 *   file whose answer is always empty therefore stops ai:generate-alt-texts
 *   at that file on every run, the same as upstream 1.7.1. Every version
 *   stores the returned text together with alttext_generation_date
 *   (FalAdapter::localizeFile(), EnrichFileMetadataAfterCreation), and the
 *   batch skips a file with that date set, so '' is never retried. Only from
 *   1.7.1 on does the upload listener catch an exception; before 1.7.1 a
 *   throw would fail the upload, so the bridge keeps returning '' there.
 *
 * An empty image returns '' on every version (after the listeners ran on
 * 1.7.1+, whose changes are then neither mapped nor refused): it can never
 * succeed, so the file is marked done instead of stopping
 * ai:generate-alt-texts at it on every run. An empty answer, in contrast,
 * can succeed on a retry, so it keeps throwing.
 *
 * NOT registered as a DI service (see Services.yaml): the compiler pass
 * swaps this class into ai_filemetadata's autoregistered definition.
 */
final readonly class OpenAiClient extends OriginalOpenAiClient
{
    private const DEFAULT_ALT_TEXT_PROMPT = <<<'GPT'
        Create an alternative text for this image to be used on websites for visually impaired people who cannot see the image.
        Focus on the image's main content and ignore all elements in the image not relevant to understand its message.
        The text should not exceed 50 words.
        GPT;

    /**
     * The original's model when the extension setting is empty; only part
     * of the request data listeners see, never sent to nr-llm.
     */
    private const DEFAULT_MODEL = 'gpt-4o-mini';

    public function __construct(
        private VisionServiceInterface $visionService,
        private ExtensionConfiguration $configuration,
        private LoggerInterface $logger,
        private TokenUsageService $usageTracker,
        private EventDispatcherInterface $eventDispatcher,
    ) {
        // No parent::__construct() on purpose — see class docblock.
    }

    public function buildAltText(string $image, ?string $locale = null, string $context = '', int $fileUid = 0): string
    {
        $dispatchesRequestEvent = $this->dispatchesRequestEvent();

        $prompt      = $this->buildPrompt($locale);
        $imageUrl    = 'data:image/jpeg;base64,' . base64_encode($image);
        $temperature = $this->configuredTemperature();

        // Logged before the event, as the original does, so a refused
        // request still shows the prompt it was built with.
        $this->logger->info('Prompt: ' . $prompt);

        // The original dispatches ModifyRequestDataEvent before anything can
        // fail, so its listeners run for an empty image too. Their changes
        // are only mapped (or refused) for an image that will be sent.
        if ($dispatchesRequestEvent) {
            $builtPrompt = $prompt;
            $prompt      = $this->applyRequestListeners($prompt, $imageUrl, $temperature, $fileUid, $image !== '');
            if ($prompt !== $builtPrompt) {
                $this->logger->info('Prompt changed by a ModifyRequestDataEvent listener: ' . $prompt);
            }
        }

        // A 0-byte file has nothing to describe. Sent on, it becomes the bare prefix
        // "data:image/jpeg;base64," and the provider refuses it ("Invalid base64
        // image_url."), which fails the file operation that triggered the listener.
        // On every version '' is returned: ai_filemetadata stores it with
        // alttext_generation_date, so the file counts as done. An empty image
        // can never be described, and a throw would stop ai:generate-alt-texts
        // at this file on every run (its batch catches nothing).
        if ($image === '') {
            $this->logger->warning('No alt text generated: the image is empty (0 bytes).', ['fileUid' => $fileUid]);

            return '';
        }

        $response = $this->visionService->analyzeImageFull(
            $imageUrl,
            $prompt,
            $this->buildOptions($temperature),
        );

        // Mirror the usage into the extension's own statistics so its
        // dashboard widgets keep working (the original tracks after the
        // OpenAI call in exactly this way).
        $this->usageTracker->track(
            new TokenUsageResult(
                $response->usage->promptTokens,
                $response->usage->completionTokens,
                $response->usage->totalTokens,
                $response->model,
            ),
            $context,
            $fileUid,
            $locale,
        );

        $altText = trim(trim($response->getText(), '"'));

        // 1.7.1 throws on an empty completion (Classes/Api/OpenAiClient.php,
        // 1.7.1): '' would be stored with alttext_generation_date and never
        // be retried. Before 1.7.1 the upload listener does not catch an
        // exception, so '' stays there (class docblock).
        if ($altText === '' && $dispatchesRequestEvent) {
            throw new UnexpectedValueException('Did not receive a usable alt text (empty completion)', 1755500007);
        }

        return $altText;
    }

    /**
     * ModifyRequestDataEvent exists from ai_filemetadata 1.7.1 on; its
     * presence selects the 1.7.1 behaviour.
     */
    private function dispatchesRequestEvent(): bool
    {
        return class_exists(ModifyRequestDataEvent::class);
    }

    /**
     * Builds the request data exactly as the original does, lets the
     * listeners change it, and returns the prompt to send, the only part a
     * listener may change.
     */
    private function applyRequestListeners(string $prompt, string $imageUrl, ?float $temperature, int $fileUid, bool $mapChanges): string
    {
        $model = $this->stringSetting('model');

        $original = [
            'model'    => $model === '' ? self::DEFAULT_MODEL : $model,
            'messages' => $this->requestMessages($prompt, $imageUrl),
        ];
        if ($temperature !== null) {
            $original['temperature'] = $temperature;
        }

        $event      = new ModifyRequestDataEvent($original);
        $dispatched = $this->eventDispatcher->dispatch($event);
        if ($dispatched instanceof ModifyRequestDataEvent) {
            // PSR-14 returns the event; the original reads that return value.
            $event = $dispatched;
        }

        $modified = $event->getRequestData();
        if (!$mapChanges) {
            return $prompt;
        }

        $unmapped = [];

        foreach (array_unique([...array_keys($original), ...array_keys($modified)]) as $key) {
            $isSet = array_key_exists($key, $modified);
            $value = $modified[$key] ?? null;
            if (array_key_exists($key, $original) === $isSet
                && self::normalised($original[$key]) === self::normalised($value)
            ) {
                continue;
            }

            if ($this->isNoOpChange($original, $key, $isSet, $value)) {
                continue;
            }

            $changedPrompt = $key === 'messages' ? $this->changedPrompt($value, $imageUrl) : null;
            if ($changedPrompt === null) {
                // Everything but a changed system prompt, among them "model"
                // (routing is nr-llm's configuration, as for the extension's
                // own model setting), "temperature", the token limits and
                // the image detail level: see the class docblock.
                $unmapped[] = (string) $key;

                continue;
            }

            $prompt = $changedPrompt;
        }

        if ($unmapped !== []) {
            // Debug only: the exception carries the same keys, and ai_filemetadata's
            // callers log or report it.
            $this->logger->debug(
                'No alt text generated: a ModifyRequestDataEvent listener changed request data that nr-llm cannot express: {keys}.',
                ['keys' => implode(', ', $unmapped), 'fileUid' => $fileUid],
            );

            throw new UnmappableRequestChangeException(
                sprintf(
                    'A ModifyRequestDataEvent listener changed request data that nr-llm cannot express (%s); the request is refused instead of being sent without the change.',
                    implode(', ', $unmapped),
                ),
                1755500006,
            );
        }

        return $prompt;
    }

    /**
     * Changes that send nothing different: an added key with an empty array
     * or null (for example `tools => []`), and a numeric string equal to the
     * original number (for example "0.3" for 0.3).
     *
     * @param array<mixed> $original
     */
    private function isNoOpChange(array $original, int|string $key, bool $isSet, mixed $value): bool
    {
        if (!array_key_exists($key, $original)) {
            return $isSet && ($value === [] || $value === null);
        }

        $before = $original[$key];

        return $isSet
            && (is_int($before) || is_float($before))
            && is_string($value)
            && is_numeric($value)
            && (float) $value === (float) $before;
    }

    /**
     * Returns the system prompt when the messages are the original two with
     * only that prompt changed; anything else returns null. The prompt may
     * be a string or a single text content part. An image detail level of
     * "auto" counts as unchanged: nr-llm sends "auto" when none is set.
     */
    private function changedPrompt(mixed $messages, string $imageUrl): ?string
    {
        if (!is_array($messages) || !is_array($messages[0] ?? null)) {
            return null;
        }

        $system = $messages[0];
        $prompt = $this->promptText($system['content'] ?? null);
        // An empty prompt leaves the model without instructions.
        if ($prompt === null || trim($prompt) === '') {
            return null;
        }

        $system['content'] = $prompt;
        $messages[0]       = $system;
        $messages          = $this->withoutAutoDetail($messages);

        if (self::normalised($this->requestMessages($prompt, $imageUrl)) !== self::normalised($messages)) {
            return null;
        }

        return $prompt;
    }

    /**
     * Removes `detail: "auto"` from the image part, nr-llm's own default.
     *
     * @param array<mixed> $messages
     *
     * @return array<mixed>
     */
    private function withoutAutoDetail(array $messages): array
    {
        $user    = $messages[1] ?? null;
        $content = is_array($user) ? ($user['content'] ?? null) : null;
        $image   = is_array($content) ? ($content[1] ?? null) : null;
        $url     = is_array($image) ? ($image['image_url'] ?? null) : null;
        if (!is_array($user) || !is_array($content) || !is_array($image) || !is_array($url) || ($url['detail'] ?? null) !== 'auto') {
            return $messages;
        }

        unset($url['detail']);
        $image['image_url'] = $url;
        $content[1]         = $image;
        $user['content']    = $content;
        $messages[1]        = $user;

        return $messages;
    }

    /**
     * The text of a message content: a string, or a list with exactly one
     * text part.
     */
    private function promptText(mixed $content): ?string
    {
        if (is_string($content)) {
            return $content;
        }

        if (!is_array($content) || count($content) !== 1 || !is_array($content[0] ?? null)) {
            return null;
        }

        $part = $content[0];
        ksort($part);

        return array_keys($part) === ['text', 'type'] && $part['type'] === 'text' && is_string($part['text'])
            ? $part['text']
            : null;
    }

    /**
     * Sorts the keys of every associative level, so data a listener rebuilt
     * in another key order compares as unchanged; lists keep their order.
     * Numbers compare by value, so int 1 equals float 1.0.
     */
    private static function normalised(mixed $value): mixed
    {
        if (is_int($value)) {
            return (float) $value;
        }

        if (!is_array($value)) {
            return $value;
        }

        $value = array_map(self::normalised(...), $value);
        if (!array_is_list($value)) {
            ksort($value);
        }

        return $value;
    }

    /**
     * The messages of the original's request data (1.7.1).
     *
     * @return list<array<string, mixed>>
     */
    private function requestMessages(string $prompt, string $imageUrl): array
    {
        return [
            [
                'role'    => 'system',
                'content' => $prompt,
            ],
            [
                'role'    => 'user',
                'content' => [
                    [
                        'type' => 'text',
                        'text' => 'Generate alt text.',
                    ],
                    [
                        'type'      => 'image_url',
                        'image_url' => ['url' => $imageUrl],
                    ],
                ],
            ],
        ];
    }

    private function buildPrompt(?string $locale): string
    {
        $prompt = $this->stringSetting('altTextPrompt');
        if ($prompt === '') {
            $prompt = self::DEFAULT_ALT_TEXT_PROMPT;
        } else {
            $prompt = str_replace('\n', "\n", $prompt);
        }

        if ($locale !== null && $locale !== '') {
            $languageEnglishName = Locale::getDisplayLanguage(Locale::getPrimaryLanguage($locale) ?? $locale, 'en');
            $prompt .= "\n Answer in {$languageEnglishName}.";
        }

        return $prompt;
    }

    private function configuredTemperature(): ?float
    {
        $temperatureSetting = $this->stringSetting('temperature');
        if ($temperatureSetting === '') {
            return null;
        }

        // Mirrors the original's validation: outside (0.1 .. 1) falls back
        // to 0.6.
        $temperature = (float) $temperatureSetting;

        return $temperature < 0.1 || $temperature > 1 ? 0.6 : $temperature;
    }

    private function buildOptions(?float $temperature): VisionOptions
    {
        $options = new VisionOptions();

        // The extension's own temperature setting, as before 1.7.1; listener
        // changes to it are refused (see the class docblock).
        if ($temperature !== null) {
            $options = $options->withTemperature($temperature);
        }

        // Caller-source attribution (nr-llm ADR-177).
        return $options->withCallerSource('ai_filemetadata', 'buildAltText');
    }

    private function stringSetting(string $key): string
    {
        $value = $this->configuration->get('ai_filemetadata', $key);

        return is_scalar($value) ? (string) $value : '';
    }
}
