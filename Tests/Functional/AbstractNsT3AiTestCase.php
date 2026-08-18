<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlmCompat\Tests\Functional;

use NITSAN\NsT3Ai\Controller\T3AiController;
use NITSAN\NsT3Ai\Service\NsT3AiContentService;
use ReflectionProperty;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * Base for functional tests against the REAL, unmodified ns-t3ai package
 * installed from Packagist.
 */
abstract class AbstractNsT3AiTestCase extends FunctionalTestCase
{
    protected array $testExtensionsToLoad = [
        'netresearch/nr-vault',
        'netresearch/nr-llm',
        'nitsan/ns-t3ai',
        'netresearch/nr-llm-compat',
        __DIR__ . '/Fixtures/Extensions/nr_llm_compat_fake',
    ];

    protected array $coreExtensionsToLoad = [
        'extbase',
        'fluid',
    ];

    /**
     * ns_t3ai's ext_conf_template defaults (14.0.0) with an EMPTY OpenAI API
     * key: the definition of transparent interception is that the extension
     * works without its own provider credential.
     *
     * @var array<string, string>
     */
    protected const NS_T3AI_CONFIGURATION = [
        'apiKey' => '',
        'model' => 'gpt-4o',
        'openAiPromptPrefixPageTitle' => 'Act as an SEO expert and write five an optimized title tag for a web page about [Content]',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        // ns_t3ai's service constructor resolves the backend layout language
        // via BackendUtility::getModuleData(), which requires a logged-in
        // backend user — exactly as in the real backend context it runs in.
        $this->importCSVDataSet(__DIR__ . '/Fixtures/be_users.csv');
        $this->setUpBackendUser(1);
    }

    /**
     * The NsT3AiContentService instance the third-party AJAX controller
     * actually received — resolved through the controller (the service
     * itself is private in the compiled container).
     */
    protected function contentServiceInjectedIntoController(): NsT3AiContentService
    {
        $controller = $this->get(T3AiController::class);
        self::assertInstanceOf(T3AiController::class, $controller);

        $property = new ReflectionProperty(T3AiController::class, 'contentService');
        $service = $property->getValue($controller);
        self::assertInstanceOf(NsT3AiContentService::class, $service);

        return $service;
    }
}
