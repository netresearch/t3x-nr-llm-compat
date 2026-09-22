<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlmCompat\Tests\Functional;

use Netresearch\NrLlmCompat\Bridge\News\CreateNewsDraftTool;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;

/**
 * The integration enabled: the compiler pass registered the tool under the
 * `nr_llm.tool` tag and nr-llm's registry hands it out by its spec name.
 */
final class NewsInterceptionTest extends AbstractNewsTestCase
{
    protected array $configurationToUseInTestInstance = [
        'EXTENSIONS' => [
            'news'         => self::NEWS_CONFIGURATION,
            'nr_llm_compat' => [
                'integrations' => [
                    'news' => '1',
                ],
            ],
        ],
    ];

    #[Test]
    public function nrLlmsRegistryHandsOutTheNewsTool(): void
    {
        self::assertInstanceOf(CreateNewsDraftTool::class, $this->toolFromRegistry('create_news_draft'));
    }

    /**
     * The editor-action labels are what nr-llm's catalogue renders; a
     * malformed XLF resolves to the key itself, which no other gate catches.
     */
    #[Test]
    public function theEditorActionLabelsResolveInEnglishAndGerman(): void
    {
        $tool = $this->toolFromRegistry('create_news_draft');
        self::assertInstanceOf(CreateNewsDraftTool::class, $tool);
        $action = $tool->getEditorAction();

        $factory = $this->get(LanguageServiceFactory::class);
        self::assertInstanceOf(LanguageServiceFactory::class, $factory);

        $english = $factory->create('default');
        self::assertSame('Create news draft', $english->sL($action->labelKey));
        self::assertStringContainsString('hidden news article', $english->sL($action->descriptionKey));

        $german = $factory->create('de');
        self::assertSame('News-Artikel als Entwurf anlegen', $german->sL($action->labelKey));
        self::assertStringContainsString('versteckten News-Artikel', $german->sL($action->descriptionKey));
    }
}
