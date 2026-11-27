<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlmCompat\Tests\Functional;

use Mfd\Ai\FileMetadata\Event\ModifyRequestDataEvent;
use Netresearch\NrLlm\Domain\Model\UsageStatistics;
use Netresearch\NrLlm\Domain\Model\VisionResponse;
use Netresearch\NrLlm\Service\Feature\VisionServiceInterface;
use Netresearch\NrLlm\Testing\FakeVisionService;
use Netresearch\NrLlmCompat\Exception\UnmappableRequestChangeException;
use Netresearch\NrLlmCompat\Tests\Functional\Fixtures\Listener\ConfigurableRequestDataListener;
use PHPUnit\Framework\Attributes\Test;

/**
 * A ModifyRequestDataEvent listener registered through the TYPO3 container,
 * as a site extension would, runs in the autowired bridge: a prompt change
 * reaches nr-llm, an added tool refuses the request.
 */
final class AiFilemetadataRequestListenerTest extends AbstractAiFilemetadataTestCase
{
    protected array $testExtensionsToLoad = [
        'netresearch/nr-vault',
        'netresearch/nr-llm',
        'mfd/ai-filemetadata',
        'netresearch/nr-llm-compat',
        __DIR__ . '/Fixtures/Extensions/nr_llm_compat_fake',
        __DIR__ . '/Fixtures/Extensions/nr_llm_compat_probe_filemetadata',
        __DIR__ . '/Fixtures/Extensions/nr_llm_compat_request_listener',
    ];

    protected array $configurationToUseInTestInstance = [
        'EXTENSIONS' => [
            'ai_filemetadata' => self::AI_FILEMETADATA_CONFIGURATION,
            'nr_llm_compat'   => [
                'integrations' => [
                    'ai_filemetadata' => '1',
                ],
            ],
        ],
    ];

    private bool $skippedBeforeSetUp = false;

    protected function setUp(): void
    {
        // The pinned 1.7.0 CI cell has no ModifyRequestDataEvent; the other
        // cells carry this proof.
        if (!class_exists(ModifyRequestDataEvent::class)) {
            $this->skippedBeforeSetUp = true;
            self::markTestSkipped('The installed ai_filemetadata predates ModifyRequestDataEvent (1.7.1).');
        }

        parent::setUp();
    }

    protected function tearDown(): void
    {
        // parent::tearDown() touches state that only parent::setUp()
        // initializes — which the skip above deliberately never reached.
        if ($this->skippedBeforeSetUp) {
            return;
        }

        parent::tearDown();
    }

    private function vision(): FakeVisionService
    {
        $vision = $this->get(VisionServiceInterface::class);
        self::assertInstanceOf(FakeVisionService::class, $vision);
        $vision->analyzeImageFullResult = new VisionResponse('A red bicycle.', 'served-model', new UsageStatistics(10, 5, 15));

        return $vision;
    }

    private function listener(): ConfigurableRequestDataListener
    {
        $listener = $this->get(ConfigurableRequestDataListener::class);
        self::assertInstanceOf(ConfigurableRequestDataListener::class, $listener);

        return $listener;
    }

    #[Test]
    public function aListenersPromptChangeReachesNrLlm(): void
    {
        $vision           = $this->vision();
        $listener         = $this->listener();
        $listener->change = static function (ModifyRequestDataEvent $event): void {
            $data = $event->getRequestData();
            self::assertIsArray($data['messages']);
            self::assertIsArray($data['messages'][0]);
            $data['messages'][0]['content'] = 'Describe the product shown.';
            $event->setRequestData($data);
        };

        $altText = $this->wiredOpenAiClient()->buildAltText('raw-image-bytes');

        self::assertSame(1, $listener->calls);
        self::assertSame('A red bicycle.', $altText);
        self::assertCount(1, $vision->analyzeImageFullCalls);
        self::assertSame('Describe the product shown.', $vision->analyzeImageFullCalls[0]['prompt']);
    }

    #[Test]
    public function aListenerAddingAToolRefusesTheRequest(): void
    {
        $vision           = $this->vision();
        $listener         = $this->listener();
        $listener->change = static function (ModifyRequestDataEvent $event): void {
            $event->addTool(['type' => 'web_search']);
        };

        try {
            $this->wiredOpenAiClient()->buildAltText('raw-image-bytes');
            self::fail('Expected the request to be refused.');
        } catch (UnmappableRequestChangeException $exception) {
            self::assertStringContainsString('(tools)', $exception->getMessage());
        }

        self::assertSame(1, $listener->calls);
        self::assertCount(0, $vision->analyzeImageFullCalls);
    }
}
