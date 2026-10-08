<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlmCompat\Tests\Functional;

use Netresearch\NrLlm\Domain\Enum\WriteKind;
use Netresearch\NrLlm\Service\Tool\ToolExecutionContext;
use Netresearch\NrLlmCompat\Bridge\News\CreateNewsDraftTool;
use Netresearch\NrLlmCompat\Tests\Functional\Fixtures\DataHandler\LogsAnErrorAfterTheNewsInsertHook;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;

/**
 * The write path of the news draft tool against a real database, the real
 * DataHandler and the real news TCA, with the tool taken from nr-llm's
 * registry exactly as the agent loop would take it.
 *
 * The assertion this file exists for is that the record is HIDDEN. The
 * news TCA makes `hidden` an exclude field with default 0, so a non-admin
 * without the field grant would get a VISIBLE article on a stock
 * installation; the read-back and the deletion are the control.
 */
final class CreateNewsDraftToolTest extends AbstractNewsTestCase
{
    private const TABLE = 'tx_news_domain_model_news';

    /** A storage folder every backend user may edit content in. */
    private const FOLDER_OPEN = 2;

    /**
     * A storage folder everybody may SEE but only the admin may edit content
     * in: the refusal must come from the content permission, not from the
     * folder being invisible.
     */
    private const FOLDER_CLOSED = 3;

    /** A standard page, which news would accept but this tool does not. */
    private const STANDARD_PAGE = 4;

    /**
     * A storage folder everybody may edit content in, outside the editor's
     * web mount (a second root): the refusal can only come from the mount.
     */
    private const FOLDER_UNMOUNTED = 5;

    private const DATETIME = '2026-09-22T10:00:00+02:00';

    private const TIMESTAMP = 1790064000;

    protected array $configurationToUseInTestInstance = [
        'EXTENSIONS' => [
            'news'         => self::NEWS_CONFIGURATION,
            'nr_llm_compat' => [
                'integrations' => [
                    'news' => '1',
                ],
            ],
        ],
        // A non-UTC server: TYPO3 13.4 shifts an ISO date-time string by the
        // server offset before storing it, which the integer timestamp the
        // tool hands over avoids. On a UTC server that difference is zero
        // and the assertion below could not tell the two apart.
        'SYS' => [
            'phpTimeZone' => 'Europe/Berlin',
        ],
    ];

    private CreateNewsDraftTool $tool;

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/Fixtures/news.csv');

        $factory = $this->get(LanguageServiceFactory::class);
        self::assertInstanceOf(LanguageServiceFactory::class, $factory);
        $GLOBALS['LANG'] = $factory->create('default');

