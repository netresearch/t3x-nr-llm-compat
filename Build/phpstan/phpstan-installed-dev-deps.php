<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

/*
 * PHPStan entry point of the ci:test:php:phpstan composer script.
 *
 * Some matrix cells remove an optional dev dependency (remove-dev-deps in
 * .github/workflows/ci.yml): passionweb/ai-seo-helper caps at TYPO3 ^13.4,
 * georgringer/news caps at PHP < 8.6. The files that reference an absent
 * package's classes cannot be analyzed without it; they are excluded here
 * and stay covered by the cells that install the package, which analyze
 * the full Build/phpstan/phpstan.neon.
 */

$root = dirname(__DIR__, 2);

$filesPerOptionalPackage = [
    'passionweb/ai-seo-helper' => [
        'Classes/Bridge/AiSeoHelper/*',
        'Classes/Integration/AiSeoHelperIntegration.php',
        'Tests/Unit/Bridge/AiSeoHelper/*',
        'Tests/Unit/Integration/AiSeoHelperIntegrationTest.php',
        'Tests/Functional/AbstractAiSeoHelperTestCase.php',
        'Tests/Functional/AiSeoHelperInterceptionTest.php',
        'Tests/Functional/AiSeoHelperDefaultsTest.php',
    ],
    'georgringer/news' => [
        'Classes/Bridge/News/CreateNewsDraftTool.php',
        'Classes/Integration/NewsIntegration.php',
    ],
];

$excludePaths = [];
foreach ($filesPerOptionalPackage as $package => $files) {
    if (is_dir($root . '/.Build/vendor/' . $package)) {
        continue;
    }
    foreach ($files as $file) {
        $excludePaths[] = $root . '/' . $file;
    }
}

return [
    'includes'   => [__DIR__ . '/phpstan.neon'],
    'parameters' => ['excludePaths' => ['analyseAndScan' => $excludePaths]],
];
