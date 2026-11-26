<?php

namespace CipiGui\Infrastructure\Connectors;

use CipiGui\Infrastructure\Action;
use CipiGui\Infrastructure\ApiClient;
use CipiGui\Infrastructure\Column;
use CipiGui\Infrastructure\Connector;
use CipiGui\Infrastructure\ConnectorException;
use CipiGui\Infrastructure\Field;
use CipiGui\Infrastructure\Outcome;
use CipiGui\Infrastructure\S3\S3Sections;
use CipiGui\Infrastructure\Section;

/**
 * Johnny, self-hosted S3 on a VPS (Garage underneath), through the JSON API
 * of its panel: buckets with their quotas, CORS and expiry rules; keys with
 * one scope, per bucket or for all of them; bucket and key in one call for a
 * new app; connections to other providers, imports, exports and scheduled
 * syncs. https://johnny.web.ap.it
 */
final class Johnny extends Connector
{
    private const SCOPES = ['read' => 'Read', 'read-write' => 'Read and write', 'write' => 'Write only', 'full' => 'Full (read, write, owner)'];

    private const PROVIDERS = [
        'aws' => 'AWS S3', 'r2' => 'Cloudflare R2', 'spaces' => 'DigitalOcean Spaces', 'b2' => 'Backblaze B2', 'wasabi' => 'Wasabi',
        'hetzner' => 'Hetzner Object Storage', 'scaleway' => 'Scaleway', 'minio' => 'MinIO', 'johnny' => 'Another Johnny', 'other' => 'Other S3', 'sftp' => 'SFTP server',
    ];

    public function key(): string
    {
        return 'johnny';
    }

    public function label(): string
    {
        return 'Johnny';
    }

    public function description(): string
    {
        return __('Your own S3 (johnny.web.ap.it): buckets with quotas, CORS and expiry, scoped keys, a bucket and its key for an app in one go, imports, exports and syncs with other providers.');
    }

    public function icon(): string
    {
        return 'archive-box';
    }

    public function category(): string
    {
        return 'storage';
    }

    public function docs(): string
    {
        return 'https://github.com/andreapollastri/johnny#panel-api';
    }

    public function fields(): array
    {
        return [
            Field::text('url', __('Panel address'))->required()->placeholder('https://s3.example.com')->help(__('Where the Johnny panel answers; the API is under /api.')),
            Field::password('token', __('Panel API token'))->required(),
        ];
    }

    public function instructions(): array
    {
        return [
            __('Johnny panel → Settings → Panel API tokens → New token (e.g. cipi-panel), or on its server: sudo -u www-data php /opt/johnny/panel/artisan johnny:api-token you@example.com --name=cipi-panel.'),
            __('The token acts as the user it belongs to: one of the panel\'s users, with its password and 2FA, is enough.'),
            __('The panel address is the one you open in the browser, with https://.'),
        ];
    }

    public function client(array $credentials): ApiClient
    {
        $url = self::instanceUrl($credentials['url'] ?? '');

        if ($url === '') {
            throw new ConnectorException(__('The address of the Johnny panel is missing.'));
        }

        return new ApiClient($url.'/api', ['Authorization' => 'Bearer '.self::string($credentials, 'token')], 60);
    }

    public function verify(object $api): string
    {
        /** @var ApiClient $api */
        $buckets = (array) ($api->get('/buckets')['data'] ?? []);
        $keys = (array) ($api->get('/keys')['data'] ?? []);

        return 'ok · '.trans_choice(':count bucket|:count buckets', count($buckets)).' · '.trans_choice(':count key|:count keys', count($keys));
    }

    public function sections(): array
    {
        return [
            $this->buckets(),
            $this->keys(),
            $this->connections(),
            $this->transfers(),
            $this->syncs(),
        ];
    }

    /** @return list<array<string, mixed>> */
    private static function data(ApiClient $api, string $path): array
    {
        return array_values(array_filter((array) ($api->get($path)['data'] ?? []), 'is_array'));
    }

