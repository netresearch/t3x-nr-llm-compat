<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlmCompat\Bridge\News;

/**
 * Every text a line of the create_news_draft approval preview is built from,
 * one case per catalogue entry.
 *
 * The value is the `trans-unit` id in `Resources/Private/Language/locallang.xlf`
 * and `de.locallang.xlf`. A closed enum, so a test can walk the cases and fail
 * when one has no English or no German text, and the reverse: an
 * `approvalPreview.*` entry that no case names.
 *
 * The same scheme as nr-llm's ADR-213, kept in this package: nr-llm's
 * `ApprovalPreviewLabel` and `ApprovalPreviewTranslator` are `@internal`
 * (nr-llm ADR-127: only `@api` is covered by semver), and the label enum is
 * closed over nr-llm's own catalogue.
 */
enum NewsDraftPreviewLabel: string
{
    // --- Values ---
    case ValueQuoted   = 'approvalPreview.value.quoted';
    case ValueEmpty    = 'approvalPreview.value.empty';
    case ValueNotGiven = 'approvalPreview.value.notGiven';

    // --- What, where, the new state, the consequences ---
    case Heading     = 'approvalPreview.createNews.heading';
    case Location    = 'approvalPreview.createNews.location';
    case Title       = 'approvalPreview.createNews.title';
    case Teaser      = 'approvalPreview.createNews.teaser';
    case Text        = 'approvalPreview.createNews.text';
    case Date        = 'approvalPreview.createNews.date';
    case DateFormat  = 'approvalPreview.createNews.dateFormat';
    case Author      = 'approvalPreview.createNews.author';
    case Description = 'approvalPreview.createNews.description';
    case UrlPath     = 'approvalPreview.createNews.urlPath';
    case Type        = 'approvalPreview.createNews.type';
    case Language    = 'approvalPreview.createNews.language';
    case Visibility  = 'approvalPreview.createNews.visibility';
    case NotSet      = 'approvalPreview.createNews.notSet';
    case Impact      = 'approvalPreview.createNews.impact';

    // --- Technical details: the identifiers the lines above leave out ---
    case TechnicalDetails = 'approvalPreview.technical.details';
    case TechnicalFolder  = 'approvalPreview.technical.folder';
    case TechnicalTable   = 'approvalPreview.technical.table';

    /**
     * The key as TYPO3 resolves it.
     */
    public function reference(): string
    {
        return 'LLL:EXT:nr_llm_compat/Resources/Private/Language/locallang.xlf:' . $this->value;
    }
}
