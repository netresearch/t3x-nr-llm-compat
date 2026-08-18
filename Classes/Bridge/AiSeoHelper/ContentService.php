<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlmCompat\Bridge\AiSeoHelper;

use Netresearch\NrLlm\Service\Feature\CompletionServiceInterface;
use Netresearch\NrLlm\Service\Option\ChatOptions;
use Netresearch\NrLlmCompat\Exception\UnexpectedAiResponseException;
use Passionweb\AiSeoHelper\Service\ContentService as OriginalContentService;
use TYPO3\CMS\Core\Domain\Repository\PageRepository;
use TYPO3\CMS\Core\Http\RequestFactory;
use TYPO3\CMS\Core\Routing\SiteMatcher;

/**
 * Bridge for ai_seo_helper's ContentService: identical service id, identical
 * behavior up to the provider boundary — only requestAi() is overridden and
 * routes through nr-llm instead of calling api.openai.com.
 *
 * The extension's own OpenAI API key is never read; model selection is
 * nr-llm's job (the original's openAiModel setting is deliberately ignored),
 * while the original's temperature/top_p settings and prompt construction
 * are preserved. Fail closed: when nr-llm cannot serve the request the
 * exception propagates — there is no fallback to the original OpenAI call.
 *
 * NOT registered as a DI service (see Services.yaml): the compiler pass
 * swaps this class into ai_seo_helper's own ContentService definition, so
 * the original explicit arguments ($languages, $extConf) still apply and
 * autowiring fills the added nr-llm dependency.
 */
final class ContentService extends OriginalContentService
{
    private readonly CompletionServiceInterface $completionService;

    /**
     * @param array<mixed> $languages
     * @param array<mixed> $extConf
     */
    public function __construct(
        CompletionServiceInterface $completionService,
        PageRepository $pageRepository,
        SiteMatcher $siteMatcher,
        RequestFactory $requestFactory,
        array $languages,
        array $extConf,
    ) {
        parent::__construct($pageRepository, $siteMatcher, $requestFactory, $languages, $extConf);
        $this->completionService = $completionService;
    }

    /**
     * Mirrors the original prompt construction and result shaping
     * (ai-seo-helper 0.7.2, ContentService::requestAi); only the transport
     * is nr-llm. $extConfReplaceText is unused — as in the original.
     *
     * @return array<mixed>
     */
    public function requestAi(string $content, mixed $extConfPromptPrefix, mixed $extConfReplaceText, mixed $languageIsoCode): array
    {
        $prompt = $this->buildPrompt($content, $extConfPromptPrefix, $languageIsoCode);

        $suggestions = $this->completionService->completeJson($prompt, $this->buildOptions());

        if (count($suggestions) > 1) {
            return $suggestions;
        }

        // The original unwraps a single-key envelope like {"suggestions": [...]}.
        $key = array_key_first($suggestions);
        if ($key === null) {
            throw new UnexpectedAiResponseException('nr-llm returned an empty JSON response where ai_seo_helper expects suggestions.', 1755500001);
        }

        $inner = $suggestions[$key];
        if (!is_array($inner)) {
            throw new UnexpectedAiResponseException('nr-llm returned a single scalar where ai_seo_helper expects a list of suggestions.', 1755500002);
        }

        return $inner;
    }

    private function buildPrompt(string $content, mixed $extConfPromptPrefix, mixed $languageIsoCode): string
    {
        $promptKey = is_scalar($extConfPromptPrefix) ? (string)$extConfPromptPrefix : '';
        $languageKey = is_scalar($languageIsoCode) ? (string)$languageIsoCode : '';

        $prefix = $this->stringSetting($this->extConf, $promptKey);
        $language = $this->stringSetting($this->languages, $languageKey);

        return $prefix . ' in ' . $language . ":\n\n" . trim($content)
            . "\n\n Return at least five suggestions and return the response as array in valid JSON format.";
    }

    private function buildOptions(): ChatOptions
    {
        $options = ChatOptions::json();

        // The clamps keep a hand-edited out-of-range extension setting from
        // becoming a ChatOptions constructor exception.
        $temperature = $this->floatSetting($this->extConf, 'openAiTemperature');
        if ($temperature !== null) {
            $options = $options->withTemperature(max(0.0, min(2.0, $temperature)));
        }

        $topP = $this->floatSetting($this->extConf, 'openAiTopP');
        if ($topP !== null) {
            return $options->withTopP(max(0.0, min(1.0, $topP)));
        }

        return $options;
    }

    /**
     * @param array<mixed> $settings
     */
    private function stringSetting(array $settings, string $key): string
    {
        $value = $settings[$key] ?? '';

        return is_scalar($value) ? (string)$value : '';
    }

    /**
     * @param array<mixed> $settings
     */
    private function floatSetting(array $settings, string $key): ?float
    {
        $value = $settings[$key] ?? null;

        return is_numeric($value) ? (float)$value : null;
    }
}
