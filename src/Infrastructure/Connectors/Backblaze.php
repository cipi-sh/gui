<?php

namespace CipiGui\Infrastructure\Connectors;

use CipiGui\Infrastructure\Action;
use CipiGui\Infrastructure\Column;
use CipiGui\Infrastructure\Connector;
use CipiGui\Infrastructure\ConnectorException;
use CipiGui\Infrastructure\Field;
use CipiGui\Infrastructure\Outcome;
use CipiGui\Infrastructure\S3\S3Sections;
use CipiGui\Infrastructure\ScopedClient;
use CipiGui\Infrastructure\Section;
use CipiGui\Infrastructure\Tokens;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Backblaze B2, with an application key, through B2's own API (v3): buckets,
 * public or private, their files folder by folder with links that expire,
 * and application keys for one bucket or all of them, with the .env of a
 * Laravel app on B2's S3 endpoint. https://www.backblaze.com/apidocs
 */
final class Backblaze extends Connector
{
    private const READ = ['listBuckets', 'listFiles', 'readFiles', 'shareFiles'];

    private const WRITE = ['writeFiles', 'deleteFiles'];

    public function key(): string
    {
        return 'backblaze';
    }

    public function label(): string
    {
        return 'Backblaze B2';
    }

    public function short(): string
    {
        return 'B2';
    }

    public function description(): string
    {
        return __('Buckets public or private, their files with links that expire, application keys per bucket with the .env of an app on B2\'s S3 endpoint.');
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
        return 'https://www.backblaze.com/docs/cloud-storage-application-keys';
    }

    public function fields(): array
    {
        return [
            Field::text('key_id', __('Key ID'))->required()->placeholder('0051a2b3c…'),
            Field::password('application_key', __('Application key'))->required(),
        ];
    }

    public function instructions(): array
    {
        return [
            __('secure.backblaze.com → Application Keys → Add a New Application Key.'),
            __('Allow access to: All buckets, Type of access: Read and Write, and tick "Allow List All Bucket Names". The panel then lists buckets and makes keys for apps.'),
            __('Creating and removing keys needs the master application key, or a key with the writeKeys capability (B2 CLI: b2 key create --all-capabilities).'),
            __('The key ID and the key are shown once: paste both here.'),
        ];
    }

    public function client(array $credentials): ScopedClient
    {
        $id = self::string($credentials, 'key_id');
        $secret = self::string($credentials, 'application_key');

        $session = Tokens::session('b2:'.$id.'|'.$secret, function () use ($id, $secret) {
            try {
                $response = Http::withBasicAuth($id, $secret)->acceptJson()->connectTimeout(10)->timeout(30)
                    ->get('https://api.backblazeb2.com/b2api/v3/b2_authorize_account');
            } catch (ConnectionException $e) {
                throw new ConnectorException(__('Not reachable: :reason', ['reason' => $e->getMessage()]), previous: $e);
            }

            $json = (array) $response->json();

            if ($response->failed()) {
                throw new ConnectorException(__('Sign-in refused (:status): :message', ['status' => $response->status(), 'message' => (string) ($json['message'] ?? $response->body())]), $response->status());
            }

            $api = (array) data_get($json, 'apiInfo.storageApi', []);

            // B2 tokens live 24 hours.
            return [[
                'token' => (string) ($json['authorizationToken'] ?? ''),
                'account' => (string) ($json['accountId'] ?? ''),
                'api' => (string) ($api['apiUrl'] ?? ''),
                'download' => (string) ($api['downloadUrl'] ?? ''),
                's3' => (string) ($api['s3ApiUrl'] ?? ''),
                'bucket' => (string) ($api['bucketId'] ?? ''),
                'capabilities' => implode(',', (array) ($api['capabilities'] ?? [])),
            ], 23 * 3600];
        });

        if (($session['api'] ?? '') === '' || ! str_starts_with((string) $session['api'], 'https://')) {
            throw new ConnectorException(__('B2 gave no API address for the key.'));
        }

        return new ScopedClient((string) $session['api'].'/b2api/v3', ['Authorization' => (string) $session['token']], array_map('strval', $session));
    }

    public function verify(object $api): string
    {
        /** @var ScopedClient $api */
        $buckets = (array) ($api->post('/b2_list_buckets', ['accountId' => $api->scope('account')])['buckets'] ?? []);

        return 'ok · '.trans_choice(':count bucket|:count buckets', count($buckets)).' · '.preg_replace('#^https://s3\.([^.]+)\..*$#', '$1', $api->scope('s3'));
    }

