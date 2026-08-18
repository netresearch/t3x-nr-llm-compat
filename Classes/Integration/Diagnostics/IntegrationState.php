<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlmCompat\Integration\Diagnostics;

/**
 * The evaluated state of one integration on this installation.
 *
 * Only Active integrations intercept anything; every other state means the
 * third-party extension behaves as if nr_llm_compat were not installed.
 */
enum IntegrationState: string
{
    case NotInstalled = 'NOT INSTALLED';
    case UnsupportedVersion = 'UNSUPPORTED VERSION';
    case IncompatibleContract = 'INCOMPATIBLE';
    case Available = 'AVAILABLE';
    case Active = 'ACTIVE';
}
