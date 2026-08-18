<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlmCompat\Integration\Diagnostics;

use Netresearch\NrLlmCompat\Integration\Contract\ClassContract;
use Netresearch\NrLlmCompat\Integration\Contract\MethodContract;
use Netresearch\NrLlmCompat\Integration\Contract\PropertyContract;
use Netresearch\NrLlmCompat\Integration\IntegrationInterface;
use ReflectionClass;
use ReflectionIntersectionType;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionProperty;
use ReflectionType;
use ReflectionUnionType;
use Throwable;

/**
 * Verifies via reflection that the installed third-party code still matches
 * the PHP contract an integration was written against.
 *
 * A Composer version range alone cannot catch an upstream refactoring that
 * kept the version "supported" — this check can, and it deactivates the
 * integration instead of letting a signature mismatch fatal in production.
 */
final class ContractVerifier
{
    /**
     * @return list<string> human-readable violations; empty = contract holds
     */
    public function verify(IntegrationInterface $integration): array
    {
        $violations = [];

        foreach ($integration->getClassContracts() as $contract) {
            $violations = [...$violations, ...$this->verifyClass($contract)];
        }

        foreach ($integration->getMethodContracts() as $contract) {
            $violations = [...$violations, ...$this->verifyMethod($contract)];
        }

        foreach ($integration->getPropertyContracts() as $contract) {
            $violations = [...$violations, ...$this->verifyProperty($contract)];
        }

        // Only load the bridges once every declared expectation holds: a
        // readonly-modifier mismatch with the installed parent would be an
        // UNCATCHABLE fatal at class-load time, so the ClassContract check
        // above is the real guard and must run first.
        if ($violations !== []) {
            return $violations;
        }

        foreach ($integration->getServiceReplacements() as $targetClass => $bridgeClass) {
            if (!class_exists($targetClass)) {
                $violations[] = sprintf('class %s does not exist', $targetClass);
                continue;
            }

            // Defense in depth for the load errors that ARE catchable (e.g.
            // an interface the bridge implements is missing): turn "would
            // crash the container" into "integration deactivates".
            try {
                if (!is_subclass_of($bridgeClass, $targetClass)) {
                    $violations[] = sprintf('bridge %s is not a subclass of %s', $bridgeClass, $targetClass);
                }
            } catch (Throwable $loadError) {
                $violations[] = sprintf(
                    'bridge %s cannot be loaded against the installed %s: %s',
                    $bridgeClass,
                    $targetClass,
                    $loadError->getMessage(),
                );
            }
        }

        return $violations;
    }

    /**
     * @return list<string>
     */
    private function verifyClass(ClassContract $contract): array
    {
        if (!class_exists($contract->className)) {
            return [sprintf('class %s does not exist', $contract->className)];
        }

        $reflection = new ReflectionClass($contract->className);

        $violations = [];

        if ($reflection->isFinal()) {
            $violations[] = sprintf('class %s is final and cannot be bridged', $contract->className);
        }

        if ($reflection->isReadOnly() !== $contract->isReadonly) {
            $violations[] = sprintf(
                'class %s is %s but the bridge expects %s — a modifier mismatch would fatal at bridge load time',
                $contract->className,
                $reflection->isReadOnly() ? 'readonly' : 'not readonly',
                $contract->isReadonly ? 'readonly' : 'not readonly',
            );
        }

        return $violations;
    }

    /**
     * @return list<string>
     */
    private function verifyMethod(MethodContract $contract): array
    {
        $subject = $contract->className . '::' . $contract->methodName . '()';

        if (!class_exists($contract->className)) {
            return [sprintf('class %s does not exist', $contract->className)];
        }

        if (!method_exists($contract->className, $contract->methodName)) {
            return [sprintf('%s does not exist', $subject)];
        }

        $method = new ReflectionMethod($contract->className, $contract->methodName);

        $violations = [];

        if ($contract->mustBePublic && !$method->isPublic()) {
            $violations[] = sprintf('%s is not public', $subject);
        } elseif (!$contract->mustBePublic && $method->isPrivate()) {
            $violations[] = sprintf('%s is private and not accessible to a bridge subclass', $subject);
        }

        $expectedCount = count($contract->parameterTypes);
        $actualCount = $method->getNumberOfParameters();
        if ($actualCount !== $expectedCount) {
            $violations[] = sprintf('%s expects %d parameters, found %d', $subject, $expectedCount, $actualCount);

            return $violations;
        }

        foreach ($method->getParameters() as $index => $parameter) {
            $expectedType = $contract->parameterTypes[$index] ?? null;
            $actualType = $this->typeName($parameter->getType());
            if ($actualType !== $expectedType) {
                $violations[] = sprintf(
                    '%s parameter #%d ($%s) is typed "%s", expected "%s"',
                    $subject,
                    $index + 1,
                    $parameter->getName(),
                    $actualType ?? 'untyped',
                    $expectedType ?? 'untyped',
                );
            }
        }

        $actualReturn = $this->typeName($method->getReturnType());
        if ($actualReturn !== $contract->returnType) {
            $violations[] = sprintf(
                '%s returns "%s", expected "%s"',
                $subject,
                $actualReturn ?? 'untyped',
                $contract->returnType ?? 'untyped',
            );
        }

        return $violations;
    }

    /**
     * @return list<string>
     */
    private function verifyProperty(PropertyContract $contract): array
    {
        $subject = $contract->className . '::$' . $contract->propertyName;

        if (!class_exists($contract->className)) {
            return [sprintf('class %s does not exist', $contract->className)];
        }

        if (!property_exists($contract->className, $contract->propertyName)) {
            return [sprintf('%s does not exist', $subject)];
        }

        $property = new ReflectionProperty($contract->className, $contract->propertyName);
        if ($property->isPrivate()) {
            return [sprintf('%s is private and not accessible to a bridge subclass', $subject)];
        }

        return [];
    }

    private function typeName(?ReflectionType $type): ?string
    {
        if (!$type instanceof ReflectionType) {
            return null;
        }

        if ($type instanceof ReflectionNamedType) {
            return $type->getName();
        }

        // Union/intersection types: the contract string must match this
        // rendering exactly (member order as declared).
        if ($type instanceof ReflectionUnionType || $type instanceof ReflectionIntersectionType) {
            $separator = $type instanceof ReflectionUnionType ? '|' : '&';

            return implode($separator, array_map(
                fn(ReflectionType $member): string => $this->typeName($member) ?? 'mixed',
                $type->getTypes(),
            ));
        }

        return 'unknown';
    }
}
