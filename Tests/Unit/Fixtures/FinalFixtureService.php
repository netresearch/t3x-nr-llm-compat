<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlmCompat\Tests\Unit\Fixtures;

/**
 * A third-party class the verifier must reject: final classes cannot be bridged.
 */
final class FinalFixtureService {}