    private static function bucket(string $name): string
    {
        return '/buckets/'.rawurlencode($name);
    }

    /** @return array<string, string> */
    private static function bucketOptions(ApiClient $api): array
    {
        $names = array_map(fn ($b) => (string) ($b['name'] ?? ''), self::data($api, '/buckets'));

        return array_combine($names, $names) ?: [];
    }

    /** @return array<string, string> */
    private static function connectionOptions(ApiClient $api): array
    {
        $out = [];

        foreach (self::data($api, '/connections') as $c) {
            $out[(string) $c['id']] = ($c['name'] ?? '').' · '.($c['provider_label'] ?? $c['provider'] ?? '');
        }

        return $out;
    }

    /** @return array<string, string> */
    private static function keyOptions(ApiClient $api): array
    {
        $out = [];

        foreach (self::data($api, '/keys') as $k) {
            $out[(string) $k['id']] = ($k['name'] ?? '').' · '.$k['id'];
        }

        return $out;
    }

    /** @param  array<string, mixed>  $answer */
    private static function credentials(array $answer, string $title): Outcome
    {
        $answer = isset($answer['data']) && is_array($answer['data']) ? $answer['data'] : $answer;
        $endpoint = (string) ($answer['endpoint'] ?? data_get($answer, 'env.AWS_ENDPOINT_URL', ''));
        // Laravel's s3 disk reads AWS_ENDPOINT and AWS_USE_PATH_STYLE_ENDPOINT; Johnny's env names the endpoint for the AWS SDKs.
        $env = array_filter([
            'AWS_ACCESS_KEY_ID' => (string) data_get($answer, 'credentials.access_key_id', ''),
            'AWS_SECRET_ACCESS_KEY' => (string) data_get($answer, 'credentials.secret_access_key', ''),
            ...array_map('strval', array_filter((array) ($answer['env'] ?? []), 'is_scalar')),
            'AWS_DEFAULT_REGION' => (string) ($answer['region'] ?? data_get($answer, 'env.AWS_DEFAULT_REGION', '')),
            'AWS_BUCKET' => (string) ($answer['bucket'] ?? ''),
            'AWS_ENDPOINT' => $endpoint,
            'AWS_USE_PATH_STYLE_ENDPOINT' => ($answer['path_style'] ?? true) ? 'true' : 'false',
        ], fn ($v) => $v !== '');

        return Outcome::show($title, S3Sections::env($env)."\n\n# ".__('The secret is shown once.'), __('Done.'));
    }

