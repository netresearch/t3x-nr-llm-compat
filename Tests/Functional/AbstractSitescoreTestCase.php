<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlmCompat\Tests\Functional;

use In2code\Sitescore\Domain\Repository\Llm\RepositoryInterface;
use In2code\Sitescore\Domain\Service\AnalysisService;
use ReflectionProperty;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * Base for functional tests against the REAL, unmodified sitescore package
 * installed from Packagist.
 */
abstract class AbstractSitescoreTestCase extends FunctionalTestCase
{
    protected array $testExtensionsToLoad = [
        'netresearch/nr-vault',
        'netresearch/nr-llm',
        'in2code/sitescore',
        'netresearch/nr-llm-compat',
        __DIR__ . '/Fixtures/Extensions/nr_llm_compat_fake',
    ];

    protected array $coreExtensionsToLoad = [
        'extbase',
        'fluid',
    ];

    /**
     * sitescore's ext_conf_template defaults (3.1.0) with an EMPTY Gemini API
     * key: the definition of transparent interception is that the extension
     * works without its own provider credential.
     *
     * @var array<string, string>
     */
    protected const SITESCORE_CONFIGURATION = [
        'apiKey'      => '',
        'geminiModel' => '',
    ];

    /**
     * The repository instance sitescore's AnalysisService actually received,
     * resolved through the service exactly as the page module's analysis
     * request would. The factory behind the RepositoryInterface service reads
     * the official llmRepositoryClass hook at this point.
     */
    protected function repositoryInjectedIntoAnalysisService(): RepositoryInterface
    {
        $service = $this->get(AnalysisService::class);
        self::assertInstanceOf(AnalysisService::class, $service);

        $property   = new ReflectionProperty(AnalysisService::class, 'llmRepository');
        $repository = $property->getValue($service);
        self::assertInstanceOf(RepositoryInterface::class, $repository);

        return $repository;
    }
}
