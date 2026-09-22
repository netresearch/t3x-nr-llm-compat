<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlmCompat\Tests\Unit\Bridge\News;

use Netresearch\NrLlm\Domain\Enum\ToolEffect;
use Netresearch\NrLlm\Service\Tool\EditorActionInterface;
use Netresearch\NrLlm\Service\Tool\ToolEffectInterface;
use Netresearch\NrLlm\Service\Tool\ToolExecutionContext;
use Netresearch\NrLlm\Service\Tool\ToolPreviewInterface;
use Netresearch\NrLlmCompat\Bridge\News\CreateNewsDraftTool;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Localization\LanguageService;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

/**
 * Argument validation and declarations of the news draft tool.
 *
 * Every assertion here stops the call BEFORE the database is touched, so a
 * stub ConnectionPool is enough. The creation itself — folder permissions,
 * the new uid, the hidden state, the read-back — runs against a real
 * database and the real news TCA in Tests/Functional.
 */
#[CoversClass(CreateNewsDraftTool::class)]
final class CreateNewsDraftToolTest extends UnitTestCase
{
    private const TABLE = 'tx_news_domain_model_news';

    private CreateNewsDraftTool $tool;

    /** @var array<string, mixed> */
    private array $globalsBackup = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->globalsBackup = [
            'TCA'             => $GLOBALS['TCA'] ?? null,
            'LANG'            => $GLOBALS['LANG'] ?? null,
            'BE_USER'         => $GLOBALS['BE_USER'] ?? null,
            'TYPO3_CONF_VARS' => $GLOBALS['TYPO3_CONF_VARS'] ?? null,
        ];

        $GLOBALS['TCA'] = [self::TABLE => [
            'ctrl'    => ['enablecolumns' => ['disabled' => 'hidden']],
            'columns' => [
                'title'    => ['config' => ['type' => 'input', 'max' => 255, 'required' => true]],
                'teaser'   => ['config' => ['type' => 'text']],
                'bodytext' => ['config' => ['type' => 'text']],
                'datetime' => ['config' => ['type' => 'datetime', 'required' => true]],
                'author'   => ['config' => ['type' => 'input']],
                'hidden'   => ['config' => ['type' => 'check']],
            ],
        ]];
        $GLOBALS['LANG']    = self::createStub(LanguageService::class);
        $GLOBALS['BE_USER'] = $this->liveUser();
        // news's ext_conf_template default: the date is required. Present on
        // every installation once the extension is set up; without it the
        // core's ExtensionConfiguration would try to synchronise the template
        // through a PackageManager this test does not have.
        $GLOBALS['TYPO3_CONF_VARS'] = ['EXTENSIONS' => ['news' => ['dateTimeNotRequired' => '0']]];

