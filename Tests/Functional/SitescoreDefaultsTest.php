<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlmCompat\Tests\Functional;

use In2code\Sitescore\Domain\Repository\Llm\GeminiRepository;
use Netresearch\NrLlmCompat\Bridge\Sitescore\NrLlmRepository;
use PHPUnit\Framework\Attributes\Test;

/**
 * Default state: nr_llm_compat installed but the integration NOT enabled —
 * the hook stays unset and sitescore keeps its own default Gemini repository.
 */
final class SitescoreDefaultsTest extends AbstractSitescoreTestCase
{
    protected array $configurationToUseInTestInstance = [
        'EXTENSIONS' => [
            'sitescore' => self::SITESCORE_CONFIGURATION,
        ],
    ];

    #[Test]
    public function sitescoreKeepsItsDefaultGeminiRepository(): void
    {
        $repository = $this->repositoryInjectedIntoAnalysisService();

        self::assertNotInstanceOf(NrLlmRepository::class, $repository);
        self::assertInstanceOf(GeminiRepository::class, $repository);
    }
}
