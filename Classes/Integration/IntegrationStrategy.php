<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlmCompat\Integration;

/**
 * How an integration intercepts the third-party extension's provider calls.
 *
 * ADR-001 designs four strategies (DI class replacement, provider
 * configuration, registry injection, XCLASS); a case is only added here
 * together with the first integration that consumes it.
 */
enum IntegrationStrategy: string
{
    /**
     * The third-party extension's own Symfony service definition gets the
     * bridge class set via Definition::setClass(); the service id stays
     * identical, so all of the extension's consumers receive the bridge
     * without any change to their wiring.
     */
    case DiClassReplacement = 'DI class replacement';
}
