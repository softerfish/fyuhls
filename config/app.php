<?php

return [
    'app_name' => 'Fyuhls',
    'base_url' => 'http://localhost',
    'debug' => false,
    'env' => 'production', // development or production

    'theme' => [
        'name' => 'default'
    ],

    // Security Configuration
    'security' => [
        // Get your API key from: https://proxycheck.io/
        // Free plan allows 1,000 queries per day
        'proxycheck_api_key' => '',
        // only allow requests originating from these hosts (for referrer validation)
        'allowed_hosts' => ['localhost'],
        // rate limit settings
        'rate_limit' => [
            'download' => [
                'limit' => 5,
                'window' => 600 // seconds
            ],
            'login' => [
                'limit' => 5,
                'window' => 300
            ]
        ],
    ],

    // Cloudflare Turnstile Configuration
    // Get keys from: https://dash.cloudflare.com/
    'turnstile' => [
        'site_key' => '',
        'secret_key' => '',
    ],

    // uploads
    'upload' => [
        // max size in bytes (default 100 MB)
        'max_size' => 100 * 1024 * 1024
    ],

    // thumbnails
    'thumbnail' => [
        'max_width' => 320,
        'max_height' => 240,
        'quality' => 80
    ],

    // optional video processing
    'video' => [
        // set to full path to ffmpeg binary if available; leave empty to disable
        'ffmpeg_path' => ''
    ],

    // Application Key (generated during installation and stored in the hidden config)
    'app_key' => 'REPLACE_DURING_INSTALL'
];
