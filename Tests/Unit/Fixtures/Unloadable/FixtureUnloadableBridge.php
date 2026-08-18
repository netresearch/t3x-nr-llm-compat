<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlmCompat\Tests\Unit\Fixtures\Unloadable;

use Netresearch\NrLlmCompat\Tests\Unit\Fixtures\FixtureService;

/**
 * DELIBERATELY UNLOADABLE: the interface does not exist, so autoloading this
 * class throws a catchable Error. Verifies that ContractVerifier reports a
 * bridge-load failure as a violation instead of crashing the container.
 *
 * This directory is excluded from PHPStan (Build/phpstan/phpstan.neon) —
 * static analysis would rightly reject the missing interface.
 *
 * @phpstan-ignore-next-line
 */
class FixtureUnloadableBridge extends FixtureService implements ThisInterfaceDoesNotExist {}
