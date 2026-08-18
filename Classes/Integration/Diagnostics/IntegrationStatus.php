<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlmCompat\Integration\Diagnostics;

use Netresearch\NrLlmCompat\Integration\IntegrationInterface;

/**
 * Evaluation result for one integration: its state, the installed version
 * of the third-party package (null when not installed), whether the
 * administrator enabled it, and the contract violations (only populated for
 * IncompatibleContract).
 */
final readonly class IntegrationStatus
{
    /**
     * @param list<string> $violations
     */
    public function __construct(
        public IntegrationInterface $integration,
        public IntegrationState $state,
        public bool $enabled,
        public ?string $installedVersion,
        public array $violations = [],
    ) {}
}
