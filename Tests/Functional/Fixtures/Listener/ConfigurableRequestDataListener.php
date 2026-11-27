<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlmCompat\Tests\Functional\Fixtures\Listener;

use Closure;
use Mfd\Ai\FileMetadata\Event\ModifyRequestDataEvent;

/**
 * A ModifyRequestDataEvent listener as a site extension would register it;
 * the functional test sets what it does.
 */
final class ConfigurableRequestDataListener
{
    /** @var (Closure(ModifyRequestDataEvent): void)|null */
    public ?Closure $change = null;

    public int $calls = 0;

    public function __invoke(ModifyRequestDataEvent $event): void
    {
        ++$this->calls;
        if ($this->change instanceof Closure) {
            ($this->change)($event);
        }
    }
}
