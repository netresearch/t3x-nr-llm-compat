<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlmCompat\Exception;

use RuntimeException;

/**
 * Thrown when an nr-llm response cannot be shaped into what the intercepted
 * third-party extension expects from its original provider call.
 */
final class UnexpectedAiResponseException extends RuntimeException {}
