<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlmCompat\Bridge\NsT3Ai;

use Netresearch\NrLlm\Service\Feature\CompletionServiceInterface;
use Netresearch\NrLlm\Service\Option\ChatOptions;
use Netresearch\NrLlmCompat\Exception\UnexpectedAiResponseException;
use NITSAN\NsT3Ai\Service\NsT3AiContentService as OriginalContentService;
use TYPO3\CMS\Core\Domain\Repository\PageRepository;
use TYPO3\CMS\Core\Http\RequestFactory;
use TYPO3\CMS\Core\Routing\SiteMatcher;
use TYPO3\CMS\Extbase\Mvc\Web\Routing\UriBuilder;

/**
 * Bridge for ns_t3ai's NsT3AiContentService: identical service id, identical
 * behavior up to the provider boundary — both OpenAI-calling methods are
 * overridden and route through nr-llm.
 *
 * Prompt construction stays ORIGINAL code: requestAi() delegates to the
 * parent's protected addModelSpecificPrompt(), so [Content] placeholders and
 * per-request prompt overrides behave exactly as upstream. The extension's
 * model selection is nr-llm's job (its "model" setting only decides which
 * payload key the original helper writes the prompt into). Fail closed: an
 * nr-llm failure propagates, there is no fallback to the original OpenAI
 * call, and the extension's own API key is never read.
 *
 * NOT registered as a DI service (see Services.yaml): the compiler pass
 * swaps this class into ns_t3ai's own service definition, so the original
 * explicit arguments keep applying and autowiring fills the added nr-llm
 * dependency.
 */
final class NsT3AiContentService extends OriginalContentService
{
    /**
     * The RTE dialog's "amount" turns into one nr-llm completion per
     * alternative; the cap bounds cost when a request smuggles a large n.
     */
    private const MAX_RTE_ALTERNATIVES = 10;

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
        UriBuilder $uriBuilder,
        bool $nonLegacyModel,
        array $languages,
        array $extConf,
    ) {
        parent::__construct($pageRepository, $siteMatcher, $requestFactory, $uriBuilder, $nonLegacyModel, $languages, $extConf);
        $this->completionService = $completionService;
    }

    /**
     * Mirrors ns-t3ai 14.0.0, NsT3AiContentService::requestAi — the prompt is
     * built by the ORIGINAL addModelSpecificPrompt(); only the transport is
     * nr-llm, and the replace-text postprocessing is preserved.
     */
    public function requestAi(string $content, mixed $extConfPromptPrefix, mixed $extConfReplaceText = '', mixed $languageIsoCode = '', mixed $parsedBody = []): string
    {
        $jsonContent = [];
        $this->addModelSpecificPrompt(
            $jsonContent,
            $content,
            is_scalar($extConfPromptPrefix) ? (string)$extConfPromptPrefix : '',
            is_scalar($languageIsoCode) ? (string)$languageIsoCode : '',
            is_array($parsedBody) ? $parsedBody : [],
        );
        $prompt = $this->extractPrompt($jsonContent);

        $options = (new ChatOptions())->withCallerSource('ns_t3ai', 'requestAi');
        $text = $this->completionService->complete($prompt, $options)->getText();

        $replaceText = is_scalar($extConfReplaceText) ? (string)$extConfReplaceText : '';

        return ltrim(str_replace($replaceText, '', $text));
    }

    /**
     * Mirrors ns-t3ai 14.0.0, NsT3AiContentService::requestAiForRteContent —
     * the RTE controller consumes choices[*].message.content, so the return
     * value keeps that envelope. Each requested alternative ("n") is one
     * nr-llm completion.
     *
     * @param array<mixed> $jsonContent
     *
     * @return array<mixed>
     */
    public function requestAiForRteContent(array $jsonContent): array
    {
        $promptRaw = $jsonContent['prompt'] ?? '';
        $prompt = is_scalar($promptRaw) ? (string)$promptRaw : '';
        if ($prompt === '') {
            throw new UnexpectedAiResponseException('ns_t3ai sent an RTE generation request without a prompt.', 1755500003);
        }

        $options = $this->buildRteOptions($jsonContent);

        $alternativesRaw = $jsonContent['n'] ?? 1;
        $alternatives = max(1, min(is_numeric($alternativesRaw) ? (int)$alternativesRaw : 1, self::MAX_RTE_ALTERNATIVES));

        $choices = [];
        for ($i = 0; $i < $alternatives; ++$i) {
            $choices[] = [
                'message' => [
                    'content' => $this->completionService->complete($prompt, $options)->getText(),
                ],
            ];
        }

        return ['choices' => $choices];
    }

    /**
     * @param array<mixed> $jsonContent
     */
    private function buildRteOptions(array $jsonContent): ChatOptions
    {
        $options = new ChatOptions();

        // The clamps keep the dialog's raw values from becoming ChatOptions
        // constructor exceptions; the original forwarded them verbatim.
        $temperature = $this->floatValue($jsonContent, 'temperature');
        if ($temperature !== null) {
            $options = $options->withTemperature(max(0.0, min(2.0, $temperature)));
        }

        $topP = $this->floatValue($jsonContent, 'top_p');
        if ($topP !== null) {
            $options = $options->withTopP(max(0.0, min(1.0, $topP)));
        }

        $maxTokens = $this->floatValue($jsonContent, 'max_tokens');
        if ($maxTokens !== null && (int)$maxTokens > 0) {
            $options = $options->withMaxTokens((int)$maxTokens);
        }

        $frequencyPenalty = $this->floatValue($jsonContent, 'frequency_penalty');
        if ($frequencyPenalty !== null) {
            $options = $options->withFrequencyPenalty(max(-2.0, min(2.0, $frequencyPenalty)));
        }

        $presencePenalty = $this->floatValue($jsonContent, 'presence_penalty');
        if ($presencePenalty !== null) {
            $options = $options->withPresencePenalty(max(-2.0, min(2.0, $presencePenalty)));
        }

        // Caller-source attribution (nr-llm ADR-177).
        return $options->withCallerSource('ns_t3ai', 'requestAiForRteContent');
    }

    /**
     * @param array<mixed> $jsonContent
     */
    private function extractPrompt(array $jsonContent): string
    {
        // addModelSpecificPrompt() writes the prompt into messages[0].content
        // or into the legacy prompt key, depending on the configured model.
        $messages = $jsonContent['messages'] ?? null;
        if (is_array($messages) && isset($messages[0]) && is_array($messages[0])) {
            $content = $messages[0]['content'] ?? null;
            if (is_string($content)) {
                return $content;
            }
        }

        $prompt = $jsonContent['prompt'] ?? null;
        if (is_string($prompt)) {
            return $prompt;
        }

        throw new UnexpectedAiResponseException("ns_t3ai's prompt builder produced no prompt to route through nr-llm.", 1755500004);
    }

    /**
     * @param array<mixed> $values
     */
    private function floatValue(array $values, string $key): ?float
    {
        $value = $values[$key] ?? null;

        return is_numeric($value) ? (float)$value : null;
    }
}
