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
 * Verified via reflection: the method must exist, be callable (public, or at
 * least protected when a bridge subclass calls it internally), and match the
 * declared parameter count, native parameter types and native return type
 * exactly. `null` stands for "no native type declared" — a parameter or
 * return that gains or loses a native type upstream is a contract change.
 */
final readonly class MethodContract
{
    /**
     * @param string            $className      class or interface name — possibly absent on
     *                                          this installation, which the verifier reports
     *                                          as a violation
     * @param list<string|null> $parameterTypes native type per parameter, null = untyped
     * @param string|null       $returnType     native return type, null = none declared
     * @param bool              $mustBePublic   false = protected suffices (bridge-internal call);
     *                                          private always violates
     */
    public function __construct(
        public string $className,
        public string $methodName,
        public array $parameterTypes,
        public ?string $returnType,
        public bool $mustBePublic = true,
    ) {}
}
