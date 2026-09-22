<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlmCompat\Tests\Functional;

use Netresearch\NrLlm\Service\Tool\ToolExecutionContext;
use Netresearch\NrLlmCompat\Bridge\News\CreateNewsDraftTool;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;

/**
 * The other direction of the date rule: news configured with
 * `dateTimeNotRequired` on, where a record without a date is what the
 * backend form itself allows.
 */
final class CreateNewsDraftToolDateOptionalTest extends AbstractNewsTestCase
{
    private const TABLE = 'tx_news_domain_model_news';

    protected array $configurationToUseInTestInstance = [
        'EXTENSIONS' => [
            'news'         => ['dateTimeNotRequired' => '1'] + self::NEWS_CONFIGURATION,
            'nr_llm_compat' => [
                'integrations' => [
                    'news' => '1',
                ],
            ],
        ],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/Fixtures/news.csv');

        $factory = $this->get(LanguageServiceFactory::class);
        self::assertInstanceOf(LanguageServiceFactory::class, $factory);
        $GLOBALS['LANG'] = $factory->create('default');
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['LANG']);
        parent::tearDown();
    }

    #[Test]
    public function aRecordWithoutADateIsCreatedWhenTheInstallationDoesNotRequireOne(): void
    {
        $tool = $this->toolFromRegistry('create_news_draft');
        self::assertInstanceOf(CreateNewsDraftTool::class, $tool);
        $admin = $this->setUpBackendUser(1);

        $result = $tool->execute(
            ['pid' => 2, 'title' => 'Undated'],
            ToolExecutionContext::fromBackendUser($admin),
        );

        self::assertFalse($result->isError, $result->content);

        $queryBuilder = $this->getConnectionPool()->getQueryBuilderForTable(self::TABLE);
        $queryBuilder->getRestrictions()->removeAll();
        $rows = $queryBuilder->select('title', 'datetime', 'hidden')->from(self::TABLE)->executeQuery()->fetchAllAssociative();

        self::assertCount(1, $rows);
        self::assertSame('Undated', $rows[0]['title'] ?? null);
        self::assertSame(0, (int)($rows[0]['datetime'] ?? -1));
        self::assertSame(1, (int)($rows[0]['hidden'] ?? 0));
    }
}