        $this->tool = new CreateNewsDraftTool(self::createStub(ConnectionPool::class));
    }

    protected function tearDown(): void
    {
        foreach ($this->globalsBackup as $key => $value) {
            if ($value === null) {
                unset($GLOBALS[$key]);

                continue;
            }

            $GLOBALS[$key] = $value;
        }

        parent::tearDown();
    }

    #[Test]
    public function itDeclaresANonIdempotentWriteEffect(): void
    {
        // Declared through the marker interface nr-llm's runtime reads: a
        // tool without it is treated as read-only and never pauses for
        // approval.
        self::assertContains(ToolEffectInterface::class, $this->implemented());
        self::assertSame(ToolEffect::NON_IDEMPOTENT_WRITE, $this->tool->getEffect());
    }

    #[Test]
    public function itShipsDisabledInTheEditingGroupAndIsNotAdminOnly(): void
    {
        self::assertFalse($this->tool->isEnabledByDefault());
        self::assertFalse($this->tool->requiresAdmin());
        self::assertSame('editing', $this->tool->getGroup());
    }

    #[Test]
    public function itOffersAPreviewAndAnEditorActionOnTheNewsTable(): void
    {
        self::assertContains(ToolPreviewInterface::class, $this->implemented());
        self::assertContains(EditorActionInterface::class, $this->implemented());

        $action = $this->tool->getEditorAction();
        self::assertSame([self::TABLE], $action->recordTypes);
        self::assertStringStartsWith('LLL:EXT:nr_llm_compat/', $action->labelKey);
        self::assertStringStartsWith('LLL:EXT:nr_llm_compat/', $action->descriptionKey);
    }

    #[Test]
    public function theSpecNamesTheArgumentsAndOffersNoWayToPublishOrRelate(): void
    {
        $spec = $this->tool->getSpec();

        self::assertSame('create_news_draft', $spec->name);
        self::assertSame(['pid', 'title'], $spec->parameters['required'] ?? null);

        $properties = $spec->parameters['properties'] ?? null;
        self::assertIsArray($properties);
        self::assertSame(['pid', 'title', 'teaser', 'bodytext', 'datetime', 'author'], array_keys($properties));
        // Fixed by the tool, never an argument.
        foreach (['hidden', 'type', 'sys_language_uid', 'language', 'path_segment', 'slug', 'categories', 'fal_media', 'related'] as $fixed) {
            self::assertArrayNotHasKey($fixed, $properties);
        }

        // The phase-one scope is stated to the model, not left for it to
        // discover by refusal.
        self::assertStringContainsString('HIDDEN', $spec->description);
        self::assertStringContainsString('Categories', $spec->description);
    }

    #[Test]
    public function itFailsClosedWithoutAnActingBackendUser(): void
    {
        $result = $this->tool->execute($this->valid(), ToolExecutionContext::none());

        self::assertTrue($result->isError);
        self::assertSame('Folder not found or not permitted.', $result->content);
    }

    #[Test]
    public function itRefusesOutsideTheLiveWorkspace(): void
    {
        $draftUser            = $this->liveUser();
        $draftUser->workspace = 1;

        $result = $this->tool->execute($this->valid(), $this->contextFor($draftUser));

        self::assertTrue($result->isError);
        self::assertStringContainsString('live workspace', $result->content);
    }

    #[Test]
    public function itRefusesAndNamesEachMissingPieceOfTheBackendEnvironment(): void
    {
        unset($GLOBALS['TCA'], $GLOBALS['LANG'], $GLOBALS['BE_USER']);

        $result = $this->tool->execute($this->valid(), $this->contextFor($this->liveUser()));

        self::assertTrue($result->isError);
        self::assertStringContainsString('TCA', $result->content);
        self::assertStringContainsString('language service', $result->content);
        self::assertStringContainsString('backend user', $result->content);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function refusedArguments(): iterable
    {
        $valid = ['pid' => 1, 'title' => 'x', 'datetime' => '2026-09-22T10:00:00+02:00'];

        yield 'no pid'       => [['title' => 'x', 'datetime' => 1758528000], 'exactly one storage folder'];
        yield 'zero pid'     => [['pid' => 0] + $valid, 'exactly one storage folder'];
        yield 'negative pid' => [['pid' => -1] + $valid, 'exactly one storage folder'];
        yield 'no title'     => [['pid' => 1, 'datetime' => 1758528000], '"title" is required'];
        yield 'empty title'  => [['title' => ''] + $valid, 'must not be empty'];
        yield 'blank title'  => [['title' => '   '] + $valid, 'must not be empty'];
        yield 'array title'  => [['title' => ['x']] + $valid, 'must be a string'];
        yield 'long title'   => [['title' => str_repeat('a', 256)] + $valid, 'exceeds 255 characters'];
        yield 'array teaser' => [$valid + ['teaser' => ['x']], 'must be a string'];
        yield 'long teaser'  => [$valid + ['teaser' => str_repeat('a', 20001)], 'exceeds 20000 characters'];
        yield 'long bodytext' => [$valid + ['bodytext' => str_repeat('a', 20001)], 'exceeds 20000 characters'];
        yield 'long author'  => [$valid + ['author' => str_repeat('a', 256)], 'exceeds 255 characters'];
        yield 'missing datetime while required' => [['pid' => 1, 'title' => 'x'], '"datetime" is required'];
        yield 'empty datetime while required'   => [['datetime' => ''] + $valid, '"datetime" is required'];
        yield 'zero datetime'      => [['datetime' => 0] + $valid, 'ISO 8601'];
        // A bare year is not a timestamp: 2026 seconds after 1970 is not a date anyone asked for.
        yield 'bare year as string' => [['datetime' => '2026'] + $valid, 'ISO 8601'];
        yield 'bare year as int'    => [['datetime' => 2026] + $valid, 'ISO 8601'];
        // The last second of 2000: the description promises 2001 onwards.
        yield 'timestamp before 2001' => [['datetime' => 978307199] + $valid, 'ISO 8601'];
        yield 'garbage datetime'   => [['datetime' => 'next tuesday'] + $valid, 'ISO 8601'];
        yield 'relative datetime'  => [['datetime' => 'tomorrow'] + $valid, 'ISO 8601'];
        yield 'array datetime'     => [['datetime' => [1]] + $valid, 'ISO 8601'];
        yield 'unknown argument'   => [$valid + ['subtitle' => 'x'], 'not an argument of this tool'];
        // The ones that would defeat the tool's guarantees.
        yield 'hidden smuggled in'     => [$valid + ['hidden' => 0], 'not an argument of this tool'];
        yield 'type smuggled in'       => [$valid + ['type' => 2], 'not an argument of this tool'];
        yield 'language smuggled in'   => [$valid + ['sys_language_uid' => 1], 'not an argument of this tool'];
        yield 'slug smuggled in'       => [$valid + ['path_segment' => 'x'], 'not an argument of this tool'];
        yield 'categories smuggled in' => [$valid + ['categories' => '1'], 'not an argument of this tool'];
        yield 'media smuggled in'      => [$valid + ['fal_media' => 1], 'not an argument of this tool'];
    }

    /**
     * @param array<string, mixed> $arguments
     */
    #[Test]
    #[DataProvider('refusedArguments')]
    public function itRefusesInvalidArguments(array $arguments, string $expectedFragment): void
    {
        $result = $this->tool->execute($arguments, $this->contextFor($this->liveUser()));

        self::assertTrue($result->isError);
        self::assertStringContainsString($expectedFragment, $result->content);
    }

    /**
     * @param array<string, mixed> $arguments
     */
    #[Test]
    #[DataProvider('refusedArguments')]
    public function thePreviewRefusesTheSameArguments(array $arguments, string $expectedFragment): void
    {
        $lines = $this->tool->previewCall($arguments, $this->contextFor($this->liveUser()));

        self::assertCount(1, $lines);
        self::assertStringContainsString($expectedFragment, $lines[0]);
    }

    /**
     * The other direction of the datetime rule: with news configured to not
     * require the date, a call without one passes the argument checks. It is
     * then stopped by the next gate — the acting user's missing table grant —
     * which is how the test proves that the datetime refusal did not fire
     * without touching the database.
     */
    #[Test]
    public function aMissingDatetimeIsAcceptedWhenTheInstallationDoesNotRequireIt(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['news']['dateTimeNotRequired'] = '1';
        $editor       = $this->liveUser();
        $editor->user = ['uid' => 5, 'admin' => 0];

        $result = $this->tool->execute(['pid' => 1, 'title' => 'x'], $this->contextFor($editor));

        self::assertTrue($result->isError);
        self::assertStringNotContainsString('datetime', $result->content);
        self::assertStringContainsString('may not create news records', $result->content);
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function timestampsFrom2001(): iterable
    {
        yield 'first second of 2001' => [978307200];
        yield 'January 2001'         => [980000000];
    }

    /**
     * The other direction of the timestamp floor: an integer from 2001-01-01
     * on is read as a UNIX timestamp, as the spec description and the
     * refusal promise. Proven the same way as the optional date above — the
     * call passes the argument checks and is stopped by the table grant.
     */
    #[Test]
    #[DataProvider('timestampsFrom2001')]
    public function aTimestampFrom2001OnIsAccepted(int $timestamp): void
    {
        $editor       = $this->liveUser();
        $editor->user = ['uid' => 5, 'admin' => 0];

        $result = $this->tool->execute(
            ['pid' => 1, 'title' => 'x', 'datetime' => $timestamp],
            $this->contextFor($editor),
        );

        self::assertTrue($result->isError);
        self::assertStringNotContainsString('ISO 8601', $result->content);
        self::assertStringContainsString('may not create news records', $result->content);
    }

    #[Test]
    public function anUnknownArgumentNameIsEchoedBackStrippedOfAnythingButItsIdentifierCharacters(): void
    {
        $result = $this->tool->execute(
            $this->valid() + ["type\n<script>" => 1],
            $this->contextFor($this->liveUser()),
        );

        self::assertTrue($result->isError);
        self::assertStringNotContainsString('<', $result->content);
        self::assertStringContainsString('typescript', $result->content);
    }

    #[Test]
    public function itRefusesAUserWithoutTheTableGrant(): void
    {
        $editor       = $this->liveUser();
        $editor->user = ['uid' => 5, 'admin' => 0];

        $result = $this->tool->execute($this->valid(), $this->contextFor($editor));

        self::assertTrue($result->isError);
        self::assertStringContainsString('may not create news records', $result->content);
    }

    #[Test]
    public function itRefusesAUserWhoMayNotEditTheDefaultLanguage(): void
    {
        $restricted                                 = $this->liveUser();
        $restricted->user                           = ['uid' => 5, 'admin' => 0];
        $restricted->groupData['tables_modify']     = self::TABLE;
        $restricted->groupData['allowed_languages'] = '2';

        $result = $this->tool->execute($this->valid(), $this->contextFor($restricted));

        self::assertTrue($result->isError);
        self::assertStringContainsString('default language', $result->content);
    }

    /**
     * @return array<string, mixed>
     */
    private function valid(): array
    {
        return ['pid' => 1, 'title' => 'x', 'datetime' => '2026-09-22T10:00:00+02:00'];
    }

    /**
     * The interfaces the tool DECLARES — read from the class rather than
     * narrowed by the type checker, so dropping an `implements` reddens.
     *
     * @return list<string>
     */
    private function implemented(): array
    {
        $implemented = class_implements($this->tool);
        self::assertIsArray($implemented);

        return array_values($implemented);
    }

    private function liveUser(): BackendUserAuthentication
    {
        $user            = new BackendUserAuthentication();
        $user->user      = ['uid' => 1, 'admin' => 1];
        $user->workspace = 0;

        return $user;
    }

    private function contextFor(BackendUserAuthentication $user): ToolExecutionContext
    {
        return ToolExecutionContext::fromBackendUser($user);
    }
}
