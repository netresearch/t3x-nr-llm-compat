<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

$EM_CONF[$_EXTKEY] = [
    'title' => 'nr_llm_compat request listener',
    'description' => "Test fixture: a configurable listener for ai_filemetadata's ModifyRequestDataEvent",
    'category' => 'misc',
    'state' => 'beta',
    'version' => '0.1.0',
    'constraints' => [
        'depends' => [
            'ai_filemetadata' => '',
        ],
    ],
];
