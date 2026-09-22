<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlmCompat\Integration;

use GeorgRinger\News\Domain\Model\Dto\EmConfiguration;
use Netresearch\NrLlm\Service\Tool\ToolInterface;
use Netresearch\NrLlmCompat\Bridge\News\CreateNewsDraftTool;
use Netresearch\NrLlmCompat\Integration\Contract\MethodContract;

/**
 * Integration for georgringer/news (extension key "news") — the first
 * tool-provision integration (ADR-002).
 *
 * Nothing of EXT:news is intercepted: it makes no LLM calls. What it lacks
 * is an nr-llm writer for its records, and nr-llm's position is that an
 * extension brings its own writer or a bridge extension does so on its
 * behalf. This integration is that bridge: when Active, the compiler pass
 * registers {@see CreateNewsDraftTool} under nr-llm's `nr_llm.tool` tag, and
 * the assistant can draft a hidden news record in a storage folder.
 *
 * The supported range brackets the TCA shape the tool writes against —
 * `title` (required, max 255), `teaser`, `bodytext`, `datetime` (required
 * unless the extension setting `dateTimeNotRequired` is on), `author`,
 * `type` (0 = article, 1/2 = link types with a required URL field),
 * `sys_language_uid`, `hidden` as the disabled column, `path_segment`
 * generated from `title` — and the record types 0/1/2. Diffed at the tags
 * 12.0.0, 12.3.2, 13.0.0, 13.0.2, 14.0.0, 14.0.3 and 14.1.1: none of those
 * fields, their `required` flags or the record types differ. The PHP
 * contract is the extension setting the tool reads at call time.
 */
final class NewsIntegration implements IntegrationInterface
{
    private const EM_CONFIGURATION = EmConfiguration::class;

    public function getPackageName(): string
    {
        return 'georgringer/news';
    }

    public function getExtensionKey(): string
    {
        return 'news';
    }

    public function getSupportedVersions(): string
    {
        return '^12.0 || ^13.0 || ^14.0';
    }

    public function getStrategy(): IntegrationStrategy
    {
        return IntegrationStrategy::ToolProvision;
    }

    public function getCapabilities(): array
    {
        return ['create_news_draft'];
    }

    public function getServiceReplacements(): array
    {
        // For this strategy the key is the nr-llm contract the tool must
        // satisfy (the verifier checks it is implemented); the value is the
        // class the compiler pass registers as the tagged tool service.
        return [
            ToolInterface::class => CreateNewsDraftTool::class,
        ];
    }

    public function getClassContracts(): array
    {
        return [];
    }

    public function getMethodContracts(): array
    {
        return [
            // Read at call time to decide whether "datetime" is mandatory;
            // constructed without arguments, so it reads the live extension
            // configuration exactly as the news TCA does.
            new MethodContract(self::EM_CONFIGURATION, '__construct', ['array'], null),
            new MethodContract(self::EM_CONFIGURATION, 'getDateTimeRequired', [], 'bool'),
        ];
    }

    public function getPropertyContracts(): array
    {
        return [];
    }
}
