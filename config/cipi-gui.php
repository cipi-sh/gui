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
    | PHP version examples (hint text in create/edit app forms)
    |--------------------------------------------------------------------------
    |
    | Shown as examples only. The actual available versions depend on what is
    | installed on each Cipi server (free-text field, not a fixed allowlist).
    |
    */
    'php_versions' => ['8.4', '8.5'],

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
    | Token abilities (Cipi API 1.33)
    |--------------------------------------------------------------------------
    |
    | Everything the panel calls. Shown on the Connections page as a ready-made
    | `cipi api token create` command.
    |
    */
    'token_abilities' => [
        'apps-view', 'apps-create', 'apps-edit', 'apps-delete', 'apps-suspend', 'apps-basicauth',
        'apps-env', 'apps-auth', 'apps-artisan', 'apps-run', 'apps-deploy-config',
        'aliases-view', 'aliases-create', 'aliases-delete', 'www-manage',
        'redirects-view', 'redirects-manage', 'proxies-view', 'proxies-manage',
        'node-view', 'node-manage', 'search-view', 'search-manage',
        'deploy-manage', 'ssl-manage', 'dbs-view', 'dbs-create', 'dbs-manage',
        'php-view', 'php-manage', 'ssh-view', 'ssh-manage', 'services-view', 'services-manage',
        'smtp-view', 'smtp-manage', 'health-view', 'health-manage',
        'packages-view', 'monitor-view', 'zt-view', 'disk-view',
        'ip-whitelist-view', 'ip-whitelist-manage', 'status-view',
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
    // GET /api/disk and /api/disk/dbs measure every app home when asked (API 1.33+).
    'http_disk_timeout' => (int) env('CIPI_GUI_HTTP_DISK_TIMEOUT', 180),

    /*
    |--------------------------------------------------------------------------
    | Default admin (used by cipi:seed-gui-user)
    |--------------------------------------------------------------------------
    */
    'default_admin_email' => env('CIPI_GUI_ADMIN_EMAIL', 'admin@cipi.local'),
    'default_admin_name' => env('CIPI_GUI_ADMIN_NAME', 'Cipi Admin'),

    /*
    |--------------------------------------------------------------------------
    | Theme assets (bump after CSS changes to bust browser cache)
    |--------------------------------------------------------------------------
    */
    'assets_version' => '6',

];
