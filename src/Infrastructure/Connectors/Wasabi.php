<?php

namespace CipiGui\Infrastructure\Connectors;

use CipiGui\Infrastructure\Action;
use CipiGui\Infrastructure\Column;
use CipiGui\Infrastructure\Connector;
use CipiGui\Infrastructure\Field;
use CipiGui\Infrastructure\S3\Iam;
use CipiGui\Infrastructure\S3\S3Account;
use CipiGui\Infrastructure\S3\S3Client;
use CipiGui\Infrastructure\S3\S3Sections;
use CipiGui\Infrastructure\Section;

/**
 * Wasabi, with an access key: buckets in every region, their objects folder
 * by folder with links that expire, CORS, and keys an app can use on one
 * bucket only (an IAM user of Wasabi's IAM with a policy for that bucket).
 * https://docs.wasabi.com/apidocs
 */
final class Wasabi extends Connector
{
    public const REGIONS = [
        'us-east-1' => 'US East 1 (N. Virginia)', 'us-east-2' => 'US East 2 (N. Virginia)', 'us-central-1' => 'US Central 1 (Texas)',
        'us-west-1' => 'US West 1 (Oregon)', 'us-west-2' => 'US West 2 (San Jose)', 'ca-central-1' => 'CA Central 1 (Toronto)',
        'eu-central-1' => 'EU Central 1 (Amsterdam)', 'eu-central-2' => 'EU Central 2 (Frankfurt)', 'eu-west-1' => 'EU West 1 (London)',
        'eu-west-2' => 'EU West 2 (Paris)', 'eu-west-3' => 'EU West 3 (London)', 'eu-south-1' => 'EU South 1 (Milan)',
        'ap-northeast-1' => 'AP Northeast 1 (Tokyo)', 'ap-northeast-2' => 'AP Northeast 2 (Osaka)', 'ap-southeast-1' => 'AP Southeast 1 (Singapore)',
        'ap-southeast-2' => 'AP Southeast 2 (Sydney)',
    ];

    public function key(): string
    {
        return 'wasabi';
    }

    public function label(): string
    {
        return 'Wasabi';
    }

    public function description(): string
    {
        return __('Buckets in every region, their objects with links that expire, CORS, and keys for one bucket each with the .env of the app.');
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
        return 'https://docs.wasabi.com/docs/creating-a-user-account-and-access-key';
    }

    public function fields(): array
    {
        return [
            Field::text('access_key_id', __('Access key'))->required(),
            Field::password('secret_access_key', __('Secret key'))->required(),
            Field::select('region', __('Default region'), self::REGIONS)->required()->default('eu-central-1'),
        ];
    }

    public function instructions(): array
    {
        return [
            __('console.wasabisys.com → Users → Create User, e.g. cipi-panel, with "Programmatic (create API key)".'),
            __('Policies: WasabiFullAccess, or AmazonS3FullAccess plus IAMFullAccess (IAM is what the "Key for an app" of a bucket uses).'),
            __('Download the access key and the secret key: Wasabi shows the secret once.'),
            __('The region is where new buckets go unless you pick another; the list shows the buckets of every region.'),
        ];
    }

    public function client(array $credentials): S3Account
    {
        $key = self::string($credentials, 'access_key_id');
        $secret = self::string($credentials, 'secret_access_key');

        return new S3Account(
            S3Client::make($key, $secret, 'https://s3.{region}.wasabisys.com', self::string($credentials, 'region', 'us-east-1')),
            Iam::make($key, $secret, 'https://iam.wasabisys.com/'),
        );
    }

    public function verify(object $api): string
    {
        /** @var S3Account $api */
        return 'ok · '.trans_choice(':count bucket|:count buckets', count($api->s3()->buckets()));
    }

    public function sections(): array
    {
        $s3 = fn (S3Account $api) => $api->s3();
        $iam = fn (S3Account $api) => $api->iam();
        $bucketRegion = fn (S3Account $api, array $row) => $api->s3()->location((string) $row['Name'], 'us-east-1');
        $scopeRegion = fn (S3Account $api, array $scope) => (string) ($scope['region'] ?? '') !== '' ? (string) $scope['region'] : $api->s3()->location((string) $scope['bucket'], 'us-east-1');
        $env = fn (string $bucket, string $region) => [
            'AWS_DEFAULT_REGION' => $region,
            'AWS_BUCKET' => $bucket,
            'AWS_ENDPOINT' => 'https://s3.'.$region.'.wasabisys.com',
            'AWS_USE_PATH_STYLE_ENDPOINT' => 'false',
        ];

        return [
            Section::make('buckets', __('Buckets'))
                ->icon('archive-box')
                ->id('Name')
                ->list(fn (S3Account $api) => $api->s3()->buckets())
                ->columns([
                    Column::make('Name', __('Bucket'))->mono(),
                    Column::make('CreationDate', __('Created'))->date(),
                ])
                ->create(__('New bucket'), [
                    Field::text('name', __('Name'))->required()->rules(['regex:/^[a-z0-9][a-z0-9.-]{1,61}[a-z0-9]$/']),
                    Field::select('region', __('Region'), ['' => __('Account default'), ...self::REGIONS]),
                ], function (S3Account $api, array $row, array $data) {
                    $region = $api->s3()->region($data['region'] ?? null);
                    $api->s3()->createBucket((string) $data['name'], $region, $region);

                    return __('Bucket created in :region, private.', ['region' => $region]);
                })
                ->actions([
                    ...S3Sections::bucketActions('objects', $s3, $bucketRegion, fn (array $row) => (string) $row['Name'], $iam, $env),
                    Action::make('region', __('Region'))->icon('map-pin')
                        ->run(fn (S3Account $api, array $row) => __(':bucket is in :region.', ['bucket' => $row['Name'], 'region' => $bucketRegion($api, $row)])),
                    Action::make('delete', __('Delete'))->icon('trash')->danger(__('Delete the bucket? It must be empty.'))
                        ->run(function (S3Account $api, array $row) use ($bucketRegion) {
                            $api->s3()->deleteBucket((string) $row['Name'], $bucketRegion($api, $row));

                            return __('Bucket deleted.');
                        }),
                ]),
            S3Sections::objects('objects', 'buckets', $s3, $scopeRegion),
            S3Sections::appKeys('app-keys', $iam),
        ];
    }
}
