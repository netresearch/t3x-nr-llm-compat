<?php

declare(strict_types=1);

$EM_CONF[$_EXTKEY] = [
    'title' => 'nr_llm_compat filemetadata probe',
    'description' => "Test fixture: public alias onto ai_filemetadata's private OpenAiClient service",
    'category' => 'misc',
    'state' => 'beta',
    'version' => '0.1.0',
    'constraints' => [
        'depends' => [
            'ai_filemetadata' => '',
        ],
    ],
];
