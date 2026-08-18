<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlmCompat\Integration;

use Netresearch\NrLlmCompat\Integration\Diagnostics\IntegrationState;
use Netresearch\NrLlmCompat\Integration\Diagnostics\StatusReporter;

/**
 * Boot-time twin of the compiler pass, for the strategies that configure a
 * third-party extension's official hook instead of replacing a service.
 *
 * Runs from ext_localconf.php on every request; the evaluation is cheap
 * (Composer runtime metadata plus a handful of reflections) and follows the
 * same single decision point as the pass, so runtime configuration and DI
 * interception can never disagree.
 */
final readonly class RuntimeConfigurationApplier
{
    private IntegrationRegistry $registry;

    private StatusReporter $reporter;

    public function __construct(?IntegrationRegistry $registry = null, ?StatusReporter $reporter = null)
    {
        $this->registry = $registry ?? IntegrationRegistry::withDefaultIntegrations();
        $this->reporter = $reporter ?? new StatusReporter();
    }

    public function apply(): void
    {
        foreach ($this->registry->all() as $integration) {
            if (!$integration instanceof ProvidesRuntimeConfiguration) {
                continue;
            }

            if ($this->reporter->evaluate($integration)->state !== IntegrationState::Active) {
                continue;
            }

            $integration->applyRuntimeConfiguration();
        }
    }
}
