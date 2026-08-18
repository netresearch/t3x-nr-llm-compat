<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlmCompat\Command;

use Netresearch\NrLlmCompat\Integration\Diagnostics\IntegrationState;
use Netresearch\NrLlmCompat\Integration\Diagnostics\StatusReporter;
use Netresearch\NrLlmCompat\Integration\IntegrationRegistry;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Reports the state of every known third-party integration: installed
 * version, contract verification, strategy, and whether it is active.
 *
 * Exit code is non-zero when an integration is ENABLED but cannot activate
 * (not installed, unsupported version, or contract violation) — the
 * administrator asked for interception that is not happening.
 */
final class CompatibilityStatusCommand extends Command
{
    public function __construct(
        private readonly IntegrationRegistry $registry,
        private readonly StatusReporter $reporter,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Third-party AI compatibility');

        $enabledButInactive = 0;

        foreach ($this->registry->all() as $integration) {
            $status = $this->reporter->evaluate($integration);

            $io->section($integration->getExtensionKey());

            $rows = [
                ['package', $integration->getPackageName()],
                ['installed', $status->installedVersion ?? 'no'],
                ['supported', $integration->getSupportedVersions()],
                ['strategy', $integration->getStrategy()->value],
                ['capabilities', implode(', ', $integration->getCapabilities())],
                ['enabled', $status->enabled ? 'yes' : 'no'],
                ['status', $status->state->value],
            ];
            $io->definitionList(...array_map(
                static fn(array $row): array => [$row[0] => $row[1]],
                $rows,
            ));

            foreach ($status->violations as $violation) {
                $io->warning($violation);
            }

            if ($status->enabled && $status->state !== IntegrationState::Active) {
                ++$enabledButInactive;
                $io->error(sprintf(
                    '%s is enabled but not active (%s) — nr-llm does NOT intercept its requests.',
                    $integration->getExtensionKey(),
                    $status->state->value,
                ));
            }
        }

        return $enabledButInactive === 0 ? Command::SUCCESS : Command::FAILURE;
    }
}
