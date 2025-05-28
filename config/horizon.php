<?php

return [
    'environments' => [
        'staging' => [
            'supervisor-1' => [
                'connection' => 'redis',
                'queue' => ['default', 'transactions'],
                'balance' => 'auto',
                'maxProcesses' => 10,
                'memory' => 512,
                'tries' => 3,
                'nice' => 0,
            ],
        ],
    ],
    
    'defaults' => [
        'supervisor-1' => [
            'connection' => 'redis',
            'queue' => ['default'],
            'balance' => 'simple',
            'maxProcesses' => 10,
            'memory' => 512,
            'tries' => 3,
            'nice' => 0,
        ],
    ],
];
