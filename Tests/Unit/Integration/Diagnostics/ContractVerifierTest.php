<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlmCompat\Tests\Unit\Integration\Diagnostics;

use Netresearch\NrLlmCompat\Integration\Contract\MethodContract;
use Netresearch\NrLlmCompat\Integration\Contract\PropertyContract;
use Netresearch\NrLlmCompat\Integration\Diagnostics\ContractVerifier;
use Netresearch\NrLlmCompat\Tests\Unit\Fixtures\ConfigurableIntegration;
use Netresearch\NrLlmCompat\Tests\Unit\Fixtures\FinalFixtureService;
use Netresearch\NrLlmCompat\Tests\Unit\Fixtures\FixtureBridge;
use Netresearch\NrLlmCompat\Tests\Unit\Fixtures\FixtureService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

#[CoversClass(ContractVerifier::class)]
final class ContractVerifierTest extends UnitTestCase
{
    private ContractVerifier $subject;

    protected function setUp(): void
    {
        parent::setUp();
        $this->subject = new ContractVerifier();
    }

    #[Test]
    public function matchingContractReportsNoViolations(): void
    {
        $integration = new ConfigurableIntegration(
            methodContracts: [
                new MethodContract(FixtureService::class, 'doWork', ['string', null], 'array'),
            ],
            propertyContracts: [
                new PropertyContract(FixtureService::class, 'settings'),
            ],
        );

        self::assertSame([], $this->subject->verify($integration));
    }

    #[Test]
    public function missingClassIsReported(): void
    {
        $integration = new ConfigurableIntegration(
            serviceReplacements: ['Acme\\DoesNotExist\\Service' => FixtureBridge::class],
        );

        $violations = $this->subject->verify($integration);

        self::assertCount(1, $violations);
        self::assertStringContainsString('does not exist', $violations[0]);
    }

    #[Test]
    public function finalClassIsReported(): void
    {
        $integration = new ConfigurableIntegration(
            serviceReplacements: [FinalFixtureService::class => FixtureBridge::class],
        );

        $violations = $this->subject->verify($integration);

        self::assertCount(1, $violations);
        self::assertStringContainsString('final', $violations[0]);
    }

    #[Test]
    public function bridgeNotSubclassingTargetIsReported(): void
    {
        $integration = new ConfigurableIntegration(
            serviceReplacements: [FixtureService::class => self::class],
        );

        $violations = $this->subject->verify($integration);

        self::assertCount(1, $violations);
        self::assertStringContainsString('not a subclass', $violations[0]);
    }

    #[Test]
    public function missingMethodIsReported(): void
    {
        $integration = new ConfigurableIntegration(
            methodContracts: [
                new MethodContract(FixtureService::class, 'vanishedMethod', [], null),
            ],
        );

        $violations = $this->subject->verify($integration);

        self::assertCount(1, $violations);
        self::assertStringContainsString('vanishedMethod() does not exist', $violations[0]);
    }

    #[Test]
    public function nonPublicMethodIsReported(): void
    {
        $integration = new ConfigurableIntegration(
            methodContracts: [
                new MethodContract(FixtureService::class, 'internalWork', [], 'void'),
            ],
        );

        $violations = $this->subject->verify($integration);

        self::assertCount(1, $violations);
        self::assertStringContainsString('not public', $violations[0]);
    }

    #[Test]
    public function changedParameterCountIsReported(): void
    {
        $integration = new ConfigurableIntegration(
            methodContracts: [
                new MethodContract(FixtureService::class, 'doWork', ['string', null, 'array'], 'array'),
            ],
        );

        $violations = $this->subject->verify($integration);

        self::assertCount(1, $violations);
        self::assertStringContainsString('expects 3 parameters, found 2', $violations[0]);
    }

    #[Test]
    public function changedParameterTypeIsReported(): void
    {
        $integration = new ConfigurableIntegration(
            methodContracts: [
                new MethodContract(FixtureService::class, 'doWork', ['int', null], 'array'),
            ],
        );

        $violations = $this->subject->verify($integration);

        self::assertCount(1, $violations);
        self::assertStringContainsString('is typed "string", expected "int"', $violations[0]);
    }

    #[Test]
    public function newlyTypedParameterIsReportedAgainstUntypedContract(): void
    {
        $integration = new ConfigurableIntegration(
            methodContracts: [
                new MethodContract(FixtureService::class, 'doWork', [null, null], 'array'),
            ],
        );

        $violations = $this->subject->verify($integration);

        self::assertCount(1, $violations);
        self::assertStringContainsString('is typed "string", expected "untyped"', $violations[0]);
    }

    #[Test]
    public function changedReturnTypeIsReported(): void
    {
        $integration = new ConfigurableIntegration(
            methodContracts: [
                new MethodContract(FixtureService::class, 'doWork', ['string', null], 'string'),
            ],
        );

        $violations = $this->subject->verify($integration);

        self::assertCount(1, $violations);
        self::assertStringContainsString('returns "array", expected "string"', $violations[0]);
    }

    #[Test]
    public function missingPropertyIsReported(): void
    {
        $integration = new ConfigurableIntegration(
            propertyContracts: [
                new PropertyContract(FixtureService::class, 'vanishedProperty'),
            ],
        );

        $violations = $this->subject->verify($integration);

        self::assertCount(1, $violations);
        self::assertStringContainsString('$vanishedProperty does not exist', $violations[0]);
    }

    #[Test]
    public function privatePropertyIsReported(): void
    {
        $integration = new ConfigurableIntegration(
            propertyContracts: [
                new PropertyContract(FixtureService::class, 'secret'),
            ],
        );

        $violations = $this->subject->verify($integration);

        self::assertCount(1, $violations);
        self::assertStringContainsString('private', $violations[0]);
    }

    #[Test]
    public function multipleViolationsAreAllCollected(): void
    {
        $integration = new ConfigurableIntegration(
            serviceReplacements: [FinalFixtureService::class => FixtureBridge::class],
            methodContracts: [
                new MethodContract(FixtureService::class, 'vanishedMethod', [], null),
            ],
            propertyContracts: [
                new PropertyContract(FixtureService::class, 'secret'),
            ],
        );

        self::assertCount(3, $this->subject->verify($integration));
    }
}
