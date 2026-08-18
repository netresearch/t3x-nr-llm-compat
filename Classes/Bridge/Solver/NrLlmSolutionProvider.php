<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlmCompat\Bridge\Solver;

use EliasHaeussler\Typo3Solver\Configuration\Configuration;
use EliasHaeussler\Typo3Solver\ProblemSolving\Problem\Problem;
use EliasHaeussler\Typo3Solver\ProblemSolving\Solution\Model\CompletionResponse;
use EliasHaeussler\Typo3Solver\ProblemSolving\Solution\Model\Message;
use EliasHaeussler\Typo3Solver\ProblemSolving\Solution\Provider\SolutionProvider;
use EliasHaeussler\Typo3Solver\ProblemSolving\Solution\Solution;
use Netresearch\NrLlm\Service\Feature\CompletionServiceInterface;
use Netresearch\NrLlm\Service\Option\ChatOptions;
use Throwable;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * typo3-solver's OFFICIAL provider hook, pointed at nr-llm: registered as
 * the extension-configuration `provider` class by the SolverIntegration's
 * runtime configuration. No third-party internals are overridden — this
 * class implements the extension's own SolutionProvider interface.
 *
 * The extension's request shaping is preserved: max tokens, temperature
 * (already range-validated by its Configuration) and the configured number
 * of completions (one nr-llm completion per alternative, capped) all come
 * from the extension's own settings; the exception-code ignore list keeps
 * deciding canBeUsed(). The extension's model setting and OpenAI key are
 * nr-llm's job and stay unread. Fail closed: an nr-llm failure propagates —
 * there is no fallback to the openai-php client.
 *
 * listModels() returns an empty list on purpose: the extension's model
 * picker configures ITS model setting, which this provider does not consume
 * — model routing belongs to nr-llm. An invented list would be fake
 * compatibility.
 *
 * NOT registered via Services.yaml resource load (see there): the compiler
 * pass registers it as a public service, which create() resolves — solver
 * instantiates providers via `$class::create()`, not DI. In a failsafe
 * context without a container create() fails, which is the fail-closed
 * behavior an nr-llm-routed provider must have there.
 */
final readonly class NrLlmSolutionProvider implements SolutionProvider
{
    /**
     * The extension's numberOfCompletions turns into one nr-llm completion
     * per alternative; the cap bounds cost for a runaway setting.
     */
    private const MAX_COMPLETIONS = 10;

    public function __construct(
        private CompletionServiceInterface $completionService,
        private Configuration $configuration,
    ) {}

    public static function create(): static
    {
        // The container itself throws when the service is not registered —
        // which only happens when the integration is not Active, and then
        // nothing configures this class as the provider in the first place.
        return GeneralUtility::getContainer()->get(self::class);
    }

    public function getSolution(Problem $problem): Solution
    {
        $options = (new ChatOptions(
            temperature: $this->configuration->getTemperature(),
            maxTokens: max(1, $this->configuration->getMaxTokens()),
        ))->withCallerSource('solver', 'getSolution');

        $completions = max(1, min($this->configuration->getNumberOfCompletions(), self::MAX_COMPLETIONS));

        $responses = [];
        $model = '';
        for ($i = 0; $i < $completions; ++$i) {
            $completion = $this->completionService->complete($problem->getPrompt(), $options);
            $model = $completion->model;
            $responses[] = new CompletionResponse($i, new Message('assistant', $completion->getText()), 'stop');
        }

        return new Solution($responses, $model, $problem->getPrompt());
    }

    public function canBeUsed(Throwable $exception): bool
    {
        // Mirrors the original OpenAISolutionProvider: the administrator's
        // ignore list keeps deciding which exceptions get a solution.
        return !in_array($exception->getCode(), $this->configuration->getIgnoredCodes(), true);
    }

    public function isCacheable(): bool
    {
        return true;
    }

    public function listModels(bool $includeUnsupported = false): array
    {
        return [];
    }
}
