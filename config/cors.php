<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    |
    | Every route group served to browsers must be listed in "paths", including
    | the ones loaded without the /api prefix (routes/dcutils.php, routes/magento.php).
    |
    | Set CORS_ALLOWED_ORIGINS in .env to a comma-separated list of origins
    | (e.g. https://www.example.com,https://admin.example.com) to restrict access.
    |
    */

    'paths' => ['api/*', 'case_label', 'confirm_label', 'dc/*', 'magento/*'],

    'allowed_methods' => ['*'],

    'allowed_origins' => explode(',', env('CORS_ALLOWED_ORIGINS', '*')),

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => false,

];
