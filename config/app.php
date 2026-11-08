<?php

return [

    'name' => env('APP_NAME', 'Pelican'),
    'logo' => env('APP_LOGO'),
    'favicon' => env('APP_FAVICON', '/pelican.ico'),

    'version' => 'canary',

    // Where this build's source is published (AGPL-3.0 section 13); the footer links to it.
    'source_url' => env('APP_SOURCE_URL', 'https://github.com/LoomNode/pelican-panel'),

    'timezone' => 'UTC',

    'installed' => env('APP_INSTALLED', true),

    'exceptions' => [
        'report_all' => env('APP_REPORT_ALL_EXCEPTIONS', false),
    ],

    'fallback_locale' => 'en',

];
