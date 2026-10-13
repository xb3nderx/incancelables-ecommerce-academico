<?php

declare(strict_types=1);

return [
    'entorno' => 'dev',

    'origenes' => [
        'dev' => [
            'http://127.0.0.1:5500',
            'https://xb3nderx.github.io',
        ],
        'prod' => [],
    ],

    'metodos' => [
        'GET',
        'POST',
    ],

    'headers' => [
        'Accept',
        'Content-Type',
        'Authorization',
    ],

    'max_age' => 600,

    'permitir_credenciales' => false,
];