    public function sections(): array
    {
        return [
            $this->buckets(),
            $this->files(),
            $this->keys(),
        ];
    }

    /** eu-central-003 out of https://s3.eu-central-003.backblazeb2.com. */
    private static function region(ScopedClient $api): string
    {
        return (string) preg_replace('#^https://s3\.([^.]+)\..*$#', '$1', $api->scope('s3'));
    }

    /** @return list<array<string, mixed>> */
    private static function bucketRows(ScopedClient $api): array
    {
        return array_values(array_filter((array) ($api->post('/b2_list_buckets', ['accountId' => $api->scope('account')])['buckets'] ?? []), 'is_array'));
    }

    /** @return array<string, string> */
    private static function bucketOptions(ScopedClient $api): array
    {
        $out = ['' => __('Every bucket')];

        foreach (self::bucketRows($api) as $bucket) {
            $out[(string) $bucket['bucketId']] = (string) $bucket['bucketName'];
        }

        return $out;
    }

    private function buckets(): Section
    {
        return Section::make('buckets', __('Buckets'))
            ->icon('archive-box')
            ->id('bucketId')
            ->list(fn (ScopedClient $api) => self::bucketRows($api))
            ->columns([
                Column::make('bucketName', __('Bucket'))->mono(),
                Column::make('bucketType', __('Access'))->badge(['allprivate' => 'zinc', 'allpublic' => 'yellow', '*' => 'blue']),
                Column::make('lifecycleRules', __('Lifecycle'))->using(fn ($row) => count((array) ($row['lifecycleRules'] ?? [])) ?: ''),
                Column::make('bucketId', __('ID'))->mono(),
            ])
            ->create(__('New bucket'), [
                Field::text('bucketName', __('Name'))->required()->rules(['regex:/^[A-Za-z0-9-]{6,63}$/'])->help(__('6 to 63 letters, digits and dashes, unique across B2.')),
                Field::select('bucketType', __('Access'), ['allPrivate' => __('Private'), 'allPublic' => __('Public (anyone can download)')])->default('allPrivate'),
            ], fn (ScopedClient $api, array $row, array $data) => tap(__('Bucket created.'), fn () => $api->post('/b2_create_bucket', ['accountId' => $api->scope('account'), 'bucketName' => $data['bucketName'], 'bucketType' => $data['bucketType'] ?? 'allPrivate'])))
            ->actions([
                Action::make('browse', __('Browse'))->icon('archive-box')
                    ->opens('files', fn (array $row) => ['bucket' => (string) $row['bucketName'], 'bucketId' => (string) $row['bucketId'], 'prefix' => '']),
                Action::make('private', __('Make private'))->icon('lock-closed')->when(fn ($row) => ($row['bucketType'] ?? '') === 'allPublic')
                    ->run(fn (ScopedClient $api, array $row) => tap(__('The bucket is private.'), fn () => $api->post('/b2_update_bucket', ['accountId' => $api->scope('account'), 'bucketId' => $row['bucketId'], 'bucketType' => 'allPrivate']))),
                Action::make('public', __('Make public'))->icon('lock-open')->when(fn ($row) => ($row['bucketType'] ?? '') === 'allPrivate')
                    ->confirm(__('Make the bucket public? Anyone with a file\'s address can download it.'))
                    ->run(fn (ScopedClient $api, array $row) => tap(__('The bucket is public.'), fn () => $api->post('/b2_update_bucket', ['accountId' => $api->scope('account'), 'bucketId' => $row['bucketId'], 'bucketType' => 'allPublic']))),
                Action::make('versions', __('Keep old versions'))->icon('clock')
                    ->fields([Field::number('days', __('Delete old versions after (days)'))->default(30)->help(__('0: keep only the latest version, delete the others after a day.'))])
                    ->run(function (ScopedClient $api, array $row, array $data) {
                        $days = max(0, (int) ($data['days'] ?? 30));
                        $api->post('/b2_update_bucket', [
                            'accountId' => $api->scope('account'),
                            'bucketId' => $row['bucketId'],
                            'lifecycleRules' => [['fileNamePrefix' => '', 'daysFromHidingToDeleting' => max(1, $days), 'daysFromUploadingToHiding' => null]],
                        ]);

                        return __('Old versions are deleted :days day(s) after they are replaced.', ['days' => max(1, $days)]);
                    }),
                Action::make('cors', __('Allow a site (CORS)'))->icon('globe')
                    ->fields([Field::textarea('origins', __('Sites'))->required()->placeholder('https://app.example.com')->help(__('One per line. The rule replaces the bucket\'s CORS.'))])
                    ->run(function (ScopedClient $api, array $row, array $data) {
                        $origins = S3Sections::origins($data['origins'] ?? '');
                        $api->post('/b2_update_bucket', [
                            'accountId' => $api->scope('account'),
                            'bucketId' => $row['bucketId'],
                            'corsRules' => [[
                                'corsRuleName' => 'cipi',
                                'allowedOrigins' => $origins,
                                'allowedOperations' => ['s3_get', 's3_head', 's3_put', 'b2_download_file_by_name', 'b2_download_file_by_id'],
                                'allowedHeaders' => ['*'],
                                'exposeHeaders' => ['ETag'],
                                'maxAgeSeconds' => 3600,
                            ]],
                        ]);

                        return __('CORS set for :origins.', ['origins' => implode(', ', $origins)]);
                    }),
                Action::make('app-key', __('Key for an app'))->icon('key')
                    ->fields([Field::checkbox('read_only', __('Read only'))])
                    ->run(fn (ScopedClient $api, array $row, array $data) => self::createKey($api, 'cipi-'.$row['bucketName'], (string) $row['bucketId'], (string) $row['bucketName'], (bool) ($data['read_only'] ?? false))),
                Action::make('delete', __('Delete'))->icon('trash')->danger(__('Delete the bucket? It must be empty, old versions included.'))
                    ->run(fn (ScopedClient $api, array $row) => tap(__('Bucket deleted.'), fn () => $api->post('/b2_delete_bucket', ['accountId' => $api->scope('account'), 'bucketId' => $row['bucketId']]))),
            ]);
    }

