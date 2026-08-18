<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlmCompat\Integration\Diagnostics;

use Composer\InstalledVersions;
use Composer\Semver\VersionParser;

/**
 * Answers whether a third-party package is installed and whether its
 * installed version satisfies an integration's supported range, from
 * Composer's runtime metadata.
 */
final class VersionVerifier
{
    public function isInstalled(string $packageName): bool
    {
        return InstalledVersions::isInstalled($packageName);
    }

    public function getInstalledVersion(string $packageName): ?string
    {
        if (!InstalledVersions::isInstalled($packageName)) {
            return null;
        }

        return InstalledVersions::getPrettyVersion($packageName);
    }

    public function satisfies(string $packageName, string $constraint): bool
    {
        return InstalledVersions::isInstalled($packageName)
            && InstalledVersions::satisfies(new VersionParser(), $packageName, $constraint);
    }
}
