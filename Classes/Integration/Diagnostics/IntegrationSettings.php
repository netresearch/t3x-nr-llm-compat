<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlmCompat\Integration\Diagnostics;

/**
 * Reads the per-integration enable toggles from the extension configuration.
 *
 * Deliberately reads $GLOBALS['TYPO3_CONF_VARS'] directly instead of the
 * ExtensionConfiguration service: the compiler pass consults these toggles
 * at container BUILD time, where no DI service exists yet, and the status
 * command must answer from exactly the same source so both always agree.
 *
 * Note: TYPO3 rebuilds the DI container when the extension configuration is
 * saved through the backend settings module (core cache flush); a toggle
 * edited directly in settings.php requires a manual cache flush to take
 * effect.
 */
final class IntegrationSettings
{
    public function isEnabled(string $extensionKey): bool
    {
        $confVars = $GLOBALS['TYPO3_CONF_VARS'] ?? null;
        if (!is_array($confVars)) {
            return false;
        }

        $extensions = $confVars['EXTENSIONS'] ?? null;
        if (!is_array($extensions)) {
            return false;
        }

        $ownConfiguration = $extensions['nr_llm_compat'] ?? null;
        if (!is_array($ownConfiguration)) {
            return false;
        }

        $integrations = $ownConfiguration['integrations'] ?? null;
        if (!is_array($integrations)) {
            return false;
        }

        $value = $integrations[$extensionKey] ?? false;
        if (!is_scalar($value)) {
            return false;
        }

        // The settings module stores a checkbox as '1' or '0'. A value edited
        // by hand in settings.php may read 'false', 'off' or 'no'; a (bool)
        // cast would turn every such non-empty string into true. Anything
        // that is not a recognisable boolean keeps the integration off.
        return filter_var(trim((string)$value), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? false;
    }
}