    private static function createKey(ScopedClient $api, string $name, string $bucketId, string $bucketName, bool $readOnly): Outcome
    {
        $capabilities = $readOnly ? self::READ : [...self::READ, ...self::WRITE];

        $key = $api->post('/b2_create_key', array_filter([
            'accountId' => $api->scope('account'),
            'keyName' => substr((string) preg_replace('/[^A-Za-z0-9-]+/', '-', $name), 0, 100),
            'capabilities' => $bucketId === '' ? [...$capabilities, 'listAllBucketNames'] : $capabilities,
            'bucketId' => $bucketId !== '' ? $bucketId : null,
        ], fn ($v) => $v !== null));

        $env = array_filter([
            'AWS_ACCESS_KEY_ID' => (string) ($key['applicationKeyId'] ?? ''),
            'AWS_SECRET_ACCESS_KEY' => (string) ($key['applicationKey'] ?? ''),
            'AWS_DEFAULT_REGION' => self::region($api),
            'AWS_BUCKET' => $bucketName,
            'AWS_ENDPOINT' => $api->scope('s3'),
        ]);

        return Outcome::show(__('.env of an app on B2'), S3Sections::env($env)."\n\n# ".__('The key is shown once.'), __('Key created.'));
    }

    private function files(): Section
    {
        return Section::make('files', __('Files'))
            ->childOf('buckets', 'bucket')
            ->icon('document')
            ->id(fn (array $row) => $row['kind'].':'.$row['key'])
            ->list(function (ScopedClient $api, array $scope) {
                $prefix = (string) ($scope['prefix'] ?? '');
                $where = ['bucket' => (string) $scope['bucket'], 'bucketId' => (string) $scope['bucketId']];
                $answer = $api->post('/b2_list_file_names', ['bucketId' => $scope['bucketId'], 'prefix' => $prefix, 'delimiter' => '/', 'maxFileCount' => 1000]);
                $rows = [];

                if ($prefix !== '') {
                    $up = dirname(rtrim($prefix, '/'));
                    $rows[] = [...$where, 'kind' => 'up', 'key' => $up === '.' ? '' : $up.'/', 'name' => '..', 'id' => '', 'size' => '', 'uploaded' => ''];
                }

                foreach ((array) ($answer['files'] ?? []) as $file) {
                    if (! is_array($file) || ($file['fileName'] ?? '') === $prefix) {
                        continue;
                    }

                    $folder = ($file['action'] ?? '') === 'folder';
                    $rows[] = [
                        ...$where,
                        'kind' => $folder ? 'folder' : 'file',
                        'key' => (string) $file['fileName'],
                        'name' => basename(rtrim((string) $file['fileName'], '/')).($folder ? '/' : ''),
                        'id' => (string) ($file['fileId'] ?? ''),
                        'size' => $folder ? '' : S3Sections::size((int) ($file['contentLength'] ?? 0)),
                        'uploaded' => isset($file['uploadTimestamp']) && ! $folder ? gmdate('c', intdiv((int) $file['uploadTimestamp'], 1000)) : '',
                    ];
                }

                return $rows;
            })
            ->columns([
                Column::make('name', __('Name'))->mono(),
                Column::make('size', __('Size')),
                Column::make('uploaded', __('Uploaded'))->date(),
            ])
            ->actions([
                Action::make('open', __('Open'))->icon('chevron-right')
                    ->when(fn ($row) => in_array($row['kind'] ?? '', ['folder', 'up'], true))
                    ->opens('files', fn (array $row) => ['bucket' => (string) $row['bucket'], 'bucketId' => (string) $row['bucketId'], 'prefix' => (string) $row['key']]),
                Action::make('link', __('Link that expires'))->icon('link')
                    ->when(fn ($row) => ($row['kind'] ?? '') === 'file')
                    ->fields([Field::number('hours', __('Hours'))->default(24)->required()->rules(['min:1', 'max:168'])])
                    ->run(function (ScopedClient $api, array $row, array $data) {
                        $seconds = max(1, min(168, (int) ($data['hours'] ?? 24))) * 3600;
                        $auth = $api->post('/b2_get_download_authorization', ['bucketId' => $row['bucketId'], 'fileNamePrefix' => $row['key'], 'validDurationInSeconds' => $seconds]);
                        $path = implode('/', array_map('rawurlencode', explode('/', (string) $row['key'])));

                        return Outcome::show(__('Link to :name', ['name' => $row['name']]), $api->scope('download').'/file/'.rawurlencode((string) $row['bucket']).'/'.$path.'?Authorization='.rawurlencode((string) ($auth['authorizationToken'] ?? '')));
                    }),
                Action::make('delete', __('Delete this version'))->icon('trash')->danger(__('Delete this version of the file? Older versions, if any, stay.'))
                    ->when(fn ($row) => ($row['kind'] ?? '') === 'file')
                    ->run(fn (ScopedClient $api, array $row) => tap(__(':name deleted.', ['name' => $row['name']]), fn () => $api->post('/b2_delete_file_version', ['fileName' => $row['key'], 'fileId' => $row['id']]))),
            ]);
    }