    private function buckets(): Section
    {
        return Section::make('buckets', __('Buckets'))
            ->icon('archive-box')
            ->id('name')
            ->list(fn (ApiClient $api) => self::data($api, '/buckets'))
            ->columns([
                Column::make('name', __('Bucket'))->mono(),
                Column::make('created', __('Created'))->date(),
            ])
            ->toolbar([
                Action::make('provision', __('Bucket and key for an app'))->icon('rocket')->primary()
                    ->fields([
                        Field::text('bucket', __('Bucket'))->placeholder(__('empty: a name is made up'))->rules(['regex:/^[a-z0-9][a-z0-9._-]{1,254}$/']),
                        Field::select('scope', __('What the key may do'), self::SCOPES)->default('read-write'),
                    ])
                    ->run(fn (ApiClient $api, array $row, array $data) => self::credentials($api->post('/buckets/provision', array_filter($data)), __('.env of the app'))),
            ])
            ->create(__('New bucket'), [
                Field::text('name', __('Name'))->required()->rules(['regex:/^[a-z0-9][a-z0-9._-]{1,254}$/']),
            ], fn (ApiClient $api, array $row, array $data) => tap(__('Bucket created.'), fn () => $api->post('/buckets', ['name' => $data['name']])))
            ->actions([
                Action::make('settings', __('Usage and settings'))->icon('chart-bar')
                    ->run(fn (ApiClient $api, array $row) => Outcome::show($row['name'], $api->get(self::bucket($row['name']).'/settings')['data'] ?? [])),
                Action::make('quotas', __('Quotas'))->icon('adjustments-horizontal')
                    ->fields([
                        Field::number('max_gb', __('Most space, GB'))->help(__('Empty: no limit.')),
                        Field::number('max_objects', __('Most objects'))->help(__('Empty: no limit.')),
                    ])
                    ->run(function (ApiClient $api, array $row, array $data) {
                        $api->put(self::bucket($row['name']).'/quotas', [
                            'max_size' => ($data['max_gb'] ?? null) ? (int) $data['max_gb'] * 1024 ** 3 : null,
                            'max_objects' => ($data['max_objects'] ?? null) ?: null,
                        ]);

                        return __('Quotas saved.');
                    }),
                Action::make('cors', __('Allow a site (CORS)'))->icon('globe')
                    ->fields([
                        Field::textarea('origins', __('Sites'))->required()->placeholder("https://app.example.com")->help(__('One per line, with https://, or * for any.')),
                        Field::multiselect('methods', __('Methods'), ['GET' => 'GET', 'HEAD' => 'HEAD', 'PUT' => 'PUT', 'POST' => 'POST', 'DELETE' => 'DELETE'])->default(['GET', 'HEAD', 'PUT']),
                    ])
                    ->run(function (ApiClient $api, array $row, array $data) {
                        $api->put(self::bucket($row['name']).'/cors', [
                            'origins' => S3Sections::origins($data['origins'] ?? ''),
                            'methods' => array_values((array) ($data['methods'] ?? [])) ?: ['GET', 'HEAD'],
                            'headers' => ['*'],
                            'max_age' => 3600,
                        ]);

                        return __('CORS saved.');
                    }),
                Action::make('lifecycle', __('Expiry rules'))->icon('clock')
                    ->fields([
                        Field::number('expire_days', __('Delete objects after (days)'))->help(__('0: never.'))->default(0),
                        Field::text('expire_prefix', __('Only under the prefix'))->placeholder('tmp/'),
                        Field::number('abort_days', __('Abort unfinished uploads after (days)'))->default(7),
                    ])
                    ->run(function (ApiClient $api, array $row, array $data) {
                        $api->put(self::bucket($row['name']).'/lifecycle', [
                            'expire_days' => (int) ($data['expire_days'] ?? 0),
                            'expire_prefix' => (string) ($data['expire_prefix'] ?? ''),
                            'abort_days' => (int) ($data['abort_days'] ?? 0),
                        ]);

                        return __('Expiry rules saved.');
                    }),
                Action::make('grant', __('Grant a key'))->icon('key')
                    ->fields([
                        Field::select('key_id', __('Key'), fn (ApiClient $api) => self::keyOptions($api))->required(),
                        Field::select('scope', __('What it may do here'), self::SCOPES)->default('read-write'),
                    ])
                    ->run(fn (ApiClient $api, array $row, array $data) => tap(__('Key granted on :bucket.', ['bucket' => $row['name']]), fn () => $api->post(self::bucket($row['name']).'/keys', ['key_id' => $data['key_id'], 'scope' => $data['scope']]))),
                Action::make('delete', __('Delete'))->icon('trash')->danger(__('Delete the bucket? It must be empty.'))
                    ->run(fn (ApiClient $api, array $row) => tap(__('Bucket deleted.'), fn () => $api->delete(self::bucket($row['name'])))),
            ]);
    }

