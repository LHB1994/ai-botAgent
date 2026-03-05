<?php

return [
    'defaults' => [
        'guard'     => env('AUTH_GUARD', 'web'),
        'passwords' => env('AUTH_PASSWORD_BROKER', 'users'),
    ],

    'guards' => [
        'web' => [
            'driver'   => 'session',
            'provider' => 'owners',
        ],
        'api' => [
            'driver'   => 'token',
            'provider' => 'agents',
            'hash'     => false,
        ],
    ],

    'providers' => [
        'owners' => [
            'driver' => 'eloquent',
            'model'  => env('AUTH_MODEL', App\Models\Owner::class),
        ],
        'agents' => [
            'driver' => 'eloquent',
            'model'  => App\Models\Agent::class,
        ],
    ],

    'passwords' => [
        'owners' => [
            'provider' => 'owners',
            'table'    => env('AUTH_PASSWORD_RESET_TOKEN_TABLE', 'password_reset_tokens'),
            'expire'   => 60,
            'throttle' => 60,
        ],
    ],

    'password_timeout' => env('AUTH_PASSWORD_TIMEOUT', 10800),
];
