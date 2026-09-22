<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlmCompat\Bridge\News;

use DateTimeImmutable;
use Exception;
use GeorgRinger\News\Domain\Model\Dto\EmConfiguration;
use Netresearch\NrLlm\Domain\Enum\ToolEffect;
use Netresearch\NrLlm\Domain\Enum\WriteKind;
use Netresearch\NrLlm\Domain\ValueObject\EditorAction;
use Netresearch\NrLlm\Domain\ValueObject\RecordReference;
use Netresearch\NrLlm\Domain\ValueObject\ToolResult;
use Netresearch\NrLlm\Domain\ValueObject\ToolSpec;
use Netresearch\NrLlm\Service\Tool\EditorActionInterface;
use Netresearch\NrLlm\Service\Tool\ToolEffectInterface;
use Netresearch\NrLlm\Service\Tool\ToolExecutionContext;
use Netresearch\NrLlm\Service\Tool\ToolInterface;
use Netresearch\NrLlm\Service\Tool\ToolPreviewInterface;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Domain\Repository\PageRepository;
use TYPO3\CMS\Core\Localization\LanguageService;
use TYPO3\CMS\Core\Type\Bitmask\Permission;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Utility\MathUtility;
use TYPO3\CMS\Core\Utility\StringUtility;

/**
 * Create ONE hidden news record (EXT:news, tx_news_domain_model_news) in a
 * storage folder, through the DataHandler, as the acting backend user.
 *
 * Shaped after nr-llm's builtin CreatePageDraftTool, and holding the same
 * line (nr-llm ADR-135/146/180): disabled by default, in the writers' own
 * group, a non-idempotent write so the approval pause applies, live
 * workspace only, the acting user authorised explicitly, one neutral refusal
 * for "no such folder" and "not yours", and a read-back that takes the
 * record back when the DataHandler dropped an exclude field the approver
 * was shown.
 *
 * What is fixed and why:
 *
 * - **Always hidden.** A model-drafted article is read by a human in the
 *   news module before anyone publishes it; there is no argument for it.
 * - **Always an article** (`type` 0). The link types (1 internal, 2
 *   external) require a URL field each and describe a redirect, not content.
 * - **Default language only** (phase one). A translation is a record with a
 *   parent, and that is a different write.
 * - **Only a storage folder as target.** News allows its records on any page
 *   type (`security.ignorePageTypeRestriction`); an editor's news live in a
 *   sysfolder, and a draft in the wrong place is a draft nobody finds.
 * - **No categories, media, tags or related records** (phase one). The tool
 *   says so in its description; an editor adds them in the backend.
 * - **The URL segment** (`path_segment`) is the DataHandler's slug generator's.
 *
 * nr-llm's WritesThroughDataHandlerTrait and PlansOneEditorialWriteTrait
 * carry the same mechanics, but neither is part of nr-llm's `@api` surface
 * (nr-llm ADR-127: the marker is the semver authority; the traits are
 * unmarked and absent from api-surface.txt), so a change to them in a minor
 * release would break this package with no deprecation shield. The handful
 * of private helpers below is this tool's own copy, kept to what it needs.
 */
