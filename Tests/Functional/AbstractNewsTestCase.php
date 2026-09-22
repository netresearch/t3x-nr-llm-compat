<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlmCompat\Tests\Functional;

use Netresearch\NrLlm\Service\Tool\ToolInterface;
use Netresearch\NrLlm\Service\Tool\ToolRegistry;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * Base for functional tests against the REAL, unmodified news package
 * installed from Packagist.
 */
abstract class AbstractNewsTestCase extends FunctionalTestCase
{
    protected array $testExtensionsToLoad = [
        'netresearch/nr-vault',
        'netresearch/nr-llm',
        'georgringer/news',
        'netresearch/nr-llm-compat',
        __DIR__ . '/Fixtures/Extensions/nr_llm_compat_fake',
    ];

    protected array $coreExtensionsToLoad = [
        'extbase',
        'fluid',
    ];

    /**
     * news's ext_conf_template defaults (14.1.1) for the settings its TCA
     * reads while it is built; `dateTimeNotRequired` is the one the tool
     * reads at call time.
     *
     * @var array<string, string>
     */
    protected const NEWS_CONFIGURATION = [
        'prependAtCopy'          => '1',
        'rteForTeaser'           => '0',
        'contentElementRelation' => '1',
        'manualSorting'          => '0',
        'categoryRestriction'    => '',
        'dateTimeNotRequired'    => '0',
        'archiveDate'            => 'date',
        'slugBehaviour'          => 'unique',
    ];

    /**
     * The tool as nr-llm's registry — the consumer of the `nr_llm.tool`
     * tag — hands it out, or null when nothing registered under that name.
     */
    protected function toolFromRegistry(string $name): ?ToolInterface
    {
        $registry = $this->get(ToolRegistry::class);
        self::assertInstanceOf(ToolRegistry::class, $registry);

        return $registry->get($name);
    }
}
