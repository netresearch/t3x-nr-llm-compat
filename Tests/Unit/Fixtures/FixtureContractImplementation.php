<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlmCompat\Tests\Unit\Fixtures;

/**
 * Stands in for a bridge implementing a third-party provider interface.
 */
final class FixtureContractImplementation implements FixtureContractInterface
{
    public function provide(string $input): string
    {
        return $input;
    }
}
