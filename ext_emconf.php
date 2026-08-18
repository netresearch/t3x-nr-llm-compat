<?php

$EM_CONF[$_EXTKEY] = [
    'title' => 'Netresearch LLM Compatibility Layer',
    'description' => 'Routes the LLM provider calls of third-party TYPO3 AI extensions through nr-llm at runtime — centralized provider management, budgets and telemetry apply without modifying the third-party extensions.',
    'category' => 'misc',
    'author' => 'Netresearch DTT GmbH',
    'author_company' => 'Netresearch DTT GmbH',
    'state' => 'beta',
    'version' => '0.1.0',
    'constraints' => [
        'depends' => [
            'php' => '8.2.0-8.5.99',
            'typo3' => '13.4.0-13.4.99',
            'nr_llm' => '0.29.0-0.99.99',
        ],
        'conflicts' => [],
        'suggests' => [
            'ai_seo_helper' => '',
        ],
    ],
];
