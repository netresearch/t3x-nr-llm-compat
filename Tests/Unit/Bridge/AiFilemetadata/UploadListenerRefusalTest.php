<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlmCompat\Tests\Unit\Bridge\AiFilemetadata;

use Mfd\Ai\FileMetadata\Event\ModifyRequestDataEvent;
use Mfd\Ai\FileMetadata\EventListener\EnrichFileMetadataAfterCreation;
use Mfd\Ai\FileMetadata\Services\ConfigurationService;
use Mfd\Ai\FileMetadata\Services\FalAdapter;
use Mfd\Ai\FileMetadata\Services\TokenUsageService;
use Netresearch\NrLlm\Testing\FakeVisionService;
use Netresearch\NrLlmCompat\Bridge\AiFilemetadata\OpenAiClient;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Resource\Event\AfterFileMetaDataCreatedEvent;
use TYPO3\CMS\Core\Resource\File;
use TYPO3\CMS\Core\Resource\FileRepository;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

/**
 * A refused request must not fail the upload that triggered it: ai_filemetadata
 * 1.7.1's own upload listener, unmodified, catches the bridge's exception, logs
 * it and stores no alternative.
 */
#[CoversClass(OpenAiClient::class)]
final class UploadListenerRefusalTest extends UnitTestCase
{
    #[Test]
    public function theUploadListenerOf171CatchesARefusedRequestAndStoresNoAltText(): void
    {
        if (!class_exists(ModifyRequestDataEvent::class)) {
            self::markTestSkipped('The installed ai_filemetadata predates ModifyRequestDataEvent (1.7.1).');
        }

        $vision = new FakeVisionService();
        $bridge = new OpenAiClient(
            $vision,
            self::createStub(ExtensionConfiguration::class),
            new NullLogger(),
            self::createStub(TokenUsageService::class),
            new class implements EventDispatcherInterface {
                public function dispatch(object $event): object
                {
                    if ($event instanceof ModifyRequestDataEvent) {
                        $event->addTool(['type' => 'web_search']);
                    }

                    return $event;
                }
            },
        );

        $file = self::createStub(File::class);
        $file->method('getExtension')->willReturn('png');
        $file->method('getUid')->willReturn(7);
        $file->method('getContents')->willReturn('image-bytes');

        $fileRepository = self::createStub(FileRepository::class);
        $fileRepository->method('findByUid')->willReturn($file);

        $configuration = self::createStub(ConfigurationService::class);
        $configuration->method('generateAltTextOnlyInBackend')->willReturn(false);
        $configuration->method('getGenerateAltTextOnFileUpload')->willReturn(true);
        $configuration->method('generateAltTextInFrontend')->willReturn(true);
        $configuration->method('getLanguageMappingForFile')->willReturn([0 => 'de_DE']);

        $falAdapter = self::createStub(FalAdapter::class);
        $falAdapter->method('resizeImage')->willReturn($file);

        $connectionPool = $this->createMock(ConnectionPool::class);
        $connectionPool->expects(self::never())->method('getQueryBuilderForTable');

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('warning')->with(
            self::stringContains('Alt text generation on upload failed'),
            self::callback(static fn (array $context): bool => is_string($context['message'] ?? null)
                && str_contains($context['message'], 'nr-llm cannot express (tools)')),
        );

        $listener = new EnrichFileMetadataAfterCreation(
            $connectionPool,
            $fileRepository,
            $bridge,
            self::createStub(SiteFinder::class),
            $configuration,
            $logger,
            $falAdapter,
        );

        $listener(new AfterFileMetaDataCreatedEvent(7, 11, ['alternative' => '']));

        self::assertCount(0, $vision->analyzeImageFullCalls);
    }
}