    private function keys(): Section
    {
        return Section::make('keys', __('Access keys'))
            ->icon('key')
            ->list(fn (ApiClient $api) => self::data($api, '/keys'))
            ->columns([
                Column::make('name', __('Name')),
                Column::make('id', __('Key ID'))->mono(),
                Column::make('all_buckets', __('All buckets'))->bool(),
                Column::make('scope', __('Scope'))->using(fn ($row) => is_array($row['scope'] ?? null) ? ($row['scope']['id'] ?? '') : ($row['scope'] ?? '')),
                Column::make('created', __('Created'))->date(),
            ])
            ->create(__('New key'), [
                Field::text('name', __('Name'))->required(),
                Field::select('access', __('Opens'), ['buckets' => __('The buckets picked below'), 'all' => __('Every bucket, new ones too'), 'none' => __('Nothing yet')])->default('buckets'),
                Field::multiselect('buckets', __('Buckets'), fn (ApiClient $api) => self::bucketOptions($api)),
                Field::select('scope', __('What it may do'), self::SCOPES)->default('read-write'),
            ], function (ApiClient $api, array $row, array $data) {
                $answer = $api->post('/keys', array_filter($data, fn ($v) => $v !== null && $v !== []));
                $key = (array) ($answer['data'] ?? $answer);
                $failed = (array) ($key['failed_buckets'] ?? []);

                return Outcome::show(
                    __('Key :name', ['name' => $key['name'] ?? $data['name']]),
                    S3Sections::env(['AWS_ACCESS_KEY_ID' => (string) data_get($key, 'credentials.access_key_id', ''), 'AWS_SECRET_ACCESS_KEY' => (string) data_get($key, 'credentials.secret_access_key', '')])
                        ."\n\n# ".__('The secret is shown once.').($failed !== [] ? "\n# ".__('Not granted on: :buckets', ['buckets' => implode(', ', $failed)]) : ''),
                    __('Key created.'),
                );
            })
            ->actions([
                Action::make('show', __('Buckets it opens'))->icon('eye')
                    ->run(fn (ApiClient $api, array $row) => Outcome::show($row['name'] ?? $row['id'], data_get($api->get('/keys/'.rawurlencode($row['id'])), 'data.buckets', []))),
                Action::make('all', __('Open every bucket'))->icon('lock-open')
                    ->when(fn ($row) => ! ($row['all_buckets'] ?? false))
                    ->fields([Field::select('scope', __('What it may do'), self::SCOPES)->default('read')])
                    ->run(fn (ApiClient $api, array $row, array $data) => tap(__('The key opens every bucket.'), fn () => $api->put('/keys/'.rawurlencode($row['id']).'/all-buckets', ['scope' => $data['scope']]))),
                Action::make('not-all', __('Stop opening new buckets'))->icon('lock-closed')
                    ->when(fn ($row) => (bool) ($row['all_buckets'] ?? false))
                    ->run(fn (ApiClient $api, array $row) => tap(__('New buckets are no longer opened to the key.'), fn () => $api->delete('/keys/'.rawurlencode($row['id']).'/all-buckets'))),
                Action::make('delete', __('Delete'))->icon('trash')->danger(__('Delete the key? Apps using it lose access at once.'))
                    ->run(fn (ApiClient $api, array $row) => tap(__('Key deleted.'), fn () => $api->delete('/keys/'.rawurlencode($row['id'])))),
            ]);
    }

