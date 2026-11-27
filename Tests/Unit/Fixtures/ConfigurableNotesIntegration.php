<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlmCompat\Tests\Unit\Fixtures;

use Netresearch\NrLlmCompat\Integration\IntegrationInterface;
use Netresearch\NrLlmCompat\Integration\IntegrationStrategy;
use Netresearch\NrLlmCompat\Integration\ProvidesStatusNotes;

/**
 * Status-notes fixture without contracts, so it is Active whenever its
 * package is installed and its toggle is on. Records the listener
 * definitions the status command hands it.
 */
final class ConfigurableNotesIntegration implements IntegrationInterface, ProvidesStatusNotes
{
    /** @var list<array<mixed>> */
    public array $receivedListeners = [];

    /**
     * @param list<string> $notes
     */
    public function __construct(
        private readonly string $packageName,
        private readonly bool $readsEventListeners,
        private readonly array $notes = ['A static note.'],
    ) {}

    public function readsEventListeners(): bool
    {
        return $this->readsEventListeners;
    }

    public function getStatusNotes(array $eventListeners): array
    {
        $this->receivedListeners[] = $eventListeners;

        return $this->notes;
    }

    public function getPackageName(): string
    {
        return $this->packageName;
    }

    public function getExtensionKey(): string
    {
        return 'fixture_extension';
    }

    public function getSupportedVersions(): string
    {
        return '*';
    }

    public function getStrategy(): IntegrationStrategy
    {
        return IntegrationStrategy::DiClassReplacement;
    }

    public function getCapabilities(): array
    {
        return ['completion'];
    }

    public function getServiceReplacements(): array
    {
        return [FixtureService::class => FixtureBridge::class];
    }

    public function getClassContracts(): array
    {
        return [];
    }

    public function getMethodContracts(): array
    {
        return [];
    }

    public function getPropertyContracts(): array
    {
        return [];
    }
}
