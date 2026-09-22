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

    /**
     * The third-party extension exposes an OFFICIAL hook for a custom
     * provider/repository class (a $GLOBALS or extension-configuration key
     * naming a class that must implement its interface). No internals are
     * touched: the compiler pass registers the bridge as a public service,
     * and the runtime configuration (set at boot when the integration is
     * Active) points the hook at it.
     */
    case ProviderConfiguration = 'provider configuration';

    /**
     * The third-party extension has no nr-llm writer of its own, so this
     * layer ships one: a tool class implementing nr-llm's public tool
     * contract, registered under the `nr_llm.tool` tag by the compiler pass
     * exactly when the integration is Active. Nothing of the third-party
     * extension is replaced or hooked — its records are written the way its
     * own backend forms write them, through the DataHandler. Not one of the
     * four ADR-001 interception strategies; the case is recorded in ADR-002.
     */
    case ToolProvision = 'tool provision';
}
