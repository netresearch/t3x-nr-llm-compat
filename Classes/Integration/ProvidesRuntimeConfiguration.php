<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlmCompat\Integration;

/**
 * Implemented by integrations whose strategy needs a runtime step at boot —
 * typically writing the third-party extension's official provider hook
 * ($GLOBALS / extension-configuration key) so it names the bridge class.
 *
 * Applied by {@see RuntimeConfigurationApplier} from ext_localconf.php,
 * and ONLY when {@see Diagnostics\StatusReporter} evaluates the integration
 * as Active — the same single decision the compiler pass follows.
 */
interface ProvidesRuntimeConfiguration
{
    public function applyRuntimeConfiguration(): void;
}
