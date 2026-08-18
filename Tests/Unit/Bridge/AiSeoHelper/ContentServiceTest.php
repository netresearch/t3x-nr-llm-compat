<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlmCompat\Tests\Unit\Bridge\AiSeoHelper;

use Netresearch\NrLlm\Testing\FakeCompletionService;
use Netresearch\NrLlmCompat\Bridge\AiSeoHelper\ContentService;
use Netresearch\NrLlmCompat\Exception\UnexpectedAiResponseException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use RuntimeException;
use TYPO3\CMS\Core\Domain\Repository\PageRepository;
use TYPO3\CMS\Core\Http\RequestFactory;
use TYPO3\CMS\Core\Routing\SiteMatcher;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

#[CoversClass(ContentService::class)]
final class ContentServiceTest extends UnitTestCase
{
    private FakeCompletionService $completions;

    private RequestFactory&MockObject $requestFactory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->completions = new FakeCompletionService();
        $this->requestFactory = $this->createMock(RequestFactory::class);
        // The whole point of the bridge: requestAi() must never reach the
        // original OpenAI HTTP transport.
        $this->requestFactory->expects(self::never())->method('request');
    }

    /**
     * @param array<string, string> $extConf
     */
    private function createSubject(array $extConf = [
        'openAiPromptPrefixPageTitle' => 'Suggest page title ideas in bullet point list for',
        'openAiTemperature' => '1',
        'openAiTopP' => '1',
        'openAiApiKey' => '',
    ]): ContentService
    {
        return new ContentService(
            $this->completions,
            self::createStub(PageRepository::class),
            self::createStub(SiteMatcher::class),
            $this->requestFactory,
            ['en' => 'English'],
            $extConf,
        );
    }

    #[Test]
    public function buildsThePromptExactlyLikeTheOriginalAndRequestsJson(): void
    {
        $this->completions->jsonResult = ['a' => 'one', 'b' => 'two'];

        $this->createSubject()->requestAi('  Some page content  ', 'openAiPromptPrefixPageTitle', '', 'en');

        self::assertCount(1, $this->completions->completeJsonCalls);
        $call = $this->completions->completeJsonCalls[0];
        self::assertSame(
            "Suggest page title ideas in bullet point list for in English:\n\nSome page content\n\n Return at least five suggestions and return the response as array in valid JSON format.",
            $call['prompt'],
        );
        self::assertNotNull($call['options']);
        self::assertSame('json', $call['options']->getResponseFormat());
        self::assertSame(1.0, $call['options']->getTemperature());
        self::assertSame(1.0, $call['options']->getTopP());
    }

    #[Test]
    public function returnsAMultiEntryResponseUnchanged(): void
    {
        $this->completions->jsonResult = ['a' => 'one', 'b' => 'two', 'c' => 'three'];

        $result = $this->createSubject()->requestAi('content', 'openAiPromptPrefixPageTitle', '', 'en');

        self::assertSame(['a' => 'one', 'b' => 'two', 'c' => 'three'], $result);
    }

    #[Test]
    public function unwrapsASingleKeyEnvelopeLikeTheOriginal(): void
    {
        $this->completions->jsonResult = ['suggestions' => ['one', 'two', 'three']];

        $result = $this->createSubject()->requestAi('content', 'openAiPromptPrefixPageTitle', '', 'en');

        self::assertSame(['one', 'two', 'three'], $result);
    }

    #[Test]
    public function emptyResponseThrows(): void
    {
        $this->completions->jsonResult = [];

        $this->expectException(UnexpectedAiResponseException::class);

        $this->createSubject()->requestAi('content', 'openAiPromptPrefixPageTitle', '', 'en');
    }

    #[Test]
    public function singleScalarResponseThrows(): void
    {
        $this->completions->jsonResult = ['only' => 'a string'];

        $this->expectException(UnexpectedAiResponseException::class);

        $this->createSubject()->requestAi('content', 'openAiPromptPrefixPageTitle', '', 'en');
    }

    #[Test]
    public function failsClosedWhenNrLlmIsUnavailable(): void
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
    public function clampsOutOfRangeExtensionSettingsInsteadOfThrowing(): void
    {
        $this->completions->jsonResult = ['a' => 'one', 'b' => 'two'];

        $this->createSubject([
            'openAiPromptPrefixPageTitle' => 'Prefix',
            'openAiTemperature' => '9',
            'openAiTopP' => '5',
        ])->requestAi('content', 'openAiPromptPrefixPageTitle', '', 'en');

        $options = $this->completions->completeJsonCalls[0]['options'];
        self::assertNotNull($options);
        self::assertSame(2.0, $options->getTemperature());
        self::assertSame(1.0, $options->getTopP());
    }

    #[Test]
    public function missingSettingsFallBackToJsonPresetDefaults(): void
    {
        $this->completions->jsonResult = ['a' => 'one', 'b' => 'two'];

        $this->createSubject(['openAiPromptPrefixPageTitle' => 'Prefix'])
            ->requestAi('content', 'openAiPromptPrefixPageTitle', '', 'en');

        $options = $this->completions->completeJsonCalls[0]['options'];
        self::assertNotNull($options);
        self::assertSame('json', $options->getResponseFormat());
        self::assertSame(0.3, $options->getTemperature());
        self::assertNull($options->getTopP());
    }

    #[Test]
    public function unknownPromptPrefixAndLanguageDegradeToEmptyStringsLikeTheOriginal(): void
    {
        $this->completions->jsonResult = ['a' => 'one', 'b' => 'two'];

        $this->createSubject()->requestAi('content', 'doesNotExist', '', 'xx');

        self::assertSame(
            " in :\n\ncontent\n\n Return at least five suggestions and return the response as array in valid JSON format.",
            $this->completions->completeJsonCalls[0]['prompt'],
        );
    }
}
