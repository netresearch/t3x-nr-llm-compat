<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlmCompat\Integration\Contract;

/**
 * Class-level expectations for a third-party class a bridge subclasses.
 *
 * The readonly flag matters because a modifier mismatch between bridge and
 * installed parent is an UNCATCHABLE PHP fatal at class-load time — it must
 * be detected here, before anything ever autoloads the bridge.
 */
final readonly class ClassContract
{
    /**
     * @param class-string $className
     */
    public function __construct(
        public string $className,
        public bool $isReadonly,
    ) {}
}
