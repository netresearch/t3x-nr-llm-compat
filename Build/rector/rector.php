<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

/**
 * Rector Configuration — nr-llm-compat.
 *
 * The rule baseline (PHP/code-quality sets, common skips, importNames,
 * phpVersion and the Rector-specific PHPStan config) comes from the shared
 * org config in netresearch/typo3-ci-workflows. Only extension-specific
 * additions live here.
 */

use Rector\Config\RectorConfig;
use Rector\PHPUnit\Set\PHPUnitSetList;
use Rector\TypeDeclaration\Rector\ClassMethod\AddVoidReturnTypeWhereNoReturnRector;
use Ssch\TYPO3Rector\Set\Typo3SetList;

$configure = require_once __DIR__ . '/../../.Build/vendor/netresearch/typo3-ci-workflows/config/rector/rector.php';

return static function (RectorConfig $rectorConfig) use ($configure): void {
    // Shared org base config: paths (with Tests/), code-quality sets, rule
    // skips, the package's ergebnis-free phpstan-rector.neon, and the PHP and
    // TYPO3 level sets for the lowest supported versions (composer.json:
    // php ^8.2, typo3/cms-core ^13.4 || ^14.3).
    $configure($rectorConfig, __DIR__ . '/../..', [
        'tests' => true,
        'php'   => '8.2',
        'typo3' => 13,
    ]);

    $rectorConfig->sets([
        // Reads the PHPUnit version composer actually installed; the rector
        // CI job is pinned to PHP 8.2 so the rules match the phpunit ^11
        // that resolves there.
        PHPUnitSetList::COMPOSER_BASED,

        Typo3SetList::CODE_QUALITY,
        Typo3SetList::GENERAL,
    ]);

    // Not part of the shared TYPE_DECLARATION set
    $rectorConfig->rules([
        AddVoidReturnTypeWhereNoReturnRector::class,
    ]);

    $rectorConfig->skip([
        // The solver integration references eliashaeussler/typo3-solver, which
        // cannot live in this environment's require-dev (openai-php/client
        // conflict with ai-filemetadata, #8); Rector's PHPStan would reject
        // the unknown classes. Covered by Tests/SolverEnvironment/ instead.
        __DIR__ . '/../../Classes/Bridge/Solver',
        __DIR__ . '/../../Classes/Integration/SolverIntegration.php',
        __DIR__ . '/../../Tests/Solver',
        __DIR__ . '/../../Tests/SolverEnvironment',
    ]);
};
