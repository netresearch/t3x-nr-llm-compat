<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlmCompat\Tests\Functional;

use In2code\Texter\Domain\Repository\Llm\GeminiRepository;
use Netresearch\NrLlmCompat\Bridge\Texter\NrLlmRepository;
use PHPUnit\Framework\Attributes\Test;

/**
 * Default state: nr_llm_compat installed but the integration NOT enabled —
 * the hook stays unset and texter keeps its own default Gemini repository.
 */
final class TexterDefaultsTest extends AbstractTexterTestCase
{
    protected array $configurationToUseInTestInstance = [
        'EXTENSIONS' => [
            'texter' => self::TEXTER_CONFIGURATION,
        ],
    ];

    #[Test]
    public function texterKeepsItsDefaultGeminiRepository(): void
    {
        $repository = $this->repositoryInjectedIntoAjaxController();

        self::assertNotInstanceOf(NrLlmRepository::class, $repository);
        self::assertInstanceOf(GeminiRepository::class, $repository);
    }
}
