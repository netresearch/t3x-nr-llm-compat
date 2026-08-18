<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlmCompat\Tests\Unit\Fixtures;

/**
 * A readonly third-party class, for verifying that a bridge whose modifier
 * does not match deactivates the integration instead of crashing.
 */
readonly class FixtureReadonlyService {}
