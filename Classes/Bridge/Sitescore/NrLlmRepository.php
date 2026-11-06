<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlmCompat\Bridge\Sitescore;

use In2code\Sitescore\Domain\Repository\Llm\AbstractRepository;
use In2code\Sitescore\Domain\Repository\Llm\RepositoryInterface;
use Netresearch\NrLlm\Service\Feature\CompletionServiceInterface;
use Netresearch\NrLlm\Service\Option\ChatOptions;
use Netresearch\NrLlmCompat\Exception\UnexpectedAiResponseException;
use TYPO3\CMS\Core\Http\RequestFactory;

/**
 * sitescore's OFFICIAL custom-repository hook, pointed at nr-llm: registered as
 * $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['sitescore']['llmRepositoryClass']
 * by the SitescoreIntegration's runtime configuration. No third-party
 * internals are overridden — this class implements the extension's own
 * RepositoryInterface, exactly as sitescore's README documents for custom
 * providers.
 *
 * Behavior mirrors the shipped GeminiRepository (sitescore 3.1.0) up to the
 * provider boundary: the prompt — page HTML, title and target keyword,
 * including the "no keyword" instruction — is built by the ORIGINAL protected
 * AbstractRepository::getPrompt(), the same system instruction and output
 * budget are sent, and the answer is reduced to the {scores, suggestions}
 * document the GeminiRepository returns to sitescore's AnalysisService.
 * Gemini's provider-specific thinkingConfig has no nr-llm equivalent and is
 * dropped; model and temperature are nr-llm's job.
 *
 * Fail closed: an nr-llm failure propagates, an answer without scores and
 * suggestions raises UnexpectedAiResponseException, there is no fallback to
 * Gemini, and sitescore's own API key is never read.
 *
 * Registered as a PUBLIC service by the compiler pass (sitescore's factory
 * resolves the configured class via $container->get()).
 */
final class NrLlmRepository extends AbstractRepository implements RepositoryInterface
{
    /** GeminiRepository::getOptions() (sitescore 3.1.0), systemInstruction. */
    private const SYSTEM_PROMPT = 'You are an expert SEO consultant and content quality analyst. Focus on qualitative assessment of web content: evaluate SEO strategy, content structure, schema.org implementation, keyword optimization quality, accessibility best practices, and performance opportunities. Do NOT count HTML elements - technical checks are handled separately. Provide actionable, high-value insights about content quality and optimization.';

    /** GeminiRepository::getOptions() (sitescore 3.1.0), maxOutputTokens. */
    private const MAX_TOKENS = 4096;

    private readonly CompletionServiceInterface $completionService;

    public function __construct(
        CompletionServiceInterface $completionService,
        RequestFactory $requestFactory,
    ) {
        parent::__construct($requestFactory);
        $this->completionService = $completionService;
    }

    /**
     * nr-llm manages provider credentials; there is no extension-local key
     * to check. Deliberately empty — as the interface allows.
     */
    public function checkApiKey(): void {}

    public function getApiUrl(): string
    {
        // No fixed endpoint: nr-llm routes per configuration. The interface
        // demands a string; sitescore reads it only in the HTTP transport this
        // class replaces.
        return 'nr-llm';
    }

    /**
     * The interface requires it; sitescore calls it only from the original
     * generateAnalysis() transport, which this class never reaches. Returns
     * the provider-neutral request without any credential.
     *
     * @return array{prompt: string, systemPrompt: string, maxTokens: int}
     */
    public function getOptions(string $html, string $pageTitle, string $keyword): array
    {
        return [
            'prompt'       => $this->getPrompt($html, $pageTitle, $keyword),
            'systemPrompt' => self::SYSTEM_PROMPT,
            'maxTokens'    => self::MAX_TOKENS,
        ];
    }

    /**
     * Mirrors sitescore 3.1.0, AbstractRepository::analyzePageContent() plus
     * GeminiRepository::parseResponse() — same prompt, same result shape;
     * only the transport is nr-llm.
     *
     * @return array{scores: array<mixed>, suggestions: array<mixed>}
     */
    public function analyzePageContent(string $html, string $pageTitle, string $keyword): array
    {
        $this->checkApiKey();

        $options = ChatOptions::json()
            ->withSystemPrompt(self::SYSTEM_PROMPT)
            ->withMaxTokens(self::MAX_TOKENS)
            ->withCallerSource('sitescore', 'analyzePageContent');

        $answer = $this->completionService->complete($this->getPrompt($html, $pageTitle, $keyword), $options)->content;

        return $this->parseAnswer($answer);
    }

    /**
     * The prompt shows the expected answer inside a ```json fence, and not
     * every provider nr-llm routes to honours JSON mode — so, like the
     * GeminiRepository, the JSON object is taken from inside any fence or
     * surrounding prose before decoding.
     *
     * @return array{scores: array<mixed>, suggestions: array<mixed>}
     */
    private function parseAnswer(string $answer): array
    {
        $start = strpos($answer, '{');
        $end   = strrpos($answer, '}');
        $data  = $start !== false && $end !== false && $end > $start
            ? json_decode(substr($answer, $start, $end - $start + 1), true)
            : null;

        if (!is_array($data) || !is_array($data['scores'] ?? null) || !is_array($data['suggestions'] ?? null)) {
            throw new UnexpectedAiResponseException('nr-llm returned no JSON object with "scores" and "suggestions", which sitescore needs to store a page analysis.', 1755500005);
        }

        return ['scores' => $data['scores'], 'suggestions' => $data['suggestions']];
    }
}
