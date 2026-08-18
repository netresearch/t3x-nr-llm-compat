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
use Mfd\Ai\FileMetadata\Services\TokenUsageService;
use Netresearch\NrLlm\Service\Feature\VisionServiceInterface;
use Netresearch\NrLlm\Service\Option\VisionOptions;
use Psr\Log\LoggerInterface;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;

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
 * mirror the 1.6.2 original. The extension's "model" setting is nr-llm's
 * job and deliberately ignored; the usage row mirrored into the extension's
 * own TokenUsageService names the model that ACTUALLY served the request.
 * Fail closed: an nr-llm failure propagates — no fallback to OpenAI.
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

    public function __construct(
        private VisionServiceInterface $visionService,
        private ExtensionConfiguration $configuration,
        private LoggerInterface $logger,
        private TokenUsageService $usageTracker,
    ) {
        // No parent::__construct() on purpose — see class docblock.
    }

    public function buildAltText(string $image, ?string $locale = null, string $context = '', int $fileUid = 0): string
    {
        $prompt = $this->buildPrompt($locale);
        $this->logger->info('Prompt: ' . $prompt);

        $response = $this->visionService->analyzeImageFull(
            'data:image/jpeg;base64,' . base64_encode($image),
            $prompt,
            $this->buildOptions(),
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

        return trim(trim($response->getText(), '"'));
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

    private function buildOptions(): ?VisionOptions
    {
        $temperatureSetting = $this->stringSetting('temperature');
        if ($temperatureSetting === '') {
            return null;
        }

        // Mirrors the original's validation: outside (0.1 .. 1) falls back
        // to 0.6.
        $temperature = (float)$temperatureSetting;
        if ($temperature < 0.1 || $temperature > 1) {
            $temperature = 0.6;
        }

        return new VisionOptions(temperature: $temperature);
    }

    private function stringSetting(string $key): string
    {
        $value = $this->configuration->get('ai_filemetadata', $key);

        return is_scalar($value) ? (string)$value : '';
    }
}