    private function connections(): Section
    {
        return Section::make('connections', __('Connections'))
            ->icon('link')
            ->description(__('Other providers and SFTP servers Johnny imports from, exports to and syncs with.'))
            ->list(fn (ApiClient $api) => self::data($api, '/connections'))
            ->columns([
                Column::make('name', __('Name')),
                Column::make('provider_label', __('Provider')),
                Column::make('endpoint', __('Endpoint'))->mono(),
                Column::make('reachable', __('Reachable'))->bool(),
                Column::make('checked_at', __('Checked'))->date(),
            ])
            ->create(__('New connection'), [
                Field::text('name', __('Name'))->required(),
                Field::select('provider', __('Provider'), self::PROVIDERS)->required()->default('aws'),
                Field::text('endpoint', __('Endpoint'))->placeholder('https://s3.eu-central-1.amazonaws.com')->help(__('S3 providers; empty for AWS.')),
                Field::text('region', __('Region'))->placeholder('eu-central-1'),
                Field::text('access_key_id', __('Access key ID')),
                Field::password('secret_access_key', __('Secret access key')),
                Field::text('host', __('SFTP host')),
                Field::number('port', __('SFTP port')),
                Field::text('username', __('SFTP user')),
                Field::password('password', __('SFTP password')),
                Field::textarea('private_key', __('SFTP private key'))->secret(),
            ], fn (ApiClient $api, array $row, array $data) => tap(__('Connection added.'), fn () => $api->post('/connections', array_filter($data, fn ($v) => $v !== null && $v !== ''))))
            ->actions([
                Action::make('check', __('Check'))->icon('play')
                    ->run(function (ApiClient $api, array $row) {
                        $answer = (array) ($api->post('/connections/'.$row['id'].'/check')['data'] ?? []);

                        return ($answer['reachable'] ?? false) ? __('Reachable.') : throw new ConnectorException(__('Not reachable: :reason', ['reason' => $answer['check_error'] ?? '?']));
                    }),
                Action::make('remote', __('Its buckets'))->icon('archive-box')
                    ->run(fn (ApiClient $api, array $row) => Outcome::show($row['name'], $api->get('/connections/'.$row['id'].'/buckets')['data'] ?? [])),
                Action::make('delete', __('Delete'))->icon('trash')->danger(__('Delete the connection? Its syncs stop.'))
                    ->run(fn (ApiClient $api, array $row) => tap(__('Connection deleted.'), fn () => $api->delete('/connections/'.$row['id']))),
            ]);
    }

    /** @return list<Field> */
    private static function moveFields(): array
    {
        return [
            Field::select('direction', __('Direction'), ['import' => __('Import into Johnny'), 'export' => __('Export from Johnny')])->required()->default('import'),
            Field::select('connection_id', __('Connection'), fn (ApiClient $api) => self::connectionOptions($api))->required(),
            Field::text('remote_bucket', __('Bucket (or folder) there'))->required(),
            Field::text('local_bucket', __('Bucket here'))->required(),
            Field::text('prefix', __('Only under the prefix')),
            Field::select('mode', __('Mode'), ['copy' => __('Copy (keep what is already there)'), 'sync' => __('Sync (delete what is gone)')])->default('copy'),
            Field::checkbox('create_bucket', __('Create the bucket if missing'))->default(true),
        ];
    }

    private function transfers(): Section
    {
        return Section::make('transfers', __('Transfers'))
            ->icon('arrows-right-left')
            ->list(fn (ApiClient $api) => self::data($api, '/transfers'))
            ->columns([
                Column::make('direction', __('Direction'))->badge(['import' => 'blue', 'export' => 'zinc']),
                Column::make('connection_name', __('Connection')),
                Column::make('local_bucket', __('Here'))->mono(),
                Column::make('remote_bucket', __('There'))->mono(),
                Column::make('status', __('Status'))->badge(['done' => 'green', 'finished' => 'green', 'running' => 'blue', 'queued' => 'zinc', 'failed' => 'red', 'cancelled' => 'zinc', '*' => 'yellow']),
                Column::make('percent', __('Progress'))->using(fn ($row) => isset($row['percent']) ? round((float) $row['percent']).'%' : ''),
                Column::make('created_at', __('Started'))->date(),
            ])
            ->create(__('Import or export'), [
                ...self::moveFields(),
                Field::checkbox('dry_run', __('Dry run (only say what would move)')),
            ], fn (ApiClient $api, array $row, array $data) => tap(__('Transfer started.'), fn () => $api->post('/transfers', [...array_filter($data, fn ($v) => $v !== null && $v !== ''), 'connection_id' => (int) $data['connection_id']])))
            ->actions([
                Action::make('log', __('Log'))->icon('document-text')
                    ->run(fn (ApiClient $api, array $row) => Outcome::show(__('Transfer :id', ['id' => $row['id']]), implode("\n", (array) data_get($api->get('/transfers/'.$row['id']), 'data.log', [])) ?: __('Nothing logged yet.'))),
                Action::make('cancel', __('Cancel'))->icon('stop')->when(fn ($row) => (bool) ($row['active'] ?? false))->confirm(__('Cancel the transfer?'))
                    ->run(fn (ApiClient $api, array $row) => tap(__('Cancelling.'), fn () => $api->post('/transfers/'.$row['id'].'/cancel'))),
                Action::make('retry', __('Run again'))->icon('arrow-path')->when(fn ($row) => ! ($row['active'] ?? false))
                    ->run(fn (ApiClient $api, array $row) => tap(__('Started again.'), fn () => $api->post('/transfers/'.$row['id'].'/retry', ['for_real' => false]))),
                Action::make('for-real', __('Run for real'))->icon('play')->when(fn ($row) => ! ($row['active'] ?? false) && (bool) ($row['dry_run'] ?? false))
                    ->confirm(__('Run the dry run for real? Data moves this time.'))
                    ->run(fn (ApiClient $api, array $row) => tap(__('Started for real.'), fn () => $api->post('/transfers/'.$row['id'].'/retry', ['for_real' => true]))),
                Action::make('delete', __('Remove from history'))->icon('trash')->when(fn ($row) => ! ($row['active'] ?? false))->danger(__('Remove the transfer from the history? The data stays.'))
                    ->run(fn (ApiClient $api, array $row) => tap(__('Removed.'), fn () => $api->delete('/transfers/'.$row['id']))),
            ]);
    }

