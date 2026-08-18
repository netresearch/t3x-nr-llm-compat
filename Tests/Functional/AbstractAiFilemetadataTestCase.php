<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlmCompat\Tests\Functional;

use Mfd\Ai\FileMetadata\Api\OpenAiClient;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * Base for functional tests against the REAL, unmodified ai-filemetadata
 * package installed from Packagist.
 */
abstract class AbstractAiFilemetadataTestCase extends FunctionalTestCase
{
    protected array $testExtensionsToLoad = [
        'netresearch/nr-vault',
        'netresearch/nr-llm',
        'mfd/ai-filemetadata',
        'netresearch/nr-llm-compat',
        __DIR__ . '/Fixtures/Extensions/nr_llm_compat_fake',
        __DIR__ . '/Fixtures/Extensions/nr_llm_compat_probe_filemetadata',
    ];

    protected array $coreExtensionsToLoad = [
        'extbase',
        'fluid',
        'filemetadata',
        'dashboard',
    ];

    /**
     * ai_filemetadata's ext_conf_template defaults (1.6.2) with an EMPTY
     * OpenAI API key: the definition of transparent interception is that the
     * extension works without its own provider credential.
     *
     * @var array<string, string>
     */
    protected const AI_FILEMETADATA_CONFIGURATION = [
        'apiKey' => '',
        'organizationId' => '',
        'projectId' => '',
        'apiBaseUri' => 'https://api.openai.com/v1',
        'requestTimeout' => '60',
        'connectTimeout' => '10',
        'model' => 'gpt-4o-mini',
        'imageResizing' => '512',
        'generateAltTextOnFileUpload' => '1',
        'generateAltTextInFrontend' => '1',
        'enableTokenTracking' => '0',
        'temperature' => '',
        'altTextPrompt' => '',
    ];

    /**
     * The OpenAiClient instance the compiled container actually wires,
     * retrieved through the probe fixture's public alias (the service itself
     * is private).
     */
    protected function wiredOpenAiClient(): OpenAiClient
    {
        $client = $this->getContainer()->get('nr_llm_compat_tests.filemetadata_openai_client');
        self::assertInstanceOf(OpenAiClient::class, $client);

        return $client;
    }
}
