<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlmCompat\Integration;

/**
 * Implemented by integrations that have something to tell an administrator
 * beyond the activation state, for example that registered event listeners
 * can make the bridge refuse requests. Printed by `nrllm:compat:status`.
 */
interface ProvidesStatusNotes
{
    /**
     * Whether the notes depend on the registered event listeners; only then
     * does the status command read them (and report when it cannot).
     */
    public function readsEventListeners(): bool;

    /**
     * @param array<mixed> $eventListeners TYPO3's registered event listener definitions, keyed by
     *                                     event class, as `ListenerProvider::getAllListenerDefinitions()`
     *                                     returns them; the listeners themselves are never instantiated
     *
     * @return list<string>
     */
    public function getStatusNotes(array $eventListeners): array;
}
