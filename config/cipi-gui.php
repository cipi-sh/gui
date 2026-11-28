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
    | Passkeys
    |--------------------------------------------------------------------------
    |
    | Sign in with Face ID, Touch ID, Windows Hello, a phone or a security key
    | (4.8.0). The relying party is the panel's host; set rp_id to the parent
    | domain to share passkeys with its subdomains. Off where only SSO signs in.
    |
    */
    'passkeys' => [
        'enabled' => (bool) env('CIPI_GUI_PASSKEYS', true),
        'rp_id' => env('CIPI_GUI_PASSKEYS_RP_ID', ''),
    ],

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
    | Token abilities (Cipi API 1.44)
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
        'redirects-view', 'redirects-manage', 'proxies-view', 'proxies-manage', 'nginx-view', 'nginx-manage',
        'node-view', 'node-manage', 'node-runtimes-manage', 'search-view', 'search-manage',
        'deploy-manage', 'ssl-manage', 'dbs-view', 'dbs-create', 'dbs-manage',
        'php-view', 'php-manage', 'ssh-view', 'ssh-manage', 'services-view', 'services-manage',
        'smtp-view', 'smtp-manage', 'health-view', 'health-manage',
        'packages-view', 'packages-manage', 'monitor-view', 'zt-view', 'zt-manage', 'sync-manage', 'updates-view', 'updates-manage', 'disk-view', 'yml-view', 'yml-manage',
        'ip-whitelist-view', 'ip-whitelist-manage', 'status-view',
        'backups-view', 'backups-manage', 'metrics-view', 'metrics-manage',
        'notifications-view', 'notifications-manage',
        'firewall-view', 'firewall-manage',
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
    | Database dump upload (Restore dialog — API 1.39+ / Cipi ≥ 5.9.0)
    |--------------------------------------------------------------------------
    |
    | A dump goes from the browser to the panel, and from the panel to the
    | server, in chunks of this many megabytes — never more than this PHP
    | accepts in one request (upload_max_filesize, post_max_size) or than the
    | server asks for. How large a dump may be is the server's to say
    | (db_upload.max_mb in its API config, 2 GB by default).
    |
    */
    'db_upload_chunk_mb' => (int) env('CIPI_GUI_DB_UPLOAD_CHUNK_MB', 4),

    /*
    |--------------------------------------------------------------------------
    | Default admin (used by cipi:seed-gui-user)
    |--------------------------------------------------------------------------
    */
    'default_admin_email' => env('CIPI_GUI_ADMIN_EMAIL', 'admin@cipi.local'),
    'default_admin_name' => env('CIPI_GUI_ADMIN_NAME', 'Cipi Admin'),

    /*
    |--------------------------------------------------------------------------
    | Single sign-on (OpenID Connect)
    |--------------------------------------------------------------------------
    |
    | Written into the panel's .env by `cipi gui sso` on the server. An empty
    | issuer turns SSO off. Discovery is read at
    | <issuer>/.well-known/openid-configuration; the redirect URI to register
    | at the identity provider is {APP_URL}/auth/sso/callback.
    |
    */
    'sso' => [
        'issuer' => env('CIPI_GUI_SSO_ISSUER', ''),
        'client_id' => env('CIPI_GUI_SSO_CLIENT_ID', ''),
        'client_secret' => env('CIPI_GUI_SSO_CLIENT_SECRET', ''),
        // Button text; empty = from the issuer host (Microsoft Entra ID, Google, Okta, else "SSO").
        'label' => env('CIPI_GUI_SSO_LABEL', ''),
        // Comma-separated email domains whose people get an account (role user, no grants) on first sign-in.
        'domains' => env('CIPI_GUI_SSO_DOMAINS', ''),
        // true: password sign-in is off for everyone; `cipi gui reset-user` on the server is the break-glass.
        'only' => (bool) env('CIPI_GUI_SSO_ONLY', false),
        'scopes' => env('CIPI_GUI_SSO_SCOPES', 'openid email profile'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Theme assets
    |--------------------------------------------------------------------------
    |
    | Not read by the panel, kept for configs that publish it. A CSS change
    | needs no bump: the stylesheet is inlined in every page, and its md5
    | (Theme::fingerprint) clears the compiled views when it changes.
    |
    */
    'assets_version' => '9',

];
