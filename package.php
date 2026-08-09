<?php

declare(strict_types=1);

return [
    'config' => [
        'framework_start' => [
            'nova\\plugin\\update\\UpdateManager',
        ],
    ],
    'require' => [
        'tpl',
        'login',
        'http',
        'corn',
    ],
];
