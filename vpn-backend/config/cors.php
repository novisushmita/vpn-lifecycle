<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    |
    | Here you may configure your settings for cross-origin resource sharing
    | or "CORS". This determines what cross-origin operations may execute
    | in web browsers. You are free to adjust these settings as needed.
    |
    | To learn more: https://developer.mozilla.org/en-US/docs/Web/HTTP/CORS
    |
    */

    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['*'],

    'allowed_origins' => [
        env('FRONTEND_URL', 'http://localhost:5173'),
        'http://127.0.0.1:5173',
    ],

    // Mengizinkan seluruh alamat LAN pada port dev server, supaya aplikasi
    // tetap dapat dibuka dari PC lain meski alamat DHCP mesin ini berubah.
    // Lab: izinkan alamat mana pun pada port dev server 5173, supaya aplikasi
    // dapat dibuka dari PC atau HP mana pun di jaringan tanpa mengunci ke satu IP.
    'allowed_origins_patterns' => ['#^https?://[^/]+:5173$#'],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => false,

];
