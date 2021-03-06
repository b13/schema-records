<?php

$EM_CONF[$_EXTKEY] = [
    'title' => 'Schema Records',
    'description' => 'schema.org structured data for your website with records',
    'category' => 'fe',
    'author' => 'Chris Müller',
    'author_email' => 'typo3@krue.ml',
    'state' => 'alpha',
    'version' => '0.1.0',
    'constraints' => [
        'depends' => [
            'typo3' => '9.5.0-10.4.99',
            'schema' => '1.3.0-0.0.0',
        ],
    ],
    'autoload' => [
        'psr-4' => [
            'Brotkrueml\\SchemaRecords\\' => 'Classes'
        ]
    ],
];
