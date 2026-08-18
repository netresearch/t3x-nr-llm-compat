<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlmCompat\Tests\Unit\Bridge\Texter;

use In2code\Texter\Domain\Service\ConversationHistory;
use Netresearch\NrLlm\Domain\Model\CompletionResponse;
use Netresearch\NrLlm\Domain\Model\UsageStatistics;
use Netresearch\NrLlm\Service\LlmServiceManagerInterface;
use Netresearch\NrLlm\Service\Option\ChatOptions;
use Netresearch\NrLlmCompat\Bridge\Texter\NrLlmRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use RuntimeException;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Http\RequestFactory;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

#[CoversClass(NrLlmRepository::class)]
final class NrLlmRepositoryTest extends UnitTestCase
{
    private LlmServiceManagerInterface&Stub $llmServiceManager;

    private RequestFactory&MockObject $requestFactory;

    /** @var array<string, mixed> */
    private array $session = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->llmServiceManager = self::createStub(LlmServiceManagerInterface::class);
        $this->requestFactory = $this->createMock(RequestFactory::class);
        // The whole point of the bridge: getText() must never reach the
        // original Gemini HTTP transport.
        $this->requestFactory->expects(self::never())->method('request');

        // Real ConversationHistory on top of a stubbed backend-user session,
        // so the per-page history lifecycle is exercised, not faked.
        $beUser = self::createStub(BackendUserAuthentication::class);
        $beUser->method('getSessionData')->willReturnCallback(fn(string $key): mixed => $this->session[$key] ?? null);
        $beUser->method('setAndSaveSessionData')->willReturnCallback(function (string $key, mixed $data): void {
            $this->session[$key] = $data;
        });
        $GLOBALS['BE_USER'] = $beUser;

        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['texter'] = ['promptPrefix' => ''];
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['BE_USER'], $GLOBALS['TYPO3_CONF_VARS']);
        parent::tearDown();
    }

    private function createSubject(?LlmServiceManagerInterface $llmServiceManager = null): NrLlmRepository
    {
        return new NrLlmRepository(
            $llmServiceManager ?? $this->llmServiceManager,
            $this->requestFactory,
            new ConversationHistory(),
        );
    }

    private function response(string $text): CompletionResponse
    {
        return new CompletionResponse($text, 'test-model', new UsageStatistics(1, 1, 2));
    }

    #[Test]
    public function getTextSendsThePromptThroughNrLlmAndReturnsTheAnswer(): void
    {
        $llmServiceManager = $this->createMock(LlmServiceManagerInterface::class);
        $llmServiceManager->expects(self::once())->method('chat')
            ->with(
                [['role' => 'user', 'content' => 'Write about TYPO3']],
                self::callback(static fn(?ChatOptions $options): bool => $options instanceof ChatOptions
                    && $options->getCallerSourceExtension() === 'texter'
                    && $options->getCallerSourceOperation() === 'getText'),
            )
            ->willReturn($this->response('Generated text'));

        self::assertSame('Generated text', $this->createSubject($llmServiceManager)->getText('Write about TYPO3', '42'));
    }

    #[Test]
    public function getTextAppliesTheConfiguredPromptPrefixViaTheOriginalExtendPrompt(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['texter'] = ['promptPrefix' => 'Answer briefly.'];

        $llmServiceManager = $this->createMock(LlmServiceManagerInterface::class);
        $llmServiceManager->expects(self::once())->method('chat')
            ->with([['role' => 'user', 'content' => 'Answer briefly.' . PHP_EOL . 'Write about TYPO3']])
            ->willReturn($this->response('Generated text'));

        $this->createSubject($llmServiceManager)->getText('Write about TYPO3', '42');
    }

    #[Test]
    public function getTextCarriesThePerPageConversationHistoryIntoTheChat(): void
    {
        $subject = $this->createSubject();
        $calls = [];
        $this->llmServiceManager->method('chat')->willReturnCallback(function (array $messages) use (&$calls): CompletionResponse {
            $calls[] = $messages;

            return $this->response(count($calls) === 1 ? 'First answer' : 'Second answer');
        });

        $subject->getText('First question', '42');
        $subject->getText('Follow-up question', '42');

        self::assertCount(2, $calls);
        self::assertSame([
            ['role' => 'user', 'content' => 'First question'],
            ['role' => 'assistant', 'content' => 'First answer'],
            ['role' => 'user', 'content' => 'Follow-up question'],
        ], $calls[1]);
    }

    #[Test]
    public function historiesAreKeptPerPage(): void
    {
        $subject = $this->createSubject();
        $this->llmServiceManager->method('chat')->willReturnCallback(
            fn(array $messages): CompletionResponse => $this->response('Answer to ' . count($messages) . ' messages'),
        );

        $subject->getText('Question on page A', 'A');
        self::assertSame('Answer to 1 messages', $subject->getText('Question on page B', 'B'));
    }

    #[Test]
    public function getTextFailsClosedWhenNrLlmIsUnavailable(): void
    {
        $this->llmServiceManager->method('chat')->willThrowException(new RuntimeException('no provider available'));

        try {
            // setUp's never() expectation on the request factory asserts the
            // other half: no silent fallback to the original Gemini call.
            $this->createSubject()->getText('Write about TYPO3', '42');
            self::fail('Expected the nr-llm exception to propagate.');
        } catch (RuntimeException $exception) {
            self::assertSame('no provider available', $exception->getMessage());
        }
    }

    #[Test]
    public function interfaceObligationsAreServedWithoutTouchingAnyProvider(): void
    {
        $subject = $this->createSubject();

        $subject->checkApiKey();

        self::assertSame('nr-llm', $subject->getApiUrl());
    }
}
