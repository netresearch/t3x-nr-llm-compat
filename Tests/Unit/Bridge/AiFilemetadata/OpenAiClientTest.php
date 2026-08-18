<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlmCompat\Tests\Unit\Bridge\AiFilemetadata;

use Mfd\Ai\FileMetadata\Domain\Dto\TokenUsageResult;
use Mfd\Ai\FileMetadata\Services\TokenUsageService;
use Netresearch\NrLlm\Domain\Model\UsageStatistics;
use Netresearch\NrLlm\Domain\Model\VisionResponse;
use Netresearch\NrLlm\Testing\FakeVisionService;
use Netresearch\NrLlmCompat\Bridge\AiFilemetadata\OpenAiClient;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\NullLogger;
use RuntimeException;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

#[CoversClass(OpenAiClient::class)]
final class OpenAiClientTest extends UnitTestCase
{
    private const ORIGINAL_DEFAULT_PROMPT = "Create an alternative text for this image to be used on websites for visually impaired people who cannot see the image.\nFocus on the image's main content and ignore all elements in the image not relevant to understand its message.\nThe text should not exceed 50 words.";

    private FakeVisionService $vision;

    private TokenUsageService&MockObject $usageTracker;

    protected function setUp(): void
    {
        parent::setUp();
        $this->vision = new FakeVisionService();
        $this->vision->analyzeImageFullResult = new VisionResponse(
            '"  A red bicycle leaning on a wall.  "',
            'served-model',
            new UsageStatistics(120, 30, 150),
        );
        $this->usageTracker = $this->createMock(TokenUsageService::class);
    }

    /**
     * @param array<string, string> $settings
     */
    private function createSubject(array $settings = ['altTextPrompt' => '', 'temperature' => '']): OpenAiClient
    {
        $configuration = self::createStub(ExtensionConfiguration::class);
        $configuration->method('get')->willReturnCallback(
            static fn(string $extension, string $path = ''): string => $settings[$path] ?? '',
        );

        return new OpenAiClient(
            $this->vision,
            $configuration,
            new NullLogger(),
            $this->usageTracker,
        );
    }

    #[Test]
    public function routesTheImageThroughNrLlmWithTheOriginalDefaultPrompt(): void
    {
        $this->usageTracker->expects(self::once())->method('track');

        $altText = $this->createSubject()->buildAltText('raw-image-bytes');

        self::assertSame('A red bicycle leaning on a wall.', $altText);
        self::assertCount(1, $this->vision->analyzeImageFullCalls);
        $call = $this->vision->analyzeImageFullCalls[0];
        self::assertSame('data:image/jpeg;base64,' . base64_encode('raw-image-bytes'), $call['imageUrl']);
        self::assertSame(self::ORIGINAL_DEFAULT_PROMPT, $call['prompt']);
        self::assertNull($call['options']);
    }

    #[Test]
    public function usesTheConfiguredPromptWithNewlineExpansionAndLocaleSuffix(): void
    {
        $this->usageTracker->expects(self::once())->method('track');

        $this->createSubject(['altTextPrompt' => 'Describe the image.\nBe brief.', 'temperature' => ''])
            ->buildAltText('bytes', 'de_DE');

        self::assertSame(
            "Describe the image.\nBe brief.\n Answer in German.",
            $this->vision->analyzeImageFullCalls[0]['prompt'],
        );
    }

    #[Test]
    public function mirrorsTheUsageIntoTheExtensionsOwnTracker(): void
    {
        $this->usageTracker->expects(self::once())->method('track')->with(
            self::callback(static fn(TokenUsageResult $usage): bool => $usage->inputTokens === 120
                && $usage->outputTokens === 30
                && $usage->totalTokens === 150
                && $usage->model === 'served-model'),
            'backend',
            42,
            'de_DE',
        );

        $this->createSubject()->buildAltText('bytes', 'de_DE', 'backend', 42);
    }

    #[Test]
    public function passesAValidConfiguredTemperature(): void
    {
        $this->usageTracker->expects(self::once())->method('track');

        $this->createSubject(['altTextPrompt' => '', 'temperature' => '0.3'])->buildAltText('bytes');

        $options = $this->vision->analyzeImageFullCalls[0]['options'];
        self::assertNotNull($options);
        self::assertSame(0.3, $options->getTemperature());
    }

    #[Test]
    public function fallsBackToTheOriginalDefaultTemperatureOutsideItsAcceptedRange(): void
    {
        $this->usageTracker->expects(self::once())->method('track');

        $this->createSubject(['altTextPrompt' => '', 'temperature' => '5'])->buildAltText('bytes');

        $options = $this->vision->analyzeImageFullCalls[0]['options'];
        self::assertNotNull($options);
        self::assertSame(0.6, $options->getTemperature());
    }

    #[Test]
    public function failsClosedWhenNrLlmIsUnavailable(): void
    {
        $this->vision->throwable = new RuntimeException('no provider available');
        $this->usageTracker->expects(self::never())->method('track');

        try {
            $this->createSubject()->buildAltText('bytes');
            self::fail('Expected the nr-llm exception to propagate.');
        } catch (RuntimeException $exception) {
            self::assertSame('no provider available', $exception->getMessage());
        }
    }
}
