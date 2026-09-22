<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlmCompat\Tests\Functional\Fixtures\DataHandler;

use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\SysLog\Action\Database;
use TYPO3\CMS\Core\SysLog\Error;

/**
 * A DataHandler hook that logs an error against every news record the run
 * has just inserted — after the row is in the table and the `NEW…` id is
 * mapped to its uid.
 *
 * It stands in for an installation's own `processDatamap_afterDatabaseOperations`
 * hook that refuses a record it cannot stop: the DataHandler runs that hook
 * once `insertDB()` has returned, and an error it logs there lands in
 * `errorLog` beside a row that exists. A tool reading `errorLog` alone
 * reports a refusal and leaves the row behind; the next attempt creates a
 * duplicate.
 *
 * Registered per test under
 * `$GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['t3lib/class.t3lib_tcemain.php']['processDatamapClass']`
 * and removed again in the test's tearDown. Stateless, so no test leaks into
 * the next.
 */
final class LogsAnErrorAfterTheNewsInsertHook
{
    public const MESSAGE = 'refused after the insert by the fixture hook';

    private const TABLE = 'tx_news_domain_model_news';

    /**
     * @param array<string, mixed> $fieldArray
     */
    public function processDatamap_afterDatabaseOperations(string $status, string $table, string|int $id, array $fieldArray, DataHandler $dataHandler): void
    {
        if ($status !== 'new' || $table !== self::TABLE) {
            return;
        }

        $dataHandler->log(
            $table,
            (int)($dataHandler->substNEWwithIDs[$id] ?? 0),
            Database::INSERT,
            null,
            Error::USER_ERROR,
            self::MESSAGE,
        );
    }
}
