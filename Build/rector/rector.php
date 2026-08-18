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
use Ssch\TYPO3Rector\Set\Typo3LevelSetList;
use Ssch\TYPO3Rector\Set\Typo3SetList;

$configure = require_once __DIR__ . '/../../.Build/vendor/netresearch/typo3-ci-workflows/config/rector/rector.php';

return static function (RectorConfig $rectorConfig) use ($configure): void {
    // Shared org base config: code-quality sets, rule skips, phpstan-rector.neon
    $configure($rectorConfig, __DIR__ . '/../..');

    // paths() replaces the shared list — re-declared to keep Tests/ in scope,
    // which the shared $projectRoot default leaves out.
    $rectorConfig->paths(array_merge(
        [
            __DIR__ . '/../../Classes',
            __DIR__ . '/../../Configuration',
            __DIR__ . '/../../Tests',
        ],
        glob(__DIR__ . '/../../ext_*.php') ?: [],
    ));

    $rectorConfig->sets([
        // Reads the PHPUnit version composer actually installed; the rector
        // CI job is pinned to PHP 8.2 so the rules match the phpunit ^11
        // that resolves there.
        PHPUnitSetList::COMPOSER_BASED,

        Typo3SetList::CODE_QUALITY,
        Typo3SetList::GENERAL,

        Typo3LevelSetList::UP_TO_TYPO3_13,
    ]);

    // Not part of the shared TYPE_DECLARATION set
    $rectorConfig->rules([
        AddVoidReturnTypeWhereNoReturnRector::class,
    ]);
};
