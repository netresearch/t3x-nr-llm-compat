<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlmCompat\Exception;

use RuntimeException;

/**
 * A listener of a third-party request event changed a part of the request
 * that nr-llm's options cannot express. The bridge refuses the request
 * instead of dropping the change or sending it to the third-party provider.
 */
final class UnmappableRequestChangeException extends RuntimeException {}
