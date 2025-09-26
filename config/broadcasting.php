<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Default Broadcaster
    |--------------------------------------------------------------------------
    */
    'default' => env('BROADCAST_DRIVER', 'pusher'),

    /*
    |--------------------------------------------------------------------------
    | Broadcast Connections
    |--------------------------------------------------------------------------
    */
    'connections' => [
        'pusher' => [
            'driver' => 'pusher',
            'key' => env('PUSHER_APP_KEY', 'a7c66b1520863080e54e'),
            'secret' => env('PUSHER_APP_SECRET', 'd4dd833d21f5a2052616'),
            'app_id' => env('PUSHER_APP_ID', '2052404'),
            'options' => [
                'cluster' => env('PUSHER_APP_CLUSTER', 'us2'),
                'host' => env('PUSHER_HOST', 'api.pusherapp.com'),
                'port' => env('PUSHER_PORT', 443),
                'scheme' => env('PUSHER_SCHEME', 'https'),
                'encrypted' => env('PUSHER_APP_ENCRYPTED', true),
            ],
        ],
    ],
];
