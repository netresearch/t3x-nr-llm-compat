<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlmCompat\Tests\Functional;

use Netresearch\NrLlm\Domain\Model\CompletionResponse;
use Netresearch\NrLlm\Domain\Model\UsageStatistics;
use Netresearch\NrLlm\Service\Feature\CompletionServiceInterface;
use Netresearch\NrLlm\Testing\FakeCompletionService;
use Netresearch\NrLlmCompat\Bridge\Sitescore\NrLlmRepository;
use PHPUnit\Framework\Attributes\Test;

/**
 * The integration enabled: sitescore's official llmRepositoryClass hook points
 * at the bridge, the compiler pass registered it as a public service, and the
 * extension's own factory resolves it into the AnalysisService, so the page
 * analysis runs through nr-llm with sitescore's Gemini API key empty.
 */
final class SitescoreInterceptionTest extends AbstractSitescoreTestCase
{
    protected array $configurationToUseInTestInstance = [
        'EXTENSIONS' => [
            'sitescore'     => self::SITESCORE_CONFIGURATION,
            'nr_llm_compat' => [
                'integrations' => [
                    'sitescore' => '1',
                ],
            ],
        ],
    ];

    #[Test]
    public function sitescoreResolvesTheBridgeThroughItsOwnHookAndFactory(): void
    {
        self::assertInstanceOf(NrLlmRepository::class, $this->repositoryInjectedIntoAnalysisService());
    }

    #[Test]
    public function analyzePageContentRunsThroughNrLlmWithEmptyGeminiKey(): void
    {
        $fake = $this->get(CompletionServiceInterface::class);
        self::assertInstanceOf(FakeCompletionService::class, $fake);
        $analysis = [
            'scores'      => ['geo' => 80, 'performance' => 70, 'semantics' => 90, 'keywords' => 50, 'accessibility' => 85],
            'suggestions' => [['type' => 'info', 'message' => 'Consider adding FAQ schema']],
        ];
        $fake->responses[] = new CompletionResponse(
            "```json\n" . json_encode($analysis) . "\n```",
            'fake-model',
            new UsageStatistics(1, 1, 2),
        );

        $result = $this->repositoryInjectedIntoAnalysisService()
            ->analyzePageContent('<main><h1>Managed TYPO3 hosting</h1></main>', 'Hosting', 'typo3 hosting');

        self::assertSame($analysis, $result);
        self::assertCount(1, $fake->completeCalls);
        self::assertStringContainsString('<main><h1>Managed TYPO3 hosting</h1></main>', $fake->completeCalls[0]['prompt']);
        self::assertStringContainsString('**Target Keyword**: typo3 hosting', $fake->completeCalls[0]['prompt']);
    }
}
