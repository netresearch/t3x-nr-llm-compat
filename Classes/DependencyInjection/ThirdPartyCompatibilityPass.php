<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlmCompat\DependencyInjection;

use Netresearch\NrLlmCompat\Integration\Diagnostics\IntegrationState;
use Netresearch\NrLlmCompat\Integration\Diagnostics\StatusReporter;
use Netresearch\NrLlmCompat\Integration\IntegrationInterface;
use Netresearch\NrLlmCompat\Integration\IntegrationRegistry;
use Netresearch\NrLlmCompat\Integration\IntegrationStrategy;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Applies every Active integration to the container.
 *
 * Anything short of Active (not installed, unsupported version, contract
 * violation, not enabled) is skipped silently here — the status command is
 * the diagnostic surface and reports the reason from the same evaluation.
 */
final readonly class ThirdPartyCompatibilityPass implements CompilerPassInterface
{
    private IntegrationRegistry $registry;

    private StatusReporter $reporter;

    public function __construct(?IntegrationRegistry $registry = null, ?StatusReporter $reporter = null)
    {
        $this->registry = $registry ?? IntegrationRegistry::withDefaultIntegrations();
        $this->reporter = $reporter ?? new StatusReporter();
    }

    public function process(ContainerBuilder $container): void
    {
        foreach ($this->registry->all() as $integration) {
            if ($this->reporter->evaluate($integration)->state !== IntegrationState::Active) {
                continue;
            }

            match ($integration->getStrategy()) {
                IntegrationStrategy::DiClassReplacement => $this->replaceServiceClasses($container, $integration),
            };
        }
    }

    private function replaceServiceClasses(ContainerBuilder $container, IntegrationInterface $integration): void
    {
        foreach ($integration->getServiceReplacements() as $serviceId => $bridgeClass) {
            if (!$container->hasDefinition($serviceId)) {
                // The third-party extension did not register its service in
                // this container build (e.g. a partial context); nothing to
                // intercept, and nothing must break.
                continue;
            }

            // Same service id, different class: every consumer of the
            // third-party service receives the bridge, with the original
            // definition's explicit arguments still applying and autowiring
            // filling the bridge's additional nr-llm dependencies.
            $container->getDefinition($serviceId)->setClass($bridgeClass);
        }
    }
}
