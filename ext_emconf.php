<?php
$EM_CONF[$_EXTKEY] = [
    'title' => 'Scheduler',
    'description' => 'The TYPO3 Scheduler let\'s you register tasks to happen at a specific time',
    'category' => 'misc',
    'version' => '8.7.3',
    'state' => 'stable',
    'uploadfolder' => 0,
    'createDirs' => '',
    'clearcacheonload' => 0,
    'author' => 'Francois Suter',
    'author_email' => 'francois@typo3.org',
    'author_company' => '',
    'constraints' => [
        'depends' => [
            'typo3' => '8.7.0-8.7.3',
        ],
        'conflicts' => [],
        'suggests' => [],
    ],
];