    private function syncs(): Section
    {
        return Section::make('syncs', __('Scheduled syncs'))
            ->icon('clock')
            ->list(fn (ApiClient $api) => self::data($api, '/syncs'))
            ->columns([
                Column::make('direction', __('Direction'))->badge(['import' => 'blue', 'export' => 'zinc']),
                Column::make('connection_name', __('Connection')),
                Column::make('local_bucket', __('Here'))->mono(),
                Column::make('remote_bucket', __('There'))->mono(),
                Column::make('interval_label', __('Every')),
                Column::make('enabled', __('On'))->bool(),
                Column::make('latest_run.status', __('Last run'))->badge(['done' => 'green', 'finished' => 'green', 'failed' => 'red', '*' => 'zinc']),
                Column::make('next_run_at', __('Next'))->date(),
            ])
            ->create(__('New sync'), [
                ...self::moveFields(),
                Field::number('interval_minutes', __('Every (minutes)'))->required()->default(60)->rules(['min:1']),
            ], fn (ApiClient $api, array $row, array $data) => tap(__('Sync scheduled.'), fn () => $api->post('/syncs', [...array_filter($data, fn ($v) => $v !== null && $v !== ''), 'connection_id' => (int) $data['connection_id']])))
            ->actions([
                Action::make('run', __('Run now'))->icon('play')
                    ->run(fn (ApiClient $api, array $row) => tap(__('Sync started.'), fn () => $api->post('/syncs/'.$row['id'].'/run'))),
                Action::make('pause', __('Pause'))->icon('pause')->when(fn ($row) => (bool) ($row['enabled'] ?? false))
                    ->run(fn (ApiClient $api, array $row) => tap(__('Sync paused.'), fn () => $api->put('/syncs/'.$row['id'], ['enabled' => false]))),
                Action::make('resume', __('Resume'))->icon('play')->when(fn ($row) => ! ($row['enabled'] ?? false))
                    ->run(fn (ApiClient $api, array $row) => tap(__('Sync resumed.'), fn () => $api->put('/syncs/'.$row['id'], ['enabled' => true]))),
                Action::make('delete', __('Delete'))->icon('trash')->danger(__('Delete the sync and its history?'))
                    ->run(fn (ApiClient $api, array $row) => tap(__('Sync deleted.'), fn () => $api->delete('/syncs/'.$row['id']))),
            ]);
    }
}
