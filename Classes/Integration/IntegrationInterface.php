<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlmCompat\Integration;

use Netresearch\NrLlmCompat\Integration\Contract\ClassContract;
use Netresearch\NrLlmCompat\Integration\Contract\MethodContract;
use Netresearch\NrLlmCompat\Integration\Contract\PropertyContract;

/**
 * Describes one supported third-party AI extension.
 *
 * An integration is pure data: which Composer package it targets, which
 * version range it supports, which PHP contract it relies on, and how it
 * intercepts the extension's provider calls. Whether the integration may
 * actually activate is decided elsewhere (Diagnostics\StatusReporter) from
 * this data — a descriptor never inspects the installation itself.
 *
 * Implementations must be instantiable without constructor arguments: the
 * compiler pass creates them before the DI container exists.
 */
interface IntegrationInterface
{
    /**
     * The Composer package name of the third-party extension (e.g. "passionweb/ai-seo-helper").
     */
    public function getPackageName(): string;

    /**
     * The TYPO3 extension key of the third-party extension (e.g. "ai_seo_helper").
     */
    public function getExtensionKey(): string;

    /**
     * The Composer version constraint this integration supports
     * (e.g. "~0.7.2"). Installed versions outside this range deactivate
     * the integration.
     */
    public function getSupportedVersions(): string;

    public function getStrategy(): IntegrationStrategy;

    /**
     * The nr-llm capabilities this integration routes (e.g. "completion").
     *
     * @return non-empty-list<string>
     */
    public function getCapabilities(): array;

    /**
     * Service replacements applied when the integration is active:
     * the third-party service id (usually its class name) mapped to the
     * bridge class that takes its place. The service id is left untouched,
     * so the third-party extension's own wiring keeps working.
     *
     * @return array<string, class-string>
     */
    public function getServiceReplacements(): array;

    /**
     * Class-level expectations for every class a bridge subclasses: it must
     * exist, must not be final, and its readonly modifier must match the
     * declared expectation (the bridge's own modifier — a mismatch would be
     * an uncatchable fatal when the bridge loads).
     *
     * @return list<ClassContract>
     */
    public function getClassContracts(): array;

    /**
     * The method signatures of the third-party extension this integration
     * relies on. Verified via reflection before every activation: when the
     * installed code no longer matches (an upstream refactoring within a
     * nominally supported version), the integration deactivates instead of
     * fataling in production.
     *
     * @return list<MethodContract>
     */
    public function getMethodContracts(): array;

    /**
     * Properties of the third-party extension the bridge accesses
     * (must exist and not be private).
     *
     * @return list<PropertyContract>
     */
    public function getPropertyContracts(): array;
}
