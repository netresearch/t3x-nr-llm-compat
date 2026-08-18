<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlmCompat\Tests\Functional;

use Passionweb\AiSeoHelper\Controller\Ajax\AiController;
use Passionweb\AiSeoHelper\Service\ContentService;
use ReflectionProperty;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * Base for functional tests against the REAL, unmodified ai-seo-helper
 * package installed from Packagist — the reference proof that interception
 * needs no patch, fork or configuration change in the third-party extension.
 */
abstract class AbstractAiSeoHelperTestCase extends FunctionalTestCase
{
    protected array $testExtensionsToLoad = [
        'netresearch/nr-vault',
        'netresearch/nr-llm',
        'passionweb/ai-seo-helper',
        'netresearch/nr-llm-compat',
        __DIR__ . '/Fixtures/Extensions/nr_llm_compat_fake',
    ];

    protected array $coreExtensionsToLoad = [
        'extbase',
        'fluid',
        // ai_seo_helper's TCA override extends the seo_title/og_*/twitter_*
        // page columns, which EXT:seo provides.
        'seo',
    ];

    /**
     * ai_seo_helper's ext_conf_template defaults (0.7.2) with an EMPTY OpenAI
     * API key: the definition of transparent interception is that the
     * extension works without its own provider credential.
     *
     * @var array<string, string|array<string, string>>
     */
    protected const AI_SEO_HELPER_CONFIGURATION = [
        'openAiApiKey' => '',
        'openAiModel' => 'gpt-5.4-nano',
        'openAiTemperature' => '1',
        'openAiMaxTokens' => '275',
        'openAiTopP' => '1',
        'useUrlForRequest' => '0',
        'openAiPromptPrefixMetaDescription' => 'Extract five seo meta descriptions in a bullet point list, each seo meta description in one short sentence and with a maximum of 150 characters or less, for the content of',
        'openAiPromptPrefixPageTitle' => 'Suggest page title ideas in bullet point list for',
        'openAiPromptPrefixKeywords' => 'Extract seo keywords from this text. Return the result in a comma separated list.',
        'replaceTextKeywords' => 'SEO keywords:',
        'openAiPromptPrefixOgTitle' => 'Suggest Open Graph title ideas in bullet point list for',
        'openAiPromptPrefixOgDescription' => 'Extract five Open Graph descriptions in a bullet point list, each Open Graph description in one short sentence and with a maximum of 150 characters or less, for the content of',
        'openAiPromptPrefixTwitterTitle' => 'Suggest Twitter title ideas in bullet point list for',
        'openAiPromptPrefixTwitterDescription' => 'Extract five Twitter descriptions in a bullet point list, each Twitter description in one short sentence and with a maximum of 150 characters or less, for the content of',
        'openAiPromptPrefixAbstract' => 'Extract five summaries in a bullet point list, each summary in one short sentence and with a maximum of 150 characters or less, for the content of',
        'pageTitleForOgAndTwitter' => '0',
        'metaDescriptionForOgAndTwitter' => '0',
        'singleNewsDisplayPage' => '1',
        'openAiPromptPrefixNewsMetaDescription' => 'Extract five seo meta descriptions in a bullet point list, each seo meta description in one short sentence and with a maximum of 150 characters or less, for the content of',
        'openAiPromptPrefixNewsAlternativeTitle' => 'Suggest page title ideas in bullet point list for',
        'openAiPromptPrefixNewsKeywords' => 'Extract seo keywords from this news article. Return the result in a comma separated list.',
        'replaceTextNewsKeywords' => 'SEO keywords:',
    ];

    /**
     * The ContentService instance the third-party AJAX controller actually
     * received — resolved through the controller (the service itself is
     * private in the compiled container), exactly as a backend request would.
     */
    protected function contentServiceInjectedIntoAiController(): ContentService
    {
        $controller = $this->get(AiController::class);
        self::assertInstanceOf(AiController::class, $controller);

        $property = new ReflectionProperty(AiController::class, 'contentService');
        $service = $property->getValue($controller);
        self::assertInstanceOf(ContentService::class, $service);

        return $service;
    }
}
