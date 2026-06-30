<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Route prefix
    |--------------------------------------------------------------------------
    |
    | All GUI routes are registered under this prefix (empty = root).
    |
    */
    'route_prefix' => env('CIPI_GUI_PREFIX', ''),

    /*
    |--------------------------------------------------------------------------
    | Session guard
    |--------------------------------------------------------------------------
    */
    'guard' => env('CIPI_GUI_GUARD', 'web'),

    /*
    |--------------------------------------------------------------------------
    | PHP versions (mirrors cipi/api config)
    |--------------------------------------------------------------------------
    */
    'php_versions' => ['7.4', '8.0', '8.1', '8.2', '8.3', '8.4', '8.5'],

    /*
    |--------------------------------------------------------------------------
    | Reserved usernames (mirrors cipi/api config)
    |--------------------------------------------------------------------------
    */
    'reserved_usernames' => [
        'root', 'admin', 'www', 'mail', 'ftp', 'mysql', 'nginx', 'cipi',
        'api', 'gui', 'test', 'dev', 'staging', 'production', 'demo',
    ],

    /*
    |--------------------------------------------------------------------------
    | Job polling
    |--------------------------------------------------------------------------
    */
    'job_poll_interval_ms' => (int) env('CIPI_GUI_JOB_POLL_MS', 1500),
    'job_poll_max_attempts' => (int) env('CIPI_GUI_JOB_POLL_MAX', 120),
    'job_timeout_seconds' => (int) env('CIPI_GUI_JOB_TIMEOUT', 300),

    /*
    |--------------------------------------------------------------------------
    | HTTP client
    |--------------------------------------------------------------------------
    */
    'http_timeout' => (int) env('CIPI_GUI_HTTP_TIMEOUT', 30),
    'http_connect_timeout' => (int) env('CIPI_GUI_HTTP_CONNECT_TIMEOUT', 10),

    /*
    |--------------------------------------------------------------------------
    | Default admin (used by cipi:seed-gui-user)
    |--------------------------------------------------------------------------
    */
    'default_admin_email' => env('CIPI_GUI_ADMIN_EMAIL', 'admin@cipi.local'),
    'default_admin_name' => env('CIPI_GUI_ADMIN_NAME', 'Cipi Admin'),

];
