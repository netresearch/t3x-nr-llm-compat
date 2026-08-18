<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

use Netresearch\NrLlmCompat\Integration\RuntimeConfigurationApplier;

defined('TYPO3') || die();

// Provider-configuration integrations point the third-party extension's
// official hook at the bridge — only for integrations the StatusReporter
// evaluates as Active (installed, supported version, contract verified,
// explicitly enabled).
(new RuntimeConfigurationApplier())->apply();
