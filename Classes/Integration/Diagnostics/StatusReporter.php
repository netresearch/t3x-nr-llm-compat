<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlmCompat\Integration\Diagnostics;

use Netresearch\NrLlmCompat\Integration\IntegrationInterface;

/**
 * Decides the state of an integration on this installation.
 *
 * Single decision point: the compiler pass activates an integration exactly
 * when this reporter says Active, and the status command displays the same
 * evaluation — the diagnosis can never disagree with the interception.
 */
final readonly class StatusReporter
{
    public function __construct(
        private VersionVerifier $versionVerifier = new VersionVerifier(),
        private ContractVerifier $contractVerifier = new ContractVerifier(),
        private IntegrationSettings $settings = new IntegrationSettings(),
    ) {}

    public function evaluate(IntegrationInterface $integration): IntegrationStatus
    {
        $package = $integration->getPackageName();
        $enabled = $this->settings->isEnabled($integration->getExtensionKey());

        if (!$this->versionVerifier->isInstalled($package)) {
            return new IntegrationStatus($integration, IntegrationState::NotInstalled, $enabled, null);
        }

        $installedVersion = $this->versionVerifier->getInstalledVersion($package);

        if (!$this->versionVerifier->satisfies($package, $integration->getSupportedVersions())) {
            return new IntegrationStatus($integration, IntegrationState::UnsupportedVersion, $enabled, $installedVersion);
        }

        $violations = $this->contractVerifier->verify($integration);
        if ($violations !== []) {
            return new IntegrationStatus($integration, IntegrationState::IncompatibleContract, $enabled, $installedVersion, $violations);
        }

        return new IntegrationStatus(
            $integration,
            $enabled ? IntegrationState::Active : IntegrationState::Available,
            $enabled,
            $installedVersion,
        );
    }
}