final readonly class CreateNewsDraftTool implements ToolInterface, ToolEffectInterface, ToolPreviewInterface, EditorActionInterface
{
    /**
     * One string for "no such page", "deleted" and "you may not create
     * records there", so a refusal never confirms that a page uid exists.
     */
    private const NOT_PERMITTED = 'Folder not found or not permitted.';

    private const TABLE = 'tx_news_domain_model_news';

    private const PAGES_TABLE = 'pages';

    /** The only news type this tool creates: an article with its own text. */
    private const TYPE_ARTICLE = 0;

    private const ARGUMENTS = ['pid', 'title', 'teaser', 'bodytext', 'datetime', 'author'];

    /** `title` is `varchar(255)` and the TCA says `max` 255. */
    private const MAX_TITLE_LENGTH = 255;

    /** `author` is `tinytext`. */
    private const MAX_AUTHOR_LENGTH = 255;

    /**
     * `teaser` and `bodytext` are `text` columns without a TCA `max`, so
     * nothing else bounds a model-chosen argument. The same bound nr-llm puts
     * on a content element's bodytext.
     */
    private const MAX_TEXT_LENGTH = 20000;

    /**
     * Date-time shapes the tool accepts: an ISO 8601 date, optionally with a
     * time and an offset. Deliberately no relative phrases — the preview must
     * be a pure function of the arguments (nr-llm ADR-184), and "tomorrow"
     * is a different timestamp on every resume.
     */
    private const DATETIME_PATTERN = '/^\d{4}-\d{2}-\d{2}(?:[T ]\d{2}:\d{2}(?::\d{2})?(?:Z|[+-]\d{2}:?\d{2})?)?$/';

    /**
     * The smallest integer read as a UNIX timestamp: 2001-01-01T00:00:00Z,
     * the "2001 onwards" the spec description and the refusal promise. A
     * bare year such as 2026 is an integer too, and read as seconds it is a
     * date in 1970 that news's lists sort to the very end. An older date
     * goes as an ISO string.
     */
    private const MIN_TIMESTAMP = 978_307_200;

    /** How many DataHandler complaints are echoed back, and how long each may be. */
    private const MAX_ERRORS = 5;

    private const MAX_ERROR_LENGTH = 200;

    /** How much of a value one approval-card line shows. */
    private const PREVIEW_EXCERPT_LENGTH = 120;

    public function __construct(
        private ConnectionPool $connectionPool,
    ) {}

    public function getSpec(): ToolSpec
    {
        return ToolSpec::function(
            'create_news_draft',
            'Create ONE news article (an EXT:news record) in a storage folder. The record is always created HIDDEN, '
            . 'so a human must review and unhide it before it is published. Writes through the TYPO3 DataHandler as '
            . 'the acting backend user, in the live workspace and in the default language. The type is always '
            . '"article": internal or external link news cannot be created with this tool. Categories, images or '
            . 'other media, tags and related records cannot be set here; an editor adds them in the backend '
            . 'afterwards. The URL segment is generated from the title.',
            [
                'type'       => 'object',
                'properties' => [
                    'pid' => [
                        'type'        => 'integer',
                        'description' => 'The uid of the storage folder (sysfolder) the news records live in.',
                    ],
                    'title' => [
                        'type'        => 'string',
                        'description' => 'The headline. Required, at most 255 characters — it is how a human '
                            . 'recognises the draft, and the URL segment is derived from it.',
                    ],
                    'teaser' => [
                        'type'        => 'string',
                        'description' => 'A short summary shown in lists. Optional.',
                    ],
                    'bodytext' => [
                        'type'        => 'string',
                        'description' => 'The article text as HTML prose (paragraphs, headings, lists, links). '
                            . 'Optional.',
                    ],
                    'datetime' => [
                        'type'        => 'string',
                        'description' => 'The publication date and time: an ISO 8601 date-time such as '
                            . '2026-09-22T10:00:00+02:00, or a UNIX timestamp in seconds (2001 or later; a bare '
                            . 'year is refused). Required unless the installation '
                            . 'has made the news date optional (extension setting dateTimeNotRequired); the tool '
                            . 'refuses a call that omits it while it is required.',
                    ],
                    'author' => [
                        'type'        => 'string',
                        'description' => 'The author name shown with the article. Optional.',
                    ],
                ],
                'required' => ['pid', 'title'],
            ],
        );
    }

    public function execute(array $arguments, ToolExecutionContext $context): ToolResult
    {
        $user = $this->writableActingUser($context);
        if ($user instanceof ToolResult) {
            return $user;
        }

        $plan = $this->plan($arguments, $user);
        if (is_string($plan)) {
            return ToolResult::error($plan);
        }

        $record = [
            'pid'   => $plan['pid'],
            'title' => $plan['title'],
            'type'  => self::TYPE_ARTICLE,
            // Default language only; stated so the DataHandler's own language
            // check on a NEW record reads it from the incoming fields.
            'sys_language_uid' => 0,
            // Never negotiable; see the class docblock.
            $plan['hiddenField'] => 1,
        ];
        foreach (['teaser', 'bodytext', 'author'] as $field) {
            if ($plan[$field] !== null) {
                $record[$field] = $plan[$field];
            }
        }

        if ($plan['datetime'] !== null) {
            // An integer UNIX timestamp is the one shape both supported cores
            // store unchanged: TYPO3 13.4 keeps an integer as it is, 14.3 reads
            // it as the database value and writes the same value back. An ISO
            // string with an offset is shifted by the server timezone on 13.4.
            $record['datetime'] = $plan['datetime'];
        }

        $newUid = $this->createRecord($record, $user);
        if ($newUid instanceof ToolResult) {
            return $newUid;
        }

        // Read back before reporting success. A uid proves a row exists, not
        // that it carries what was approved: the DataHandler SKIPS a field the
        // acting user lacks the `non_exclude_fields` grant for, silently and
        // without logging — and on news, `hidden`, `teaser`, `author` and
        // `sys_language_uid` are all exclude fields. A dropped `hidden` means
        // the article is live on the site, the one outcome this tool exists
        // to prevent. Only exclude fields are compared: `bodytext` is stored
        // through the RTE parser, which joins block elements with a line
        // feed, so its bytes legitimately differ from the argument.
        $stored    = $this->fetchRowByUid(self::TABLE, $newUid);
        $differing = $stored === null ? ['record'] : $this->differingFields($record, $stored);
        if ($differing !== []) {
            $removed = $this->discard($newUid, $user);

            return ToolResult::error(sprintf(
                'News record [%d] was created but did not carry what was asked for (%s), so it %s. The acting '
                . 'backend user is most likely missing the field-level ("exclude field") grant for %s on %s.',
                $newUid,
                implode(', ', $differing),
                $removed ? 'was deleted again' : 'COULD NOT BE DELETED and may be visible — remove it by hand',
                implode(', ', $differing),
                self::TABLE,
            ));
        }

        $slug = $this->string($stored['path_segment'] ?? '');

        return ToolResult::text(sprintf(
            'Created hidden news draft [%d] "%s" in folder [%d]%s. It is not published until a human unhides it. '
            . 'Categories, media, tags and related records are not set; an editor adds them in the backend.',
            $newUid,
            $this->excerpt($plan['title']),
            $plan['pid'],
            $slug !== '' ? sprintf(' (URL segment %s)', $slug) : '',
        ))->withWriteTarget(new RecordReference(self::TABLE, $newUid), WriteKind::CREATED);
    }

    /**
     * What this call would create, as the approver reads it. There is no
     * "before" — the record does not exist yet — so the card shows the whole
     * of what would come into being. Authorised exactly like execute(),
     * against the same explicit acting user, down to the neutral refusal.
     *
     * @param array<string, mixed> $arguments
     *
     * @return list<string>
     */
    public function previewCall(array $arguments, ToolExecutionContext $context): array
    {
        $user = $context->actingBackendUser();
        if (!$user instanceof BackendUserAuthentication) {
            return [self::NOT_PERMITTED];
        }

        $plan = $this->plan($arguments, $user);
        if (is_string($plan)) {
            return [$plan];
        }

        return [
            sprintf('New news article in folder [%d] "%s":', $plan['pid'], $this->excerpt($plan['folderTitle'])),
            sprintf('title: %s', $this->quoted($plan['title'])),
            sprintf('teaser: %s', $plan['teaser'] === null ? '(none)' : $this->quoted($plan['teaser'])),
            sprintf('text: %s', $plan['bodytext'] === null ? '(none)' : $this->quoted($plan['bodytext'])),
            sprintf(
                'date: %s',
                $plan['datetime'] === null
                    ? '(none)'
                    : (new DateTimeImmutable())->setTimestamp($plan['datetime'])->format(DateTimeImmutable::ATOM),
            ),
            sprintf('author: %s', $plan['author'] === null ? '(none)' : $this->quoted($plan['author'])),
            'type: article, default language; no categories, media, tags or related records',
            'visibility: hidden — a human must unhide it before it is published',
        ];
    }

    /**
     * Whether the person LOOKING at the approval card may see this call: the
     * same resolution the write uses, run against the viewer. A viewer who
     * could not have made the call gets no preview.
     *
     * @param array<string, mixed> $arguments
     */
    public function mayViewerReadPreview(array $arguments, BackendUserAuthentication $viewer): bool
    {
        return !is_string($this->plan($arguments, $viewer));
    }

    public function isEnabledByDefault(): bool
    {
        // A writing tool is never on by default.
        return false;
    }

    public function requiresAdmin(): bool
    {
        // Usable by a non-admin: the folder is authorised against the acting
        // user's own page permission and table grant, and the DataHandler
        // enforces both a second time inside the creation.
        return false;
    }

    public function getGroup(): string
    {
        // The writers' own group.
        return 'editing';
    }

    public function getEffect(): ToolEffect
    {
        // A creation with no caller-supplied key: running it twice leaves two
        // records. A reaped run that may already have created one must fail
        // terminally rather than draft it again.
        return ToolEffect::NON_IDEMPOTENT_WRITE;
    }

    /**
     * The human-facing declaration. `recordTypes` names the news table: this
     * is the writer that owns it, and nr-llm's generic fallback steps back
     * from a table a registered writer declares.
     */
    public function getEditorAction(): EditorAction
    {
        return new EditorAction(
            'LLL:EXT:nr_llm_compat/Resources/Private/Language/locallang.xlf:editorAction.create_news_draft.label',
            'LLL:EXT:nr_llm_compat/Resources/Private/Language/locallang.xlf:editorAction.create_news_draft.description',
            // Registered by EXT:news (Configuration/Icons.php, present since
            // 12.0.0); the tool only exists in a container where news is loaded.
            'ext-news-type-default',
            [self::TABLE],
        );
    }

    /**
     * Everything the creation needs, resolved and authorised — or the
     * refusal message that stops it. One method for execute() and
     * previewCall(): the approver must read the record the write produces.
     *
     * @param array<string, mixed> $arguments
     *
     * @return array{pid:int, folderTitle:string, title:string, teaser:string|null, bodytext:string|null, author:string|null, datetime:int|null, hiddenField:non-empty-string}|string
     */
    private function plan(array $arguments, BackendUserAuthentication $user): array|string
    {
        $unknown = $this->refuseUnknownArguments($arguments);
        if ($unknown !== null) {
            return $unknown;
        }

        $pid = $this->int($arguments['pid'] ?? 0);
        if ($pid < 1) {
            return 'Refused: "pid" must be the positive uid of exactly one storage folder.';
        }

        $title = $this->text($arguments, 'title', self::MAX_TITLE_LENGTH);
        if (is_string($title)) {
            return $title;
        }

        if ($title[0] === '') {
            return 'Refused: "title" is required and must not be empty — it is how a human recognises the draft.';
        }

        $optional = [];
        foreach (['teaser' => self::MAX_TEXT_LENGTH, 'bodytext' => self::MAX_TEXT_LENGTH, 'author' => self::MAX_AUTHOR_LENGTH] as $field => $max) {
            $optional[$field] = null;
            if (!array_key_exists($field, $arguments)) {
                continue;
            }

            $value = $this->text($arguments, $field, $max);
            if (is_string($value)) {
                return $value;
            }

            $optional[$field] = $value[0] === '' ? null : $value[0];
        }

        $datetime = $this->timestamp($arguments);
        if (is_string($datetime)) {
            return $datetime;
        }

        if ($datetime === null && $this->dateTimeRequired()) {
            return 'Refused: "datetime" is required — this installation requires a date on every news record '
                . '(extension setting dateTimeNotRequired is off).';
        }

        // The DataHandler refuses a non-admin without the table grant and
        // logs it; asked here so the preview does not show a card the write
        // would refuse.
        if (!$user->check('tables_modify', self::TABLE)) {
            return 'Refused: you may not create news records (no modify grant for ' . self::TABLE . ').';
        }

        // Default language only; a user without the right to edit it may not
        // draft one either.
        if (!$user->checkLanguageAccess(0)) {
            return 'Refused: you may not edit content in the default language.';
        }

        // The permission the DataHandler checks for a record in a table other
        // than `pages` is "edit content" on the page it is created under
        // (DataHandler::checkRecordInsertAccess()).
        $folder = $this->fetchRowByUid(self::PAGES_TABLE, $pid);
        if ($folder === null || !$user->doesUserHaveAccess($folder, Permission::CONTENT_EDIT)) {
            return self::NOT_PERMITTED;
        }

        if ($this->int($folder['doktype'] ?? 0) !== PageRepository::DOKTYPE_SYSFOLDER) {
            return sprintf(
                'Refused: page [%d] "%s" is not a storage folder; news records are created in a folder.',
                $pid,
                $this->excerpt($this->string($folder['title'] ?? '')),
            );
        }

        return [
            'pid'         => $pid,
            'folderTitle' => $this->string($folder['title'] ?? ''),
            'title'       => $title[0],
            'teaser'      => $optional['teaser'],
            'bodytext'    => $optional['bodytext'],
            'author'      => $optional['author'],
            'datetime'    => $datetime,
            'hiddenField' => $this->hiddenField(),
        ];
    }

    /**
     * Whether the installed EXT:news requires a date on every record: read
     * from its extension configuration at call time, the way its TCA reads it.
     */
    private function dateTimeRequired(): bool
    {
        return GeneralUtility::makeInstance(EmConfiguration::class)->getDateTimeRequired();
    }

    /**
     * The `datetime` argument as a UNIX timestamp; null when it is absent or
     * empty; a refusal message when it is present but not a date-time.
     *
     * @param array<string, mixed> $arguments
     */
    private function timestamp(array $arguments): int|string|null
    {
        $raw = $arguments['datetime'] ?? null;
        if ($raw === null || $raw === '') {
            return null;
        }

        $refusal = 'Refused: "datetime" must be an ISO 8601 date-time (such as 2026-09-22T10:00:00+02:00) or a '
            . 'UNIX timestamp in seconds from 2001 onwards.';

        if (is_int($raw) || (is_string($raw) && MathUtility::canBeInterpretedAsInteger($raw))) {
            $timestamp = (int)$raw;

            return $timestamp >= self::MIN_TIMESTAMP ? $timestamp : $refusal;
        }

        if (!is_string($raw) || preg_match(self::DATETIME_PATTERN, trim($raw)) !== 1) {
            return $refusal;
        }

        try {
            $timestamp = (new DateTimeImmutable(trim($raw)))->getTimestamp();
        } catch (Exception) {
            return $refusal;
        }

        return $timestamp > 0 ? $timestamp : $refusal;
    }

    /**
     * The user a write may be performed as — or the refusal that stops it:
     * an acting backend user at all (fail closed, with the neutral refusal),
     * the backend environment the DataHandler needs, the live workspace.
     */
    private function writableActingUser(ToolExecutionContext $context): BackendUserAuthentication|ToolResult
    {
        $user = $context->actingBackendUser();
        if (!$user instanceof BackendUserAuthentication) {
            return ToolResult::error(self::NOT_PERMITTED);
        }

        $missing = [];
        if ($this->tcaColumns() === null) {
            $missing[] = 'TCA';
        }

        if (!(($GLOBALS['LANG'] ?? null) instanceof LanguageService)) {
            $missing[] = 'language service';
        }

        if (!(($GLOBALS['BE_USER'] ?? null) instanceof BackendUserAuthentication)) {
            $missing[] = 'backend user';
        }

        if ($missing !== []) {
            return ToolResult::error(sprintf(
                'Refused: writing needs a full backend environment, and this process has no %s. '
                . 'Run this tool from a backend request rather than a bare worker process.',
                implode(' and no ', $missing),
            ));
        }

        if ($user->workspace !== 0) {
            return ToolResult::error(
                'Refused: this tool only writes to the live workspace. Switch out of the draft workspace and retry.',
            );
        }

        return $user;
    }

    /**
     * Create the record through the DataHandler as the given user and hand
     * back its uid — or the refusal, when the DataHandler complained or no
     * row came into being (a missing grant to create in the table is the
     * one failure it reports by silence).
     *
     * @param array<string, mixed> $record
     */
    private function createRecord(array $record, BackendUserAuthentication $user): int|ToolResult
    {
        $placeholder = StringUtility::getUniqueId('NEW');

        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
        $dataHandler->start([self::TABLE => [$placeholder => $record]], [], $user);
        $dataHandler->process_datamap();

        if ($dataHandler->errorLog !== []) {
            return ToolResult::error(sprintf(
                'The news record was refused by TYPO3: %s',
                $this->summariseErrors($dataHandler->errorLog),
            ));
        }

        $newUid = $this->int($dataHandler->substNEWwithIDs[$placeholder] ?? 0);

        return $newUid < 1
            ? ToolResult::error(
                'The news record was not created. The acting backend user is most likely missing the grant to '
                . 'create records in that folder.',
            )
            : $newUid;
    }

    /**
     * The written fields the stored row does not carry: `pid` and `type`,
     * which place the record, and every field the live TCA marks `exclude`,
     * the only ones the DataHandler drops for a missing grant. An integer
     * must match exactly; a text is dropped to empty, so a non-empty text
     * must have arrived non-empty — its bytes are not compared, because an
     * RTE-enabled field (`bodytext`, and `teaser` under `rteForTeaser`) is
     * stored in the RTE parser's form.
     *
     * @param array<string, mixed> $record
     * @param array<string, mixed> $stored
     *
     * @return list<string>
     */
    private function differingFields(array $record, array $stored): array
    {
        $columns   = $this->tcaColumns() ?? [];
        $differing = [];
        foreach ($record as $field => $expected) {
            $column  = $columns[$field] ?? null;
            $exclude = is_array($column) && ($column['exclude'] ?? false) === true;
            if (!$exclude && $field !== 'pid' && $field !== 'type') {
                continue;
            }

            $actual = $stored[$field] ?? null;
            $same   = is_int($expected)
                ? $this->int($actual) === $expected
                : ($expected === '' || $this->string($actual) !== '');
            if (!$same) {
                $differing[] = $field;
            }
        }

        return $differing;
    }

    /**
     * Delete a record this tool created but could not vouch for, reporting
     * whether it is gone. Through the DataHandler under the same acting
     * user, so the removal is in `sys_log` next to the creation.
     */
    private function discard(int $uid, BackendUserAuthentication $user): bool
    {
        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
        $dataHandler->start([], [self::TABLE => [$uid => ['delete' => 1]]], $user);
        $dataHandler->process_cmdmap();

        return $this->fetchRowByUid(self::TABLE, $uid) === null;
    }

    /**
     * Refuse an argument the tool does not know, or null when every key is
     * known. The WHOLE call is refused on the first unknown key: applying the
     * known half of a call the model got wrong leaves a record nobody asked
     * for.
     *
     * @param array<string, mixed> $arguments
     */
    private function refuseUnknownArguments(array $arguments): ?string
    {
        foreach (array_keys($arguments) as $key) {
            if (in_array($key, self::ARGUMENTS, true)) {
                continue;
            }

            return sprintf(
                'Refused: "%s" is not an argument of this tool. It creates one hidden news article; allowed: %s.',
                // Echoed back so the model can correct itself; it is a name
                // the model chose, not instance data.
                preg_replace('/[^A-Za-z0-9_]/', '', $this->string($key)) ?? '',
                implode(', ', self::ARGUMENTS),
            );
        }

        return null;
    }

    /**
     * A validated text argument WRAPPED in a one-element list, or a refusal
     * message — wrapped because both a valid value and a refusal are strings,
     * and a title that happened to read like a refusal must not be one.
     *
     * @param array<string, mixed> $arguments
     *
     * @return list{string}|string
     */
    private function text(array $arguments, string $field, int $max): array|string
    {
        $raw = $arguments[$field] ?? null;
        if ($raw === null) {
            return sprintf('Refused: "%s" is required.', $field);
        }

        if (!is_string($raw) && !is_numeric($raw)) {
            return sprintf('Refused: the value for "%s" must be a string.', $field);
        }

        $text = trim($this->string($raw));
        if (mb_strlen($text) > $max) {
            return sprintf('Refused: the value for "%s" exceeds %d characters.', $field, $max);
        }

        return [$text];
    }

    /**
     * The name of the news table's "hidden" column as the installation
     * declares it — the field that makes this a DRAFT tool.
     *
     * @return non-empty-string
     */
    private function hiddenField(): string
    {
        $tca  = $GLOBALS['TCA'] ?? null;
        $ctrl = is_array($tca) && is_array($tca[self::TABLE] ?? null) ? ($tca[self::TABLE]['ctrl'] ?? null) : null;
        $cols = is_array($ctrl) ? ($ctrl['enablecolumns'] ?? null) : null;
        $name = is_array($cols) ? ($cols['disabled'] ?? null) : null;

        return is_string($name) && $name !== '' ? $name : 'hidden';
    }

    /**
     * The news table's column definitions from the live TCA, or null when no
     * TCA is loaded.
     *
     * @return array<array-key, mixed>|null
     */
    private function tcaColumns(): ?array
    {
        $tca = $GLOBALS['TCA'] ?? null;
        if (!is_array($tca) || !is_array($tca[self::TABLE] ?? null)) {
            return null;
        }

        $columns = $tca[self::TABLE]['columns'] ?? null;

        return is_array($columns) ? $columns : null;
    }

    /**
     * A row by uid, or null when no undeleted row carries it. Only the
     * deleted restriction: a hidden record is still one an editor works on.
     *
     * @param non-empty-string $table
     *
     * @return array<string, mixed>|null
     */
    private function fetchRowByUid(string $table, int $uid): ?array
    {
        if ($uid < 1) {
            return null;
        }

        $queryBuilder = $this->connectionPool->getQueryBuilderForTable($table);
        $queryBuilder->getRestrictions()->removeAll()->add(GeneralUtility::makeInstance(DeletedRestriction::class));

        $row = $queryBuilder
            ->select('*')
            ->from($table)
            ->where(
                $queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($uid, Connection::PARAM_INT)),
            )
            ->executeQuery()
            ->fetchAssociative();

        return is_array($row) ? $row : null;
    }

    /**
     * The DataHandler's complaints, bounded in count and length. Only ever
     * shown to a caller that already passed the tool's own permission check.
     *
     * @param array<array-key, mixed> $errorLog
     */
    private function summariseErrors(array $errorLog): string
    {
        $messages = [];
        foreach (array_slice($errorLog, 0, self::MAX_ERRORS) as $entry) {
            $text = trim($this->string($entry));
            if ($text === '') {
                continue;
            }

            $messages[] = mb_substr($text, 0, self::MAX_ERROR_LENGTH);
        }

        return $messages === [] ? 'the record was not written.' : implode('; ', $messages);
    }

    /**
     * A value as it appears on the approval card: quoted, or `(empty)`.
     */
    private function quoted(string $value): string
    {
        return $value === '' ? '(empty)' : '"' . $this->excerpt($value) . '"';
    }

    /**
     * One line's worth of a value: whitespace collapsed and truncated.
     */
    private function excerpt(string $value): string
    {
        $flat = trim(preg_replace('/\s+/u', ' ', $value) ?? $value);

        return mb_strlen($flat) > self::PREVIEW_EXCERPT_LENGTH
            ? mb_substr($flat, 0, self::PREVIEW_EXCERPT_LENGTH) . '…'
            : $flat;
    }

    private function int(mixed $value): int
    {
        return is_numeric($value) ? (int)$value : 0;
    }

    private function string(mixed $value): string
    {
        return is_scalar($value) ? (string)$value : '';
    }
}