    private function keys(): Section
    {
        return Section::make('keys', __('Application keys'))
            ->icon('key')
            ->id('applicationKeyId')
            ->list(fn (ScopedClient $api) => array_values(array_filter((array) ($api->post('/b2_list_keys', ['accountId' => $api->scope('account'), 'maxKeyCount' => 1000])['keys'] ?? []), 'is_array')))
            ->columns([
                Column::make('keyName', __('Name')),
                Column::make('applicationKeyId', __('Key ID'))->mono(),
                Column::make('bucketId', __('Bucket'))->mono()->using(fn ($row) => $row['bucketId'] ?? implode(', ', (array) ($row['bucketIds'] ?? [])) ?: __('every bucket')),
                Column::make('capabilities', __('Capabilities'))->using(fn ($row) => implode(', ', (array) ($row['capabilities'] ?? []))),
                Column::make('expirationTimestamp', __('Expires'))->using(fn ($row) => isset($row['expirationTimestamp']) ? gmdate('Y-m-d', intdiv((int) $row['expirationTimestamp'], 1000)) : __('never')),
            ])
            ->create(__('New key'), [
                Field::text('name', __('Name'))->required()->rules(['regex:/^[A-Za-z0-9-]{1,100}$/']),
                Field::select('bucket', __('Bucket'), fn (ScopedClient $api) => self::bucketOptions($api)),
                Field::checkbox('read_only', __('Read only')),
            ], function (ScopedClient $api, array $row, array $data) {
                $bucket = (string) ($data['bucket'] ?? '');
                $name = $bucket === '' ? '' : (string) (self::bucketOptions($api)[$bucket] ?? '');

                return self::createKey($api, (string) $data['name'], $bucket, $name, (bool) ($data['read_only'] ?? false));
            })
            ->actions([
                Action::make('delete', __('Delete'))->icon('trash')->danger(__('Delete the key? Whatever uses it loses access at once.'))
                    ->run(fn (ScopedClient $api, array $row) => tap(__('Key deleted.'), fn () => $api->post('/b2_delete_key', ['applicationKeyId' => $row['applicationKeyId']]))),
            ]);
    }
}