        $tool = $this->toolFromRegistry('create_news_draft');
        self::assertInstanceOf(CreateNewsDraftTool::class, $tool);
        $this->tool = $tool;
    }

    protected function tearDown(): void
    {
        $this->unregisterDataHandlerHook('processDatamapClass', LogsAnErrorAfterTheNewsInsertHook::class);
        unset($GLOBALS['LANG']);
        parent::tearDown();
    }

    #[Test]
    public function theRecordIsCreatedHiddenWithEveryFieldAndAGeneratedSlug(): void
    {
        $admin = $this->setUpBackendUser(1);

        $result = $this->tool->execute(
            [
                'pid'      => self::FOLDER_OPEN,
                'title'    => 'Drafted article',
                'teaser'   => 'A short summary.',
                'bodytext' => '<p>The whole story.</p>',
                'datetime' => self::DATETIME,
                'author'   => 'Jane Doe',
            ],
            ToolExecutionContext::fromBackendUser($admin),
        );

        self::assertFalse($result->isError, $result->content);
        self::assertStringContainsString('Created hidden news draft', $result->content);
        self::assertStringContainsString('not published until a human unhides it', $result->content);

        $row = $this->createdRecord();
        self::assertSame(1, (int)($row['hidden'] ?? 0), 'a drafted article must never be visible');
        self::assertSame(self::FOLDER_OPEN, (int)($row['pid'] ?? 0));
        self::assertSame('Drafted article', $row['title'] ?? null);
        self::assertSame('A short summary.', $row['teaser'] ?? null);
        self::assertSame('<p>The whole story.</p>', $row['bodytext'] ?? null);
        self::assertSame(self::TIMESTAMP, (int)($row['datetime'] ?? 0), 'the timestamp must be stored unshifted');
        self::assertSame('Jane Doe', $row['author'] ?? null);
        self::assertSame(0, (int)($row['type'] ?? -1), 'only an article is ever created');
        self::assertSame(0, (int)($row['sys_language_uid'] ?? -1));

        $slug = $row['path_segment'] ?? '';
        self::assertIsString($slug);
        self::assertStringContainsString('drafted-article', $slug, 'the DataHandler must have filled the slug nobody passed');
        self::assertStringContainsString($slug, $result->content);

        // The write target nr-llm records against the run.
        self::assertSame(WriteKind::CREATED, $result->writeKind);
        self::assertNotNull($result->writeTarget);
        self::assertSame(self::TABLE, $result->writeTarget->table);
        self::assertSame((int)$row['uid'], $result->writeTarget->uid);
    }

    /**
     * `bodytext` is an RTE field (`enableRichtext` in the news TCA), so the
     * DataHandler stores it through RteHtmlParser::transformTextForPersistence,
     * which joins block elements with a line feed. A byte-for-byte read-back
     * of the argument would find the stored text "different" and delete every
     * article with more than one paragraph, heading or list. The read-back
     * therefore compares exclude fields only; `bodytext` is not one, so it
     * cannot be dropped for a missing grant, and the stored text is checked
     * here block by block instead.
     */
    #[Test]
    public function anArticleWithSeveralBlockElementsIsCreatedWithEveryBlockStored(): void
    {
        $admin = $this->setUpBackendUser(1);

        $result = $this->tool->execute(
            [
                'pid'      => self::FOLDER_OPEN,
                'title'    => 'Two paragraphs',
                'bodytext' => '<h2>Head</h2><p>Text with <a href="https://example.com/">a link</a> and <b>bold</b>.</p>'
                    . '<ul><li>one</li><li>two</li></ul>',
                'datetime' => self::DATETIME,
            ],
            ToolExecutionContext::fromBackendUser($admin),
        );

        self::assertFalse($result->isError, $result->content);

        $row  = $this->createdRecord();
        $body = $row['bodytext'] ?? '';
        self::assertIsString($body);
        self::assertStringContainsString('<h2>Head</h2>', $body);
        self::assertStringContainsString('<a href="https://example.com/">a link</a>', $body);
        self::assertStringContainsString('<li>two</li>', $body);
        self::assertSame(1, (int)($row['hidden'] ?? 0));
        self::assertSame(0, (int)($row['deleted'] ?? 1), 'the record must not have been taken back');
    }

    /**
     * EXT:news has no og:description column: its Opengraph partial prints the
     * record's `description` as both the meta description and og:description
     * (and falls back to the teaser when it is empty).
     */
    #[Test]
    public function theMetaDescriptionIsWrittenToTheRecord(): void
    {
        $admin       = $this->setUpBackendUser(1);
        $description = 'A meta description of exactly the kind a search result shows under the headline.';

        $result = $this->tool->execute(
            [
                'pid'         => self::FOLDER_OPEN,
                'title'       => 'With description',
                'description' => $description,
                'datetime'    => self::DATETIME,
            ],
            ToolExecutionContext::fromBackendUser($admin),
        );

        self::assertFalse($result->isError, $result->content);
        self::assertSame($description, $this->createdRecord()['description'] ?? null);
    }

    /**
     * `description` is an exclude field in the news TCA: dropped for a missing
     * grant, the draft would carry no meta description although the approver
     * was shown one.
     */
    #[Test]
    public function aRecordWhoseDescriptionWasDroppedIsDeletedAgain(): void
    {
        $editor = $this->editor();
        // Deliberately WITHOUT `tx_news_domain_model_news:description`.
        $editor->groupData['non_exclude_fields'] = self::TABLE . ':hidden,' . self::TABLE . ':sys_language_uid';

        $result = $this->tool->execute(
            ['pid' => self::FOLDER_OPEN, 'title' => 'Description lost', 'description' => 'Shown to the approver', 'datetime' => self::DATETIME],
            ToolExecutionContext::fromBackendUser($editor),
        );

        self::assertTrue($result->isError, $result->content);
        self::assertStringContainsString('was deleted again', $result->content);
        self::assertStringContainsString('(description)', $result->content);
        self::assertSame(0, $this->recordCount(), 'nothing undeleted may be left');
    }

    #[Test]
    public function aUnixTimestampIsAcceptedForTheDate(): void
    {
        $admin = $this->setUpBackendUser(1);

        $result = $this->tool->execute(
            ['pid' => self::FOLDER_OPEN, 'title' => 'Stamped', 'datetime' => self::TIMESTAMP],
            ToolExecutionContext::fromBackendUser($admin),
        );

        self::assertFalse($result->isError, $result->content);
        self::assertSame(self::TIMESTAMP, (int)($this->createdRecord()['datetime'] ?? 0));
    }

    #[Test]
    public function aMissingDateIsRefusedWhileTheInstallationRequiresIt(): void
    {
        $admin = $this->setUpBackendUser(1);

        $result = $this->tool->execute(
            ['pid' => self::FOLDER_OPEN, 'title' => 'Undated'],
            ToolExecutionContext::fromBackendUser($admin),
        );

        self::assertTrue($result->isError);
        self::assertStringContainsString('"datetime" is required', $result->content);
        self::assertSame(0, $this->recordCount(), 'nothing may have been created');
    }

    #[Test]
    public function aStandardPageIsRefusedAsTarget(): void
    {
        $admin = $this->setUpBackendUser(1);

        $result = $this->tool->execute(
            ['pid' => self::STANDARD_PAGE, 'title' => 'Misplaced', 'datetime' => self::DATETIME],
            ToolExecutionContext::fromBackendUser($admin),
        );

        self::assertTrue($result->isError);
        self::assertStringContainsString('is not a storage folder', $result->content);
        self::assertSame(0, $this->recordCount());
    }

    #[Test]
    public function anEditorMayNotCreateInAFolderTheyMayNotEditContentIn(): void
    {
        $editor = $this->editor();

        $result = $this->tool->execute(
            ['pid' => self::FOLDER_CLOSED, 'title' => 'Sneaky', 'datetime' => self::DATETIME],
            ToolExecutionContext::fromBackendUser($editor),
        );

        self::assertTrue($result->isError);
        self::assertSame('Folder not found or not permitted.', $result->content);
        self::assertSame(0, $this->recordCount());
    }

    /**
     * TYPO3 14.3's DataHandler refuses an insert outside the acting user's
     * DB mounts (hasPageContextPermission); 13.4's does not. The tool's own
     * folder check covers both: BackendUserAuthentication::calcPerms() —
     * what doesUserHaveAccess() reads — returns no permission at all for a
     * page outside the web mount (13.4.35 line 504, 14.3.7 line 551), so the
     * preview and the write agree and no draft lands in a folder the editor
     * cannot reach in the page tree.
     */
    #[Test]
    public function anEditorMayNotCreateInAFolderOutsideTheirWebMount(): void
    {
        $editor = $this->editor();

        $result = $this->tool->execute(
            ['pid' => self::FOLDER_UNMOUNTED, 'title' => 'Out of reach', 'datetime' => self::DATETIME],
            ToolExecutionContext::fromBackendUser($editor),
        );

        self::assertTrue($result->isError);
        self::assertSame('Folder not found or not permitted.', $result->content);
        self::assertSame(['Folder not found or not permitted.'], $this->tool->previewCall(
            ['pid' => self::FOLDER_UNMOUNTED, 'title' => 'Out of reach', 'datetime' => self::DATETIME],
            ToolExecutionContext::fromBackendUser($editor),
        ));
        self::assertSame(0, $this->recordCount());
    }

    #[Test]
    public function anEditorWithoutTheTableGrantIsRefused(): void
    {
        $editor                             = $this->editor();
        $editor->groupData['tables_modify'] = '';

        $result = $this->tool->execute(
            ['pid' => self::FOLDER_OPEN, 'title' => 'No grant', 'datetime' => self::DATETIME],
            ToolExecutionContext::fromBackendUser($editor),
        );

        self::assertTrue($result->isError);
        self::assertStringContainsString('may not create news records', $result->content);
        self::assertSame(0, $this->recordCount());
    }

    #[Test]
    public function anEditorCreatesInAFolderTheyMayEditContentIn(): void
    {
        $editor = $this->editor();

        $result = $this->tool->execute(
            ['pid' => self::FOLDER_OPEN, 'title' => 'By the editor', 'teaser' => 'Theirs', 'datetime' => self::DATETIME],
            ToolExecutionContext::fromBackendUser($editor),
        );

        self::assertFalse($result->isError, $result->content);
        $row = $this->createdRecord();
        self::assertSame(1, (int)($row['hidden'] ?? 0));
        self::assertSame('Theirs', $row['teaser'] ?? null);
        self::assertSame([2], array_values(array_unique($this->sysLogUserIdsFor((int)($row['uid'] ?? 0)))));
    }

    /**
     * The failure this tool must never leave behind: the DataHandler drops an
     * exclude field the acting user has no grant for, silently. On news,
     * `hidden` is such a field with default 0 — the article would be live.
     */
    #[Test]
    public function aRecordThatCouldNotBeHiddenIsDeletedAgain(): void
    {
        $editor = $this->editor();
        // Deliberately WITHOUT `tx_news_domain_model_news:hidden`.
        $editor->groupData['non_exclude_fields'] = self::TABLE . ':teaser,' . self::TABLE . ':sys_language_uid';

        $result = $this->tool->execute(
            ['pid' => self::FOLDER_OPEN, 'title' => 'Would have been visible', 'datetime' => self::DATETIME],
            ToolExecutionContext::fromBackendUser($editor),
        );

        self::assertTrue($result->isError, $result->content);
        self::assertStringContainsString('was deleted again', $result->content);
        self::assertStringContainsString('hidden', $result->content);
        self::assertSame(0, $this->recordCount(), 'nothing undeleted may be left');
    }

    /**
     * The text direction of the same guard: `teaser` is an exclude field the
     * approver was shown; dropped for a missing grant it arrives empty, and
     * that — not the byte form, which an RTE-enabled teaser
     * (`rteForTeaser`) would change — is what the read-back detects. What
     * the narrowed compare no longer detects: a non-exclude field written in
     * a different byte form, which no grant can cause. `rteForTeaser` on is
     * not executed here.
     */
    #[Test]
    public function aRecordWhoseTeaserWasDroppedIsDeletedAgain(): void
    {
        $editor = $this->editor();
        // Deliberately WITHOUT `tx_news_domain_model_news:teaser`.
        $editor->groupData['non_exclude_fields'] = self::TABLE . ':hidden,' . self::TABLE . ':sys_language_uid';

        $result = $this->tool->execute(
            ['pid' => self::FOLDER_OPEN, 'title' => 'Teaser lost', 'teaser' => 'Shown to the approver', 'datetime' => self::DATETIME],
            ToolExecutionContext::fromBackendUser($editor),
        );

        self::assertTrue($result->isError, $result->content);
        self::assertStringContainsString('was deleted again', $result->content);
        self::assertStringContainsString('(teaser)', $result->content);
        self::assertSame(0, $this->recordCount(), 'nothing undeleted may be left');
    }

    /**
     * A refusal that arrives with a row: `processDatamap_afterDatabaseOperations`
     * runs once `insertDB()` has mapped the `NEW…` id to a uid, so an error a
     * hook logs there fills `errorLog` beside a record that exists. Reported
     * as a plain refusal, the row would stay and the next attempt would
     * create a duplicate; the tool takes it back before it reports.
     */
    #[Test]
    public function aRecordRefusedAfterTheInsertIsDeletedAgain(): void
    {
        $admin = $this->setUpBackendUser(1);
        $this->registerDataHandlerHook('processDatamapClass', LogsAnErrorAfterTheNewsInsertHook::class);

        $result = $this->tool->execute(
            ['pid' => self::FOLDER_OPEN, 'title' => 'Refused after the insert', 'datetime' => self::DATETIME],
            ToolExecutionContext::fromBackendUser($admin),
        );

        self::assertTrue($result->isError, $result->content);
        self::assertSame(1, (int)$this->createdRecord()['deleted'], 'the inserted row must be flagged deleted');
        self::assertSame(0, $this->recordCount(), 'nothing undeleted may be left');
        self::assertStringContainsString(LogsAnErrorAfterTheNewsInsertHook::MESSAGE, $result->content);
        self::assertStringContainsString('was deleted again', $result->content);
    }

    /**
     * nr-llm ADR-213, applied here: the lines are in the acting user's
     * language, in the order what, where, the new state, the consequences,
     * and the identifiers only in the last line.
     */
    #[Test]
    public function thePreviewShowsTheWholeDraftInTheActingUsersLanguageAndWritesNothing(): void
    {
        $arguments = [
            'pid'         => self::FOLDER_OPEN,
            'title'       => 'Proposed',
            'teaser'      => 'Short',
            'datetime'    => self::DATETIME,
            'author'      => 'Jane Doe',
            'description' => 'Meta text',
        ];

        self::assertSame([
            'Create news article as draft',
            'Location: in the folder “News folder”',
            'Title: “Proposed”',
            'Teaser: “Short”',
            'Article text: (not given)',
            'Date: 2026-09-22 10:00 (Europe/Berlin)',
            'Author: “Jane Doe”',
            'Meta description: “Meta text”',
            'URL path: generated from the title',
            'Type: article with its own text',
            'Language: default language',
            'Visibility: hidden at first',
            'Not set: categories, images and media, tags, related articles, links and files. They are added in the backend afterwards.',
            'After it is created the article is not publicly visible yet. It has to be made visible by a person.',
            'Technical details: folder UID 2, table tx_news_domain_model_news',
        ], $this->previewIn('en', $arguments));

        self::assertSame([
            'Neuen News-Artikel als Entwurf anlegen',
            'Ort: im Ordner „News folder“',
            'Titel: „Proposed“',
            'Teaser: „Short“',
            'Artikeltext: (nicht angegeben)',
            'Datum: 22.09.2026, 10:00 (Europe/Berlin)',
            'Autor: „Jane Doe“',
            'Meta Description: „Meta text“',
            'URL-Pfad: wird aus dem Titel erzeugt',
            'Typ: Artikel mit eigenem Text',
            'Sprache: Standardsprache',
            'Sichtbarkeit: zunächst verborgen',
            'Nicht gesetzt: Kategorien, Bilder und Medien, Tags, verwandte Artikel, Links und Dateien. Diese werden danach im Backend ergänzt.',
            'Der Artikel ist nach dem Anlegen noch nicht öffentlich sichtbar. Er muss erst von einer Person sichtbar gemacht werden.',
            'Technische Details: Ordner-UID 2, Tabelle tx_news_domain_model_news',
        ], $this->previewIn('de', $arguments));

        self::assertSame(0, $this->recordCount(), 'a preview must not create anything');
    }

    /**
     * The card a German editor read on 2026-10-08 was English with raw field
     * names (`title:`, `teaser:`, `(none)`). For lang=de no line but the
     * technical one may carry an internal name or an English word of the
     * former card — with every optional field left out as well, so the
     * "not given" path is German too.
     */
    #[Test]
    public function aGermanPreviewCarriesNoEnglishAndNoInternalName(): void
    {
        $lines = $this->previewIn('de', [
            'pid'      => self::FOLDER_OPEN,
            'title'    => 'Entwurf',
            'bodytext' => '<p>Ein Absatz.</p>',
            'datetime' => self::DATETIME,
        ]);

        self::assertSame('Teaser: (nicht angegeben)', $lines[3]);
        self::assertSame('Artikeltext: „<p>Ein Absatz.</p>“', $lines[4]);
        self::assertSame('Autor: (nicht angegeben)', $lines[6]);
        self::assertSame('Meta Description: (nicht angegeben)', $lines[7]);

        $technical = array_pop($lines);
        self::assertStringStartsWith('Technische Details: ', $technical);

        $card = implode("\n", $lines);
        self::assertDoesNotMatchRegularExpression('/\b[a-z]+(?:_[a-z]+)+\b/', $card, 'no field or table name above the technical line');
        self::assertStringNotContainsString('[2]', $card, 'no UID above the technical line');
        // The former card opened each line with the raw, lower-case field name.
        self::assertDoesNotMatchRegularExpression('/^[a-z][a-z ]*:/m', $card, 'a line opens with a field name');
        foreach (['New news article', 'meta description', '(none)', 'type:', 'article', 'default language', 'visibility', 'hidden', 'human', 'not given'] as $english) {
            self::assertStringNotContainsString($english, $card, 'English on a German card: ' . $english);
        }
    }

    /**
     * nr-llm ADR-184: the lines are compared byte for byte on resume, so the
     * language of whoever renders or resumes — the request's backend user in
     * $GLOBALS['BE_USER'] and its $GLOBALS['LANG'] — must not reach them. Only
     * the acting user's `lang` decides.
     */
    #[Test]
    public function thePreviewIgnoresTheLanguageOfTheViewingRequest(): void
    {
        $arguments = ['pid' => self::FOLDER_OPEN, 'title' => 'Proposed', 'datetime' => self::DATETIME];
        $english   = $this->previewIn('en', $arguments);
        $german    = $this->previewIn('de', $arguments);
        self::assertNotSame($english, $german);

        $ambient = $GLOBALS['LANG'] ?? null;

        try {
            self::assertSame($english, $this->previewAmidAnotherUser('en', 'de', $arguments));
            self::assertSame($german, $this->previewAmidAnotherUser('de', 'en', $arguments));
        } finally {
            $GLOBALS['LANG'] = $ambient;
        }
    }

    #[Test]
    public function theViewerGateAnswersForTheViewerNotTheRun(): void
    {
        $admin  = $this->setUpBackendUser(1);
        $editor = $this->editor();

        $arguments = ['pid' => self::FOLDER_CLOSED, 'title' => 'x', 'datetime' => self::DATETIME];

        self::assertTrue($this->tool->mayViewerReadPreview($arguments, $admin));
        self::assertFalse($this->tool->mayViewerReadPreview($arguments, $editor));
    }

    /**
     * The preview as the run's acting administrator reads it, in that user's
     * language: the `lang` column of the backend user.
     *
     * @param array<string, mixed> $arguments
     *
     * @return list<string>
     */
    private function previewIn(string $language, array $arguments): array
    {
        $admin               = $this->setUpBackendUser(1);
        $admin->user['lang'] = $language;

        return $this->tool->previewCall($arguments, ToolExecutionContext::fromBackendUser($admin));
    }

    /**
     * The preview as the acting administrator reads it while ANOTHER backend
     * user, with another language, is the one of the request: in
     * $GLOBALS['BE_USER'] and, through its language, in $GLOBALS['LANG'].
     * Two distinct user objects, so a preview that read the request's user
     * instead of the acting one comes out in the wrong language.
     *
     * @param array<string, mixed> $arguments
     *
     * @return list<string>
     */
    private function previewAmidAnotherUser(string $actingLanguage, string $requestLanguage, array $arguments): array
    {
        $acting               = $this->setUpBackendUser(1);
        $acting->user['lang'] = $actingLanguage;

        // setUpBackendUser() puts the user it returns into $GLOBALS['BE_USER'].
        $request               = $this->setUpBackendUser(2);
        $request->user['lang'] = $requestLanguage;
        self::assertSame($request, $GLOBALS['BE_USER'] ?? null);
        self::assertNotSame($acting, $request);

        $factory = $this->get(LanguageServiceFactory::class);
        self::assertInstanceOf(LanguageServiceFactory::class, $factory);
        $GLOBALS['LANG'] = $factory->createFromUserPreferences($request);

        return $this->tool->previewCall($arguments, ToolExecutionContext::fromBackendUser($acting));
    }

    /**
     * The editor with every grant the happy path needs: the table, and the
     * exclude fields the tool writes (`hidden`, `teaser`, `author`,
     * `sys_language_uid` are exclude fields in the news TCA).
     */
    private function editor(): BackendUserAuthentication
    {
        $editor                                  = $this->setUpBackendUser(2);
        $editor->groupData['tables_modify']      = self::TABLE;
        $editor->groupData['non_exclude_fields'] = implode(',', [
            self::TABLE . ':hidden',
            self::TABLE . ':teaser',
            self::TABLE . ':author',
            self::TABLE . ':sys_language_uid',
        ]);

        return $editor;
    }

    /**
     * The one record this tool created, asserting there is exactly one.
     *
     * @return array<string, mixed>
     */
    private function createdRecord(): array
    {
        $queryBuilder = $this->getConnectionPool()->getQueryBuilderForTable(self::TABLE);
        $queryBuilder->getRestrictions()->removeAll();

        $rows = $queryBuilder
            ->select('*')
            ->from(self::TABLE)
            ->executeQuery()
            ->fetchAllAssociative();

        self::assertCount(1, $rows, 'exactly one record must have been created');

        return $rows[0];
    }

    /**
     * Records that are not flagged deleted — what an editor would still see.
     */
    private function recordCount(): int
    {
        $queryBuilder = $this->getConnectionPool()->getQueryBuilderForTable(self::TABLE);
        $queryBuilder->getRestrictions()->removeAll();

        return (int)$queryBuilder
            ->count('uid')
            ->from(self::TABLE)
            ->where($queryBuilder->expr()->eq('deleted', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)))
            ->executeQuery()
            ->fetchOne();
    }

    /**
     * @return list<int>
     */
    private function sysLogUserIdsFor(int $uid): array
    {
        $queryBuilder = $this->getConnectionPool()->getQueryBuilderForTable('sys_log');
        $queryBuilder->getRestrictions()->removeAll();

        $rows = $queryBuilder
            ->select('userid')
            ->from('sys_log')
            ->where(
                $queryBuilder->expr()->eq('tablename', $queryBuilder->createNamedParameter(self::TABLE)),
                $queryBuilder->expr()->eq('recuid', $queryBuilder->createNamedParameter($uid, Connection::PARAM_INT)),
            )
            ->executeQuery()
            ->fetchAllAssociative();

        return array_map(static fn(array $row): int => (int)($row['userid'] ?? 0), $rows);
    }

    private function registerDataHandlerHook(string $list, string $className): void
    {
        $hooks   = $this->dataHandlerHooks($list);
        $hooks[] = $className;
        $this->storeDataHandlerHooks($list, $hooks);
    }

    private function unregisterDataHandlerHook(string $list, string $className): void
    {
        $this->storeDataHandlerHooks($list, array_filter(
            $this->dataHandlerHooks($list),
            static fn(mixed $registeredClassName): bool => $registeredClassName !== $className,
        ));
    }

    /**
     * One DataHandler hook list, narrowed step by step — `$GLOBALS` is `mixed`.
     *
     * @return array<array-key, mixed>
     */
    private function dataHandlerHooks(string $list): array
    {
        $confVars = $GLOBALS['TYPO3_CONF_VARS'] ?? [];
        $options  = is_array($confVars) ? ($confVars['SC_OPTIONS'] ?? []) : [];
        $tcemain  = is_array($options) ? ($options['t3lib/class.t3lib_tcemain.php'] ?? []) : [];
        $hooks    = is_array($tcemain) ? ($tcemain[$list] ?? []) : [];

        return is_array($hooks) ? $hooks : [];
    }

    /**
     * @param array<array-key, mixed> $hooks
     */
    private function storeDataHandlerHooks(string $list, array $hooks): void
    {
        $confVars = $GLOBALS['TYPO3_CONF_VARS'] ?? [];
        if (!is_array($confVars)) {
            $confVars = [];
        }

        $options = $confVars['SC_OPTIONS'] ?? [];
        if (!is_array($options)) {
            $options = [];
        }

        $tcemain = $options['t3lib/class.t3lib_tcemain.php'] ?? [];
        if (!is_array($tcemain)) {
            $tcemain = [];
        }

        $tcemain[$list]                           = $hooks;
        $options['t3lib/class.t3lib_tcemain.php'] = $tcemain;
        $confVars['SC_OPTIONS']                   = $options;
        $GLOBALS['TYPO3_CONF_VARS']               = $confVars;
    }
}
