<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

use Netresearch\NrLlmCompat\Tests\Unit\Fixtures\Unloadable\FixtureUnloadableBridge;

// Returns the unloadable fixture's class name as a value PHPStan never sees
// as a literal: reflecting that class is the very failure under test.
return FixtureUnloadableBridge::class;
