<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlmCompat\Tests\Unit\Bridge\AiFilemetadata;

use Closure;
use Mfd\Ai\FileMetadata\Domain\Dto\TokenUsageResult;
use Mfd\Ai\FileMetadata\Event\ModifyRequestDataEvent;
use Mfd\Ai\FileMetadata\Services\TokenUsageService;
use Netresearch\NrLlm\Domain\Model\UsageStatistics;
use Netresearch\NrLlm\Domain\Model\VisionResponse;
use Netresearch\NrLlm\Testing\FakeVisionService;
use Netresearch\NrLlmCompat\Bridge\AiFilemetadata\OpenAiClient;
use Netresearch\NrLlmCompat\Exception\UnmappableRequestChangeException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use RuntimeException;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;
use UnexpectedValueException;

#[CoversClass(OpenAiClient::class)]
final class OpenAiClientTest extends UnitTestCase
{
    private const ORIGINAL_DEFAULT_PROMPT = "Create an alternative text for this image to be used on websites for visually impaired people who cannot see the image.\nFocus on the image's main content and ignore all elements in the image not relevant to understand its message.\nThe text should not exceed 50 words.";

    private FakeVisionService $vision;

    private TokenUsageService&MockObject $usageTracker;

    protected function setUp(): void
    {
        parent::setUp();
        $this->vision                         = new FakeVisionService();
        $this->vision->analyzeImageFullResult = new VisionResponse(
            '"  A red bicycle leaning on a wall.  "',
            'served-model',
            new UsageStatistics(120, 30, 150),
        );
        $this->usageTracker = $this->createMock(TokenUsageService::class);
    }

    /**
     * @param array<string, string>                        $settings
     * @param (Closure(ModifyRequestDataEvent): void)|null $listener a ModifyRequestDataEvent listener
     */
    private function createSubject(
        array $settings = ['altTextPrompt' => '', 'temperature' => ''],
        ?Closure $listener = null,
        ?LoggerInterface $logger = null,
    ): OpenAiClient {
        $configuration = self::createStub(ExtensionConfiguration::class);
        $configuration->method('get')->willReturnCallback(
            static fn (string $extension, string $path = ''): string => $settings[$path] ?? '',
        );

        return new OpenAiClient(
            $this->vision,
            $configuration,
            $logger ?? new NullLogger(),
            $this->usageTracker,
            new class ($listener) implements EventDispatcherInterface {
                /**
                 * @param (Closure(ModifyRequestDataEvent): void)|null $listener
                 */
                public function __construct(private readonly ?Closure $listener) {}

                public function dispatch(object $event): object
                {
                    if ($this->listener instanceof Closure && $event instanceof ModifyRequestDataEvent) {
                        ($this->listener)($event);
                    }

                    return $event;
                }
            },
        );
    }

    private function requireRequestEvent(): void
    {
        if (!class_exists(ModifyRequestDataEvent::class)) {
            self::markTestSkipped('The installed ai_filemetadata predates ModifyRequestDataEvent (1.7.1).');
        }
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
        self::assertNotNull($call['options']);
        self::assertNull($call['options']->getTemperature());
        self::assertSame('ai_filemetadata', $call['options']->getCallerSourceExtension());
        self::assertSame('buildAltText', $call['options']->getCallerSourceOperation());
    }

    /**
     * An empty image can never be described: '' marks the file as done on
     * every version, so ai:generate-alt-texts does not stop at it on every run.
     */
    #[Test]
    public function anEmptyImageGetsNoAltTextAndNoProviderCall(): void
    {
        $this->usageTracker->expects(self::never())->method('track');
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('warning')->with(self::stringContains('the image is empty'));

        $altText = $this->createSubject(logger: $logger)->buildAltText('', 'de_DE', 'upload', 42);

        self::assertSame('', $altText);
        self::assertCount(0, $this->vision->analyzeImageFullCalls);
    }

