<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlmCompat\Tests\Unit\Bridge\NsT3Ai;

use Netresearch\NrLlm\Domain\Model\CompletionResponse;
use Netresearch\NrLlm\Domain\Model\UsageStatistics;
use Netresearch\NrLlm\Testing\FakeCompletionService;
use Netresearch\NrLlmCompat\Bridge\NsT3Ai\NsT3AiContentService;
use Netresearch\NrLlmCompat\Exception\UnexpectedAiResponseException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use RuntimeException;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Domain\Repository\PageRepository;
use TYPO3\CMS\Core\Http\RequestFactory;
use TYPO3\CMS\Core\Routing\SiteMatcher;
use TYPO3\CMS\Extbase\Mvc\Web\Routing\UriBuilder;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

#[CoversClass(NsT3AiContentService::class)]
final class NsT3AiContentServiceTest extends UnitTestCase
{
    private FakeCompletionService $completions;

    private RequestFactory&MockObject $requestFactory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->completions = new FakeCompletionService();
        $this->requestFactory = $this->createMock(RequestFactory::class);
        // The whole point of the bridge: neither overridden method may reach
        // the original OpenAI HTTP transport.
        $this->requestFactory->expects(self::never())->method('request');
        // The parent constructor resolves the backend layout language via
        // BackendUtility::getModuleData(), which needs a backend user.
        $GLOBALS['BE_USER'] = self::createStub(BackendUserAuthentication::class);
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['BE_USER']);
        parent::tearDown();
    }

    private function queueResponse(string $text): void
    {
        $this->completions->responses[] = new CompletionResponse(
            $text,
            'test-model',
            new UsageStatistics(1, 1, 2),
        );
    }

    /**
     * @param array<string, string> $extConf
     */
    private function createSubject(array $extConf = [
        'apiKey' => '',
        'model' => 'gpt-4o',
        'openAiPromptPrefixPageTitle' => 'Suggest page title ideas for',
    ]): NsT3AiContentService
    {
        return new NsT3AiContentService(
            $this->completions,
            self::createStub(PageRepository::class),
            self::createStub(SiteMatcher::class),
            $this->requestFactory,
            self::createStub(UriBuilder::class),
            true,
            ['en' => 'English'],
            $extConf,
        );
    }

    #[Test]
    public function requestAiBuildsThePromptViaTheOriginalHelper(): void
    {
        $this->queueResponse('Generated title');

        $result = $this->createSubject()->requestAi('  Some page content  ', 'openAiPromptPrefixPageTitle', '', 'en');

        self::assertSame('Generated title', $result);
        self::assertCount(1, $this->completions->completeCalls);
        self::assertSame(
            "Suggest page title ideas for in English:\n\nSome page content",
            $this->completions->completeCalls[0]['prompt'],
        );
    }

    #[Test]
    public function requestAiHonorsTheContentPlaceholderLikeTheOriginal(): void
    {
        $this->queueResponse('Generated title');

        $this->createSubject([
            'apiKey' => '',
            'model' => 'gpt-4o',
            'openAiPromptPrefixPageTitle' => 'Write a title about [Content] now',
        ])->requestAi('the topic', 'openAiPromptPrefixPageTitle', '', 'en');

        self::assertSame(
            'Write a title about the topic now in English',
            $this->completions->completeCalls[0]['prompt'],
        );
    }

    #[Test]
    public function requestAiHonorsThePerRequestPromptOverrideLikeTheOriginal(): void
    {
        $this->queueResponse('Generated title');

        $this->createSubject()->requestAi(
            'ignored content basis',
            'openAiPromptPrefixPageTitle',
            '',
            'en',
            ['prompt' => 'Custom prompt about [Content]'],
        );

        self::assertSame(
            'Custom prompt about ignored content basis in English',
            $this->completions->completeCalls[0]['prompt'],
        );
    }

    #[Test]
    public function requestAiExtractsThePromptFromTheLegacyModelBranch(): void
    {
        $this->queueResponse('Generated title');

        $this->createSubject([
            'apiKey' => '',
            'model' => 'text-davinci-003',
            'openAiPromptPrefixPageTitle' => 'Suggest page title ideas for',
        ])->requestAi('content', 'openAiPromptPrefixPageTitle', '', 'en');

        self::assertSame(
            "Suggest page title ideas for in English:\n\ncontent",
            $this->completions->completeCalls[0]['prompt'],
        );
    }

    #[Test]
    public function requestAiStripsTheReplaceTextLikeTheOriginal(): void
    {
        $this->queueResponse('seo keywords: one, two, three');

        $result = $this->createSubject()->requestAi('content', 'openAiPromptPrefixPageTitle', 'seo keywords:', 'en');

        self::assertSame('one, two, three', $result);
    }

    #[Test]
    public function requestAiFailsClosedWhenNrLlmIsUnavailable(): void
    {
        $this->completions->throwable = new RuntimeException('no provider available');

        try {
            // setUp's never() expectation on the request factory asserts the
            // other half: no silent fallback to the original OpenAI call.
            $this->createSubject()->requestAi('content', 'openAiPromptPrefixPageTitle', '', 'en');
            self::fail('Expected the nr-llm exception to propagate.');
        } catch (RuntimeException $exception) {
            self::assertSame('no provider available', $exception->getMessage());
        }
    }

    #[Test]
    public function rteContentReturnsOneChoicePerRequestedAlternative(): void
    {
        $this->queueResponse('first');
        $this->queueResponse('second');
        $this->queueResponse('third');

        $result = $this->createSubject()->requestAiForRteContent([
            'prompt' => 'Write about TYPO3',
            'max_tokens' => 400,
            'model' => 'gpt-4o',
            'temperature' => 0.5,
            'top_p' => 1,
            'n' => 3,
            'frequency_penalty' => 0,
            'presence_penalty' => 0,
        ]);

        self::assertSame([
            'choices' => [
                ['message' => ['content' => 'first']],
                ['message' => ['content' => 'second']],
                ['message' => ['content' => 'third']],
            ],
        ], $result);
        self::assertCount(3, $this->completions->completeCalls);
        self::assertSame('Write about TYPO3', $this->completions->completeCalls[0]['prompt']);
    }

    #[Test]
    public function rteContentHonorsTheDialogsRequestShapingValues(): void
    {
        $this->queueResponse('text');

        $this->createSubject()->requestAiForRteContent([
            'prompt' => 'Write about TYPO3',
            'max_tokens' => 400,
            'temperature' => 0.5,
            'top_p' => 1,
            'n' => 1,
            'frequency_penalty' => 1,
            'presence_penalty' => -1,
        ]);

        $options = $this->completions->completeCalls[0]['options'];
        self::assertNotNull($options);
        self::assertSame(0.5, $options->getTemperature());
        self::assertSame(1.0, $options->getTopP());
        self::assertSame(400, $options->getMaxTokens());
        self::assertSame(1.0, $options->getFrequencyPenalty());
        self::assertSame(-1.0, $options->getPresencePenalty());
    }

    #[Test]
    public function rteContentClampsOutOfRangeValuesInsteadOfThrowing(): void
    {
        $this->queueResponse('text');

        $this->createSubject()->requestAiForRteContent([
            'prompt' => 'Write about TYPO3',
            'temperature' => 9,
            'top_p' => 7,
            'frequency_penalty' => 5,
            'presence_penalty' => -5,
        ]);

        $options = $this->completions->completeCalls[0]['options'];
        self::assertNotNull($options);
        self::assertSame(2.0, $options->getTemperature());
        self::assertSame(1.0, $options->getTopP());
        self::assertSame(2.0, $options->getFrequencyPenalty());
        self::assertSame(-2.0, $options->getPresencePenalty());
    }

    #[Test]
    public function rteContentCapsTheAlternativeCount(): void
    {
        for ($i = 0; $i < 10; ++$i) {
            $this->queueResponse('text ' . $i);
        }

        $result = $this->createSubject()->requestAiForRteContent([
            'prompt' => 'Write about TYPO3',
            'n' => 5000,
        ]);

        self::assertIsArray($result['choices']);
        self::assertCount(10, $result['choices']);
        self::assertCount(10, $this->completions->completeCalls);
    }

    #[Test]
    public function rteContentWithoutPromptThrows(): void
    {
        $this->expectException(UnexpectedAiResponseException::class);

        $this->createSubject()->requestAiForRteContent(['n' => 1]);
    }
}
