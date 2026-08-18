<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

use Netresearch\NrLlmCompat\DependencyInjection\ThirdPartyCompatibilityPass;
use Symfony\Component\DependencyInjection\Compiler\PassConfig;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

return static function (ContainerConfigurator $containerConfigurator, ContainerBuilder $containerBuilder): void {
    // Runs before optimization so the swapped class is what autowiring
    // resolves and what private-service inlining copies into consumers.
    $containerBuilder->addCompilerPass(
        new ThirdPartyCompatibilityPass(),
        PassConfig::TYPE_BEFORE_OPTIMIZATION,
    );
};
