<?php

declare(strict_types=1);

$EM_CONF[$_EXTKEY] = [
    'title' => 'nr_llm_compat test fake',
    'description' => 'Test fixture: fakes the nr-llm completion surface',
    'category' => 'misc',
    'state' => 'beta',
    'version' => '0.1.0',
    'constraints' => [
        'depends' => [
            'nr_llm' => '',
        ],
    ],
];
