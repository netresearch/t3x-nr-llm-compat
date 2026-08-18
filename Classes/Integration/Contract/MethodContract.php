<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlmCompat\Integration\Contract;

/**
 * One method signature an integration relies on in the third-party code.
 *
 * Verified via reflection: the method must exist, be public, and match the
 * declared parameter count, native parameter types and native return type
 * exactly. `null` stands for "no native type declared" — a parameter or
 * return that gains or loses a native type upstream is a contract change.
 */
final readonly class MethodContract
{
    /**
     * @param class-string      $className
     * @param list<string|null> $parameterTypes native type per parameter, null = untyped
     * @param string|null       $returnType     native return type, null = none declared
     */
    public function __construct(
        public string $className,
        public string $methodName,
        public array $parameterTypes,
        public ?string $returnType,
    ) {}
}
