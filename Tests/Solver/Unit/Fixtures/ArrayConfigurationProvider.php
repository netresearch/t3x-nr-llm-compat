<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlmCompat\Tests\Solver\Unit\Fixtures;

use EliasHaeussler\Typo3Solver\Configuration\ConfigurationProvider;

/**
 * Array-backed provider for the solver's Configuration, so bridge unit tests
 * control every setting without a TYPO3 bootstrap.
 */
final readonly class ArrayConfigurationProvider implements ConfigurationProvider
{
    /**
     * @param array<string, mixed> $settings
     */
    public function __construct(
        private array $settings = [],
    ) {}

    public function get(string $configPath, mixed $default = null): mixed
    {
        return $this->settings[$configPath] ?? $default;
    }
}
