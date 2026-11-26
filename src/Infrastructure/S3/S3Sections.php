<?php

namespace CipiGui\Infrastructure\S3;

use CipiGui\Infrastructure\Action;
use CipiGui\Infrastructure\Column;
use CipiGui\Infrastructure\ConnectorException;
use CipiGui\Infrastructure\Field;
use CipiGui\Infrastructure\Outcome;
use CipiGui\Infrastructure\Section;
use Closure;

/**
 * What every S3 provider's page has in common (AWS S3, Wasabi, Spaces): the
 * objects of a bucket folder by folder, links that expire, CORS for the
 * browsers of an app, and keys an app can use on one bucket only.
 */
final class S3Sections
{
    /**
     * The actions of a bucket row.
     *
     * @param  Closure(object): S3Client  $s3
     * @param  Closure(object, array<string, mixed>): string  $regionOf  where the bucket of a row lives
     * @param  Closure(array<string, mixed>): string  $nameOf  the bucket of a row
     * @param  (Closure(object): Iam)|null  $iam  null where the provider has no IAM
     * @param  (Closure(string, string): array<string, string>)|null  $env  the .env of an app on bucket and region
     * @return list<Action>
     */
    public static function bucketActions(string $objectsKey, Closure $s3, Closure $regionOf, Closure $nameOf, ?Closure $iam = null, ?Closure $env = null): array
    {
        $actions = [
            Action::make('browse', __('Browse'))->icon('archive-box')
                ->opens($objectsKey, fn (array $row) => ['bucket' => $nameOf($row), 'region' => (string) ($row['_region'] ?? $row['BucketRegion'] ?? ''), 'prefix' => '']),
            Action::make('cors', __('CORS'))->icon('eye')
                ->run(function (object $api, array $row) use ($s3, $regionOf, $nameOf) {
                    $rules = $s3($api)->cors($nameOf($row), $regionOf($api, $row));

                    return Outcome::show(__('CORS of :bucket', ['bucket' => $nameOf($row)]), $rules === [] ? __('No CORS rule: browsers on other sites cannot read the bucket.') : $rules);
                }),
            Action::make('cors-set', __('Allow a site (CORS)'))->icon('globe')
                ->fields([
                    Field::textarea('origins', __('Sites'))->required()->placeholder("https://app.example.com\nhttps://admin.example.com")->help(__('One per line, with https://. The rule replaces the bucket\'s CORS.')),
                    Field::multiselect('methods', __('Methods'), ['GET' => 'GET', 'HEAD' => 'HEAD', 'PUT' => 'PUT', 'POST' => 'POST', 'DELETE' => 'DELETE'])->default(['GET', 'HEAD', 'PUT']),
                ])
                ->run(function (object $api, array $row, array $data) use ($s3, $regionOf, $nameOf) {
                    $origins = self::origins($data['origins'] ?? '');
                    $methods = array_values(array_intersect(array_map('strtoupper', (array) ($data['methods'] ?? [])), ['GET', 'HEAD', 'PUT', 'POST', 'DELETE'])) ?: ['GET', 'HEAD'];
                    $s3($api)->putCors($nameOf($row), $origins, $methods, 3600, $regionOf($api, $row));

                    return __('CORS set: :origins may use :methods.', ['origins' => implode(', ', $origins), 'methods' => implode(', ', $methods)]);
                }),
            Action::make('cors-delete', __('Remove CORS'))->icon('x')->confirm(__('Remove the CORS rules of the bucket? Browsers on other sites lose access.'))
                ->run(function (object $api, array $row) use ($s3, $regionOf, $nameOf) {
                    $s3($api)->deleteCors($nameOf($row), $regionOf($api, $row));

                    return __('CORS removed.');
                }),
        ];

        if ($iam !== null) {
            $actions[] = Action::make('app-key', __('Key for an app'))->icon('key')
                ->fields([
                    Field::checkbox('read_only', __('Read only'))->help(__('The app reads the bucket but never writes or deletes.')),
                ])
                ->run(function (object $api, array $row, array $data) use ($iam, $regionOf, $nameOf, $env) {
                    $bucket = $nameOf($row);
                    $key = $iam($api)->createAppKey($bucket, (bool) ($data['read_only'] ?? false));
                    $lines = [
                        'AWS_ACCESS_KEY_ID' => $key['access_key_id'],
                        'AWS_SECRET_ACCESS_KEY' => $key['secret_access_key'],
                        ...($env ? $env($bucket, $regionOf($api, $row)) : ['AWS_BUCKET' => $bucket]),
                    ];

                    return Outcome::show(
                        __('.env of an app on :bucket', ['bucket' => $bucket]),
                        self::env($lines)."\n\n# ".__('The secret is shown once. The key opens :bucket only (user :user).', ['bucket' => $bucket, 'user' => $key['user']]),
                        __('Key created.'),
                    );
                });
        }

        return $actions;
    }