    /**
     * The original dispatches ModifyRequestDataEvent before anything can fail.
     */
    #[Test]
    public function anEmptyImageStillDispatchesTheRequestEventFrom171(): void
    {
        $this->requireRequestEvent();
        $this->usageTracker->expects(self::never())->method('track');
        $calls = 0;

        $altText = $this->createSubject(
            listener: static function (ModifyRequestDataEvent $event) use (&$calls): void {
                ++$calls;
            },
        )->buildAltText('', 'de_DE', 'upload', 42);

        self::assertSame('', $altText);
        self::assertSame(1, $calls);
        self::assertCount(0, $this->vision->analyzeImageFullCalls);
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
            self::callback(static fn (TokenUsageResult $usage): bool => $usage->inputTokens === 120
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

    #[Test]
    public function listenersSeeTheOriginalsRequestData(): void
    {
        $this->requireRequestEvent();
        $this->usageTracker->expects(self::once())->method('track');
        $seen = null;

        $this->createSubject(
            ['altTextPrompt' => '', 'temperature' => '0.3', 'model' => ''],
            static function (ModifyRequestDataEvent $event) use (&$seen): void {
                $seen = $event->getRequestData();
            },
        )->buildAltText('bytes');

        self::assertSame(
            [
                'model'    => 'gpt-4o-mini',
                'messages' => [
                    ['role' => 'system', 'content' => self::ORIGINAL_DEFAULT_PROMPT],
                    [
                        'role'    => 'user',
                        'content' => [
                            ['type' => 'text', 'text' => 'Generate alt text.'],
                            ['type' => 'image_url', 'image_url' => ['url' => 'data:image/jpeg;base64,' . base64_encode('bytes')]],
                        ],
                    ],
                ],
                'temperature' => 0.3,
            ],
            $seen,
        );
    }

    #[Test]
    public function aListenerThatChangesNothingSendsTheSameRequestAsNoListener(): void
    {
        $this->requireRequestEvent();
        $this->usageTracker->expects(self::exactly(2))->method('track');
        $settings = ['altTextPrompt' => '', 'temperature' => '0.3', 'model' => 'gpt-4.1'];

        $this->createSubject($settings)->buildAltText('bytes');
        $this->createSubject(
            $settings,
            static function (ModifyRequestDataEvent $event): void {
                $event->setRequestData($event->getRequestData());
            },
        )->buildAltText('bytes');

        self::assertCount(2, $this->vision->analyzeImageFullCalls);
        [$withoutListener, $withListener] = $this->vision->analyzeImageFullCalls;
        self::assertSame($withoutListener['prompt'], $withListener['prompt']);
        self::assertNotNull($withoutListener['options']);
        self::assertNotNull($withListener['options']);
        self::assertSame($withoutListener['options']->toArray(), $withListener['options']->toArray());
        self::assertSame(['temperature' => 0.3], $withListener['options']->toArray());
    }

    #[Test]
    public function passesOnAListenerChangeOfThePrompt(): void
    {
        $this->requireRequestEvent();
        $this->usageTracker->expects(self::once())->method('track');

        $this->createSubject(
            ['altTextPrompt' => '', 'temperature' => '0.3'],
            static function (ModifyRequestDataEvent $event): void {
                $data                           = $event->getRequestData();
                $data['messages'][0]['content'] = 'Describe the product shown.';
                $event->setRequestData($data);
            },
        )->buildAltText('bytes');

        $call = $this->vision->analyzeImageFullCalls[0];
        self::assertSame('Describe the product shown.', $call['prompt']);
        self::assertNotNull($call['options']);
        self::assertNull($call['options']->getDetailLevel());
        self::assertSame(0.3, $call['options']->getTemperature());
        self::assertNull($call['options']->getMaxTokens());
        self::assertSame('ai_filemetadata', $call['options']->getCallerSourceExtension());
    }

    #[Test]
    public function aNumericallyEqualTemperatureIsNoChange(): void
    {
        $this->requireRequestEvent();
        $this->usageTracker->expects(self::once())->method('track');

        $this->createSubject(
            ['altTextPrompt' => '', 'temperature' => '1'],
            static function (ModifyRequestDataEvent $event): void {
                $event->setRequestData([...$event->getRequestData(), 'temperature' => 1]);
            },
        )->buildAltText('bytes');

        $options = $this->vision->analyzeImageFullCalls[0]['options'];
        self::assertNotNull($options);
        self::assertSame(1.0, $options->getTemperature());
    }

    #[Test]
    public function passesOnAPromptWrittenAsASingleTextContentPart(): void
    {
        $this->requireRequestEvent();
        $this->usageTracker->expects(self::once())->method('track');

        $this->createSubject(
            listener: static function (ModifyRequestDataEvent $event): void {
                $data = $event->getRequestData();
                self::assertIsArray($data['messages']);
                self::assertIsArray($data['messages'][0]);
                $data['messages'][0]['content'] = [['type' => 'text', 'text' => 'Describe the product shown.']];
                $event->setRequestData($data);
            },
        )->buildAltText('bytes');

        self::assertSame('Describe the product shown.', $this->vision->analyzeImageFullCalls[0]['prompt']);
    }

    #[Test]
    public function logsThePromptThatIsActuallySent(): void
    {
        $this->requireRequestEvent();
        $this->usageTracker->expects(self::once())->method('track');
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('info')->with('Prompt: Describe the product shown.');

        $this->createSubject(
            listener: static function (ModifyRequestDataEvent $event): void {
                $data = $event->getRequestData();
                self::assertIsArray($data['messages']);
                self::assertIsArray($data['messages'][0]);
                $data['messages'][0]['content'] = 'Describe the product shown.';
                $event->setRequestData($data);
            },
            logger: $logger,
        )->buildAltText('bytes');
    }

    /**
     * nr-llm sends "auto" when no detail level is set
     * (VisionService::analyzeImageFull(), v0.55.1), so "auto" changes nothing.
     */
    #[Test]
    public function anAutoDetailLevelIsNoChange(): void
    {
        $this->requireRequestEvent();
        $this->usageTracker->expects(self::once())->method('track');

        $this->createSubject(
            listener: static function (ModifyRequestDataEvent $event): void {
                $data = $event->getRequestData();
                self::assertIsArray($data['messages']);
                self::assertIsArray($data['messages'][1]);
                self::assertIsArray($data['messages'][1]['content']);
                self::assertIsArray($data['messages'][1]['content'][1]);
                self::assertIsArray($data['messages'][1]['content'][1]['image_url']);
                $data['messages'][1]['content'][1]['image_url']['detail'] = 'auto';
                $event->setRequestData($data);
            },
        )->buildAltText('bytes');

        $call = $this->vision->analyzeImageFullCalls[0];
        self::assertSame(self::ORIGINAL_DEFAULT_PROMPT, $call['prompt']);
        self::assertNotNull($call['options']);
        self::assertNull($call['options']->getDetailLevel());
    }

    /**
     * The original reads the event the dispatcher returns (PSR-14).
     */
    #[Test]
    public function readsTheRequestDataOfTheEventTheDispatcherReturns(): void
    {
        $this->requireRequestEvent();
        $this->usageTracker->expects(self::once())->method('track');
        $configuration = self::createStub(ExtensionConfiguration::class);
        $configuration->method('get')->willReturn('');

        $subject = new OpenAiClient(
            $this->vision,
            $configuration,
            new NullLogger(),
            $this->usageTracker,
            new class implements EventDispatcherInterface {
                public function dispatch(object $event): object
                {
                    if (!$event instanceof ModifyRequestDataEvent) {
                        return $event;
                    }

                    $data = $event->getRequestData();
                    $this->assertIsArray($data['messages']);
                    $this->assertIsArray($data['messages'][0]);
                    $data['messages'][0]['content'] = 'Describe the product shown.';

                    return new ModifyRequestDataEvent($data);
                }

                private function assertIsArray(mixed $value): void
                {
                    if (!is_array($value)) {
                        throw new RuntimeException('Unexpected request data shape.', 1795800002);
                    }
                }
            },
        );

        $subject->buildAltText('bytes');

        self::assertSame('Describe the product shown.', $this->vision->analyzeImageFullCalls[0]['prompt']);
    }

    #[Test]
    public function aListenerRebuildingTheRequestInAnotherKeyOrderChangesNothing(): void
    {
        $this->requireRequestEvent();
        $this->usageTracker->expects(self::once())->method('track');

        $this->createSubject(
            ['altTextPrompt' => '', 'temperature' => '0.3'],
            static function (ModifyRequestDataEvent $event): void {
                $data = $event->getRequestData();
                self::assertIsArray($data['messages']);
                $messages = [];
                foreach ($data['messages'] as $message) {
                    self::assertIsArray($message);
                    $messages[] = array_reverse($message, true);
                }

                $event->setRequestData(['temperature' => $data['temperature'], 'messages' => $messages, 'model' => $data['model']]);
            },
        )->buildAltText('bytes');

        $call = $this->vision->analyzeImageFullCalls[0];
        self::assertSame(self::ORIGINAL_DEFAULT_PROMPT, $call['prompt']);
    }

    /**
     * @return iterable<string, array{Closure(array<mixed>): array<mixed>, string}>
     */
    public static function listenerChangesNrLlmDoesNotForward(): iterable
    {
        yield 'changed temperature' => [static fn (array $data): array => [...$data, 'temperature' => 0.9], 'temperature'];
        yield 'removed temperature' => [
            static function (array $data): array {
                unset($data['temperature']);

                return $data;
            },
            'temperature',
        ];
        yield 'max_tokens' => [static fn (array $data): array => [...$data, 'max_tokens' => 120], 'max_tokens'];
        yield 'max_completion_tokens' => [static fn (array $data): array => [...$data, 'max_completion_tokens' => 120], 'max_completion_tokens'];
        yield 'image detail level' => [
            static function (array $data): array {
                self::assertIsArray($data['messages']);
                self::assertIsArray($data['messages'][1]);
                self::assertIsArray($data['messages'][1]['content']);
                self::assertIsArray($data['messages'][1]['content'][1]);
                self::assertIsArray($data['messages'][1]['content'][1]['image_url']);
                $data['messages'][1]['content'][1]['image_url']['detail'] = 'high';

                return $data;
            },
            'messages',
        ];
        yield 'changed image URL' => [
            static function (array $data): array {
                self::assertIsArray($data['messages']);
                self::assertIsArray($data['messages'][1]);
                self::assertIsArray($data['messages'][1]['content']);
                self::assertIsArray($data['messages'][1]['content'][1]);
                $data['messages'][1]['content'][1]['image_url'] = ['url' => 'https://example.org/other.jpg'];

                return $data;
            },
            'messages',
        ];
        yield 'extra message' => [
            static function (array $data): array {
                self::assertIsArray($data['messages']);
                $data['messages'][] = ['role' => 'user', 'content' => 'Mention the brand.'];

                return $data;
            },
            'messages',
        ];
        yield 'empty prompt' => [
            static function (array $data): array {
                self::assertIsArray($data['messages']);
                self::assertIsArray($data['messages'][0]);
                $data['messages'][0]['content'] = '';

                return $data;
            },
            'messages',
        ];
        yield 'whitespace-only prompt' => [
            static function (array $data): array {
                self::assertIsArray($data['messages']);
                self::assertIsArray($data['messages'][0]);
                $data['messages'][0]['content'] = " \n\t ";

                return $data;
            },
            'messages',
        ];
        yield 'image detail level low' => [
            static function (array $data): array {
                self::assertIsArray($data['messages']);
                self::assertIsArray($data['messages'][1]);
                self::assertIsArray($data['messages'][1]['content']);
                self::assertIsArray($data['messages'][1]['content'][1]);
                self::assertIsArray($data['messages'][1]['content'][1]['image_url']);
                $data['messages'][1]['content'][1]['image_url']['detail'] = 'low';

                return $data;
            },
            'messages',
        ];
    }

    /**
     * nr-llm's vision providers send no temperature (netresearch/t3x-nr-llm#1474),
     * only the OpenAI-style ones send an image detail level, and without a
     * finish reason (#1473) a token limit could let a truncated alt text
     * through.
     *
     * @param Closure(array<mixed>): array<mixed> $change
     */
    #[Test]
    #[DataProvider('listenerChangesNrLlmDoesNotForward')]
    public function refusesListenerChangesNrLlmDoesNotForward(Closure $change, string $key): void
    {
        $this->requireRequestEvent();
        $this->usageTracker->expects(self::never())->method('track');

        try {
            $this->createSubject(
                ['altTextPrompt' => '', 'temperature' => '0.3'],
                static function (ModifyRequestDataEvent $event) use ($change): void {
                    $event->setRequestData($change($event->getRequestData()));
                },
            )->buildAltText('bytes');
            self::fail('Expected the request to be refused.');
        } catch (UnmappableRequestChangeException $exception) {
            self::assertStringContainsString('(' . $key . ')', $exception->getMessage());
        }

        self::assertCount(0, $this->vision->analyzeImageFullCalls);
    }

    #[Test]
    public function refusesTheRequestWhenAListenerAddsATool(): void
    {
        $this->requireRequestEvent();
        $this->usageTracker->expects(self::never())->method('track');
        $logger = $this->createMock(LoggerInterface::class);
        // Debug, not warning: the exception carries the same keys.
        $logger->expects(self::never())->method('warning');
        $logger->expects(self::once())->method('debug')->with(
            self::stringContains('nr-llm cannot express'),
            self::callback(static fn (array $context): bool => ($context['keys'] ?? null) === 'tools'
                && ($context['fileUid'] ?? null) === 42),
        );

        try {
            $this->createSubject(
                listener: static function (ModifyRequestDataEvent $event): void {
                    $event->addTool(['type' => 'web_search']);
                },
                logger: $logger,
            )->buildAltText('bytes', null, 'upload', 42);
            self::fail('Expected the request to be refused.');
        } catch (UnmappableRequestChangeException $exception) {
            self::assertStringContainsString('(tools)', $exception->getMessage());
        }

        self::assertCount(0, $this->vision->analyzeImageFullCalls);
    }

    #[Test]
    public function refusesTheRequestWhenAListenerChangesTheModelOrTheUserMessage(): void
    {
        $this->requireRequestEvent();
        $this->usageTracker->expects(self::never())->method('track');

        try {
            $this->createSubject(
                listener: static function (ModifyRequestDataEvent $event): void {
                    $data                                      = $event->getRequestData();
                    $data['model']                             = 'gpt-4.1';
                    $data['messages'][1]['content'][0]['text'] = 'Describe it in a poem.';
                    $event->setRequestData($data);
                },
            )->buildAltText('bytes');
            self::fail('Expected the request to be refused.');
        } catch (UnmappableRequestChangeException $exception) {
            self::assertStringContainsString('(model, messages)', $exception->getMessage());
        }

        self::assertCount(0, $this->vision->analyzeImageFullCalls);
    }

    #[Test]
    public function anEmptyCompletionThrowsFrom171AfterTrackingTheUsage(): void
    {
        $this->requireRequestEvent();
        $this->vision->analyzeImageFullResult = new VisionResponse('"  "', 'served-model', new UsageStatistics(120, 0, 120));
        $this->usageTracker->expects(self::once())->method('track');

        try {
            $this->createSubject()->buildAltText('bytes');
            self::fail('Expected an empty completion to throw.');
        } catch (UnexpectedValueException $exception) {
            self::assertStringContainsString('Did not receive a usable alt text', $exception->getMessage());
        }
    }

    #[Test]
    public function anEmptyCompletionReturnsAnEmptyAltTextBefore171(): void
    {
        if (class_exists(ModifyRequestDataEvent::class)) {
            self::markTestSkipped('The installed ai_filemetadata is 1.7.1 or later.');
        }

        $this->vision->analyzeImageFullResult = new VisionResponse('', 'served-model', new UsageStatistics(120, 0, 120));
        $this->usageTracker->expects(self::once())->method('track');

        self::assertSame('', $this->createSubject()->buildAltText('bytes'));
    }
}
