<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlmCompat\Tests\Unit\Fixtures;

/**
 * Stands in for a third-party service class in contract verifier tests.
 */
class FixtureService
{
    /** @var array<mixed> */
    protected array $settings = [];

    private string $secret = '';

    /**
     * @param mixed $untyped deliberately untyped, mirroring third-party code
     *
     * @return array<mixed>
     */
    public function doWork(string $input, $untyped): array
    {
        return [$input, $untyped, $this->settings, $this->secret];
    }

    protected function internalWork(): void {}
}
