<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlmCompat\Integration\Contract;

/**
 * One property an integration's bridge accesses in the third-party code.
 *
 * Verified via reflection: the property must exist and must not be private
 * (bridges extend the third-party class and read its state).
 */
final readonly class PropertyContract
{
    /**
     * @param class-string $className
     */
    public function __construct(
        public string $className,
        public string $propertyName,
    ) {}
}
