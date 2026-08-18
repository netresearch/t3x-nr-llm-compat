<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlmCompat\Integration;

/**
 * The list of integrations this extension ships.
 *
 * withDefaultIntegrations() is the single source of that list: the DI
 * container builds the registry through it (Services.yaml factory) and the
 * compiler pass calls it directly, because the pass runs before the
 * container exists.
 */
final readonly class IntegrationRegistry
{
    /** @var list<IntegrationInterface> */
    private array $integrations;

    public function __construct(IntegrationInterface ...$integrations)
    {
        $this->integrations = array_values($integrations);
    }

    public static function withDefaultIntegrations(): self
    {
        return new self(
            new AiSeoHelperIntegration(),
            new NsT3AiIntegration(),
            new AiFilemetadataIntegration(),
            new TexterIntegration(),
            new SolverIntegration(),
        );
    }

    /**
     * @return list<IntegrationInterface>
     */
    public function all(): array
    {
        return $this->integrations;
    }
}
