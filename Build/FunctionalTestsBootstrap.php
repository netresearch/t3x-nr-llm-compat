<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

/**
 * Bootstrap for functional tests: sets up the TYPO3 testing environment.
 */
if (!getenv('TYPO3_PATH_WEB')) {
    putenv('TYPO3_PATH_WEB=' . dirname(__DIR__));
}

require_once dirname(__DIR__) . '/.Build/vendor/autoload.php';

(static function (): void {
    $testbase = new \TYPO3\TestingFramework\Core\Testbase();
    $testbase->defineOriginalRootPath();
    $testbase->createDirectory(ORIGINAL_ROOT . 'typo3temp/var/tests');
    $testbase->createDirectory(ORIGINAL_ROOT . 'typo3temp/var/transient');
})();
