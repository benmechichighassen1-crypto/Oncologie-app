<?php

return [

    'defaults' => [
        'guard' => 'web',
        'passwords' => null,
    ],

    'guards' => [
        'web' => [
            'driver' => 'session',
            'provider' => 'clinisys',
        ],
    ],

    // Comptes Clinisys (AccessControl), lus en lecture seule.
    'providers' => [
        'clinisys' => [
            'driver' => 'accesscontrol',
        ],
    ],

    'passwords' => [],

    'password_timeout' => 10800,

];
