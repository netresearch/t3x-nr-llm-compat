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
 *   ModifyRequestDataEvent with it, then maps what listeners changed onto
 *   nr-llm's VisionOptions: the system prompt, temperature, max_tokens or
 *   max_completion_tokens, and the image's detail level. Any other change
 *   (tools, another model, other messages, further request keys) cannot be
 *   expressed through nr-llm, so the request is refused with an
 *   UnmappableRequestChangeException naming the keys. It is never sent to
 *   OpenAI instead: that needs the extension's own API key and bypasses
 *   nr-llm's budgets and policies.
 * - An empty completion throws instead of returning '', so callers do not
 *   persist an empty alternative.
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

    private const DETAIL_LEVELS = ['auto', 'low', 'high'];

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
        // A 0-byte file has nothing to describe. Sent on, it becomes the bare prefix
        // "data:image/jpeg;base64," and the provider refuses it ("Invalid base64
        // image_url."), which fails the file operation that triggered the listener.
        if ($image === '') {
            $this->logger->warning('No alt text generated: the image is empty (0 bytes).', ['fileUid' => $fileUid]);

            return '';
        }

        $prompt = $this->buildPrompt($locale);
        $this->logger->info('Prompt: ' . $prompt);

        $imageUrl = 'data:image/jpeg;base64,' . base64_encode($image);
        $request  = [
            'prompt'      => $prompt,
            'temperature' => $this->configuredTemperature(),
            'maxTokens'   => null,
            'detailLevel' => null,
        ];

        $dispatchesRequestEvent = $this->dispatchesRequestEvent();
        if ($dispatchesRequestEvent) {
            $request = $this->applyRequestListeners($prompt, $imageUrl, $request['temperature'], $fileUid);
        }

        $response = $this->visionService->analyzeImageFull(
            $imageUrl,
            $request['prompt'],
            $this->buildOptions($request['temperature'], $request['maxTokens'], $request['detailLevel']),
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

        // 1.7.1 throws on an empty completion: returning '' would be stored
        // together with alttext_generation_date and skip the file on every
        // later run (Classes/Api/OpenAiClient.php, 1.7.1).
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
     * listeners change it, and maps the result onto what nr-llm can express.
     *
     * @return array{prompt: string, temperature: ?float, maxTokens: ?int, detailLevel: ?string}
     */
    private function applyRequestListeners(string $prompt, string $imageUrl, ?float $temperature, int $fileUid): array
    {
        $model = $this->stringSetting('model');

        $original = [
            'model'    => $model === '' ? self::DEFAULT_MODEL : $model,
            'messages' => $this->requestMessages($prompt, $imageUrl, null),
        ];
        if ($temperature !== null) {
            $original['temperature'] = $temperature;
        }

        $event = new ModifyRequestDataEvent($original);
        $this->eventDispatcher->dispatch($event);
        $modified = $event->getRequestData();

        $request  = ['prompt' => $prompt, 'temperature' => $temperature, 'maxTokens' => null, 'detailLevel' => null];
        $unmapped = [];

        foreach (array_unique([...array_keys($original), ...array_keys($modified)]) as $key) {
            $isSet = array_key_exists($key, $modified);
            $value = $modified[$key] ?? null;
            if (array_key_exists($key, $original) === $isSet && $original[$key] === $value) {
                continue;
            }

            switch ($key) {
                case 'temperature':
                    if (!$isSet) {
                        $request['temperature'] = null;
                    } elseif (is_int($value) || is_float($value)) {
                        $request['temperature'] = (float) $value;
                    } else {
                        $unmapped[] = $key;
                    }

                    break;

                case 'max_tokens':
                case 'max_completion_tokens':
                    if (is_int($value) && $value > 0
                        && ($request['maxTokens'] === null || $request['maxTokens'] === $value)
                    ) {
                        $request['maxTokens'] = $value;
                    } else {
                        $unmapped[] = $key;
                    }

                    break;

                case 'messages':
                    $messages = $this->mapMessages($value, $imageUrl);
                    if ($messages === null) {
                        $unmapped[] = $key;
                    } else {
                        $request['prompt']      = $messages['prompt'];
                        $request['detailLevel'] = $messages['detailLevel'];
                    }

                    break;

                default:
                    // "model" included: model routing is nr-llm's
                    // configuration, as for the extension's own model setting.
                    $unmapped[] = (string) $key;
            }
        }

        if ($unmapped !== []) {
            $this->logger->warning(
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

        return $request;
    }

    /**
     * Accepts the original two messages with a changed system prompt and an
     * optional detail level on the image; anything else returns null.
     *
     * @return array{prompt: string, detailLevel: ?string}|null
     */
    private function mapMessages(mixed $messages, string $imageUrl): ?array
    {
        if (!is_array($messages)) {
            return null;
        }

        $system = $messages[0] ?? null;
        $prompt = is_array($system) ? ($system['content'] ?? null) : null;
        if (!is_string($prompt)) {
            return null;
        }

        $user        = $messages[1] ?? null;
        $content     = is_array($user) ? ($user['content'] ?? null) : null;
        $image       = is_array($content) ? ($content[1] ?? null) : null;
        $imageDetail = is_array($image) && is_array($image['image_url'] ?? null) ? ($image['image_url']['detail'] ?? null) : null;

        $detailLevel = null;
        if ($imageDetail !== null) {
            if (!is_string($imageDetail) || !in_array($imageDetail, self::DETAIL_LEVELS, true)) {
                return null;
            }

            $detailLevel = $imageDetail;
        }

        if ($this->requestMessages($prompt, $imageUrl, $detailLevel) !== $messages) {
            return null;
        }

        return ['prompt' => $prompt, 'detailLevel' => $detailLevel];
    }

    /**
     * The messages of the original's request data (1.7.1).
     *
     * @return list<array<string, mixed>>
     */
    private function requestMessages(string $prompt, string $imageUrl, ?string $detailLevel): array
    {
        $image = ['url' => $imageUrl];
        if ($detailLevel !== null) {
            $image['detail'] = $detailLevel;
        }

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
                        'image_url' => $image,
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

    private function buildOptions(?float $temperature, ?int $maxTokens, ?string $detailLevel): VisionOptions
    {
        $options = new VisionOptions();

        if ($temperature !== null) {
            $options = $options->withTemperature($temperature);
        }

        if ($maxTokens !== null) {
            $options = $options->withMaxTokens($maxTokens);
        }

        if ($detailLevel !== null) {
            $options = $options->withDetailLevel($detailLevel);
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