    /**
     * The objects of one folder of a bucket, a child list: folders open, files get a link.
     *
     * @param  Closure(object): S3Client  $s3
     * @param  Closure(object, array<string, mixed>): string  $regionOf  where the bucket of the scope lives (the scope's region, once known)
     */
    public static function objects(string $key, string $parent, Closure $s3, Closure $regionOf): Section
    {
        return Section::make($key, __('Objects'))
            ->childOf($parent, 'bucket')
            ->icon('document')
            ->id(fn (array $row) => $row['kind'].':'.$row['key'])
            ->list(function (object $api, array $scope) use ($s3, $regionOf) {
                $bucket = (string) ($scope['bucket'] ?? '');
                $prefix = (string) ($scope['prefix'] ?? '');
                $region = $regionOf($api, $scope);
                $listing = $s3($api)->objects($bucket, $prefix, $region);
                $rows = [];
                $where = ['bucket' => $bucket, 'region' => $region];

                if ($prefix !== '') {
                    $up = dirname(rtrim($prefix, '/'));
                    $rows[] = [...$where, 'kind' => 'up', 'key' => $up === '.' ? '' : $up.'/', 'name' => '..', 'size' => '', 'modified' => '', 'class' => ''];
                }

                foreach ($listing['folders'] as $folder) {
                    $rows[] = [...$where, 'kind' => 'folder', 'key' => $folder, 'name' => basename(rtrim($folder, '/')).'/', 'size' => '', 'modified' => '', 'class' => ''];
                }

                foreach ($listing['objects'] as $object) {
                    $rows[] = [
                        ...$where,
                        'kind' => 'file',
                        'key' => (string) ($object['Key'] ?? ''),
                        'name' => basename((string) ($object['Key'] ?? '')),
                        'size' => self::size((int) ($object['Size'] ?? 0)),
                        'modified' => (string) ($object['LastModified'] ?? ''),
                        'class' => (string) ($object['StorageClass'] ?? ''),
                    ];
                }

                if ($listing['truncated']) {
                    $rows[] = [...$where, 'kind' => 'more', 'key' => '', 'name' => __('… more than 1,000 here: open a folder, or use an S3 client'), 'size' => '', 'modified' => '', 'class' => ''];
                }

                return $rows;
            })
            ->columns([
                Column::make('name', __('Name'))->mono(),
                Column::make('size', __('Size')),
                Column::make('class', __('Class')),
                Column::make('modified', __('Modified'))->date(),
            ])
            ->actions([
                Action::make('open', __('Open'))->icon('chevron-right')
                    ->when(fn (array $row) => in_array($row['kind'] ?? '', ['folder', 'up'], true))
                    ->opens($key, fn (array $row) => ['bucket' => (string) $row['bucket'], 'region' => (string) $row['region'], 'prefix' => (string) $row['key']]),
                Action::make('link', __('Link that expires'))->icon('link')
                    ->when(fn (array $row) => ($row['kind'] ?? '') === 'file')
                    ->fields([Field::number('hours', __('Hours'))->default(24)->required()->rules(['min:1', 'max:168'])->help(__('A week at most.'))])
                    ->run(function (object $api, array $row, array $data, array $scope) use ($s3, $regionOf) {
                        $hours = max(1, min(168, (int) ($data['hours'] ?? 24)));

                        return Outcome::show(__('Link to :name, :hours h', ['name' => $row['name'], 'hours' => $hours]), $s3($api)->presign((string) $scope['bucket'], (string) $row['key'], $hours * 3600, $regionOf($api, $scope)));
                    }),
                Action::make('delete', __('Delete'))->icon('trash')->danger(__('Delete the object? Without versioning it is gone.'))
                    ->when(fn (array $row) => ($row['kind'] ?? '') === 'file')
                    ->run(function (object $api, array $row, array $data, array $scope) use ($s3, $regionOf) {
                        $s3($api)->deleteObject((string) $scope['bucket'], (string) $row['key'], $regionOf($api, $scope));

                        return __(':name deleted.', ['name' => $row['name']]);
                    }),
            ]);
    }

    /**
     * The keys the panel made for apps, one bucket each.
     *
     * @param  Closure(object): Iam  $iam
     */
    public static function appKeys(string $key, Closure $iam): Section
    {
        return Section::make($key, __('App keys'))
            ->icon('key')
            ->description(__('The keys made from a bucket\'s "Key for an app": one IAM user under /cipi/ each, allowed on that bucket only.'))
            ->id('user')
            ->list(fn (object $api) => $iam($api)->appKeys())
            ->columns([
                Column::make('bucket', __('Bucket'))->mono(),
                Column::make('keys', __('Access key'))->mono(),
                Column::make('user', __('IAM user'))->mono(),
                Column::make('created', __('Created'))->date(),
            ])
            ->actions([
                Action::make('delete', __('Revoke'))->icon('trash')->danger(__('Revoke the key? The apps using it lose the bucket at once.'))
                    ->run(function (object $api, array $row) use ($iam) {
                        $iam($api)->deleteUser((string) $row['user']);

                        return __('Key revoked.');
                    }),
            ]);
    }

    /**
     * Sites typed one per line: each an origin (https://host[:port]) or *.
     *
     * @return list<string>
     */
    public static function origins(mixed $text): array
    {
        $origins = [];

        foreach (preg_split('/[\s,]+/', is_string($text) ? $text : '') ?: [] as $origin) {
            $origin = rtrim(trim($origin), '/');

            if ($origin === '') {
                continue;
            }

            if ($origin !== '*' && ! preg_match('#^https?://(\*\.)?[a-z0-9.-]+(:\d{1,5})?$#i', $origin)) {
                throw new ConnectorException(__('":origin" is not a site: use https://app.example.com (no path), or *.', ['origin' => $origin]));
            }

            $origins[] = $origin;
        }

        return $origins ?: throw new ConnectorException(__('Name at least one site.'));
    }

    /** @param  array<string, string>  $lines */
    public static function env(array $lines): string
    {
        return implode("\n", array_map(fn ($name, $value) => $name.'='.(preg_match('/[\s#"]/', $value) ? '"'.addcslashes($value, '"').'"' : $value), array_keys($lines), $lines));
    }

    public static function size(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $i = 0;
        $value = (float) $bytes;

        while ($value >= 1024 && $i < count($units) - 1) {
            $value /= 1024;
            $i++;
        }

        return ($i === 0 ? (string) $bytes : number_format($value, $value < 10 ? 1 : 0)).' '.$units[$i];
    }
}
