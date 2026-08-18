<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlmCompat\Tests\Functional;

use In2code\Texter\Controller\AjaxController;
use In2code\Texter\Domain\Repository\Llm\RepositoryInterface;
use ReflectionProperty;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * Base for functional tests against the REAL, unmodified texter package
 * installed from Packagist.
 */
abstract class AbstractTexterTestCase extends FunctionalTestCase
{
    protected array $testExtensionsToLoad = [
        'netresearch/nr-vault',
        'netresearch/nr-llm',
        'in2code/texter',
        'netresearch/nr-llm-compat',
        __DIR__ . '/Fixtures/Extensions/nr_llm_compat_fake',
    ];

    protected array $coreExtensionsToLoad = [
        'extbase',
        'fluid',
    ];

    /**
     * texter's ext_conf_template defaults (3.0.0) with an EMPTY Gemini API
     * key: the definition of transparent interception is that the extension
     * works without its own provider credential.
     *
     * @var array<string, string>
     */
    protected const TEXTER_CONFIGURATION = [
        'promptPrefix' => '',
        'geminiApiKey' => '',
        'geminiModel' => '',
    ];

    /**
     * The repository instance texter's AJAX controller actually received —
     * resolved through the controller, exactly as a backend request would.
     * The factory behind the RepositoryInterface service reads the official
     * llmRepositoryClass hook at this point.
     */
    protected function repositoryInjectedIntoAjaxController(): RepositoryInterface
    {
        $controller = $this->get(AjaxController::class);
        self::assertInstanceOf(AjaxController::class, $controller);

        $property = new ReflectionProperty(AjaxController::class, 'llmRepository');
        $repository = $property->getValue($controller);
        self::assertInstanceOf(RepositoryInterface::class, $repository);

        return $repository;
    }
}
