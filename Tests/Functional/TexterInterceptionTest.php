<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlmCompat\Tests\Functional;

use Netresearch\NrLlmCompat\Bridge\Texter\NrLlmRepository;
use PHPUnit\Framework\Attributes\Test;

/**
 * The integration enabled: texter's official llmRepositoryClass hook points
 * at the bridge, the compiler pass registered it as a public service, and
 * the extension's own factory resolves it into the AJAX controller — with
 * the extension's Gemini API key empty.
 *
 * getText() behavior (prompt prefix, history, fail-closed) is covered by the
 * unit suite against a mocked manager; nr-llm ships no consumer-facing fake
 * for the chat surface yet, so this functional layer proves the wiring.
 */
final class TexterInterceptionTest extends AbstractTexterTestCase
{
    protected array $configurationToUseInTestInstance = [
        'EXTENSIONS' => [
            'texter' => self::TEXTER_CONFIGURATION,
            'nr_llm_compat' => [
                'integrations' => [
                    'texter' => '1',
                ],
            ],
        ],
    ];

    #[Test]
    public function texterResolvesTheBridgeThroughItsOwnHookAndFactory(): void
    {
        self::assertInstanceOf(NrLlmRepository::class, $this->repositoryInjectedIntoAjaxController());
    }
}
