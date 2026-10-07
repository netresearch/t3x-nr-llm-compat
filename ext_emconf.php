<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

$EM_CONF[$_EXTKEY] = [
    'title' => 'LLM Compatibility Layer',
    'description' => 'Routes the LLM provider calls of third-party AI extensions through nr-llm at runtime, so its provider management, budgets and telemetry apply without modifying them.',
    'category' => 'misc',
    'author' => 'Netresearch DTT GmbH',
    'author_company' => 'Netresearch DTT GmbH',
    'state' => 'beta',
    'version' => '0.2.3',
    'constraints' => [
        'depends' => [
            'php' => '8.2.0-8.5.99',
            'typo3' => '13.4.0-14.3.99',
            'nr_llm' => '0.35.0-0.38.99',
        ],
        'conflicts' => [],
        'suggests' => [
            'ai_seo_helper' => '',
            'ns_t3ai' => '',
            'ai_filemetadata' => '',
            'texter' => '',
            'solver' => '',
            'news' => '',
        ],
    ],
];
