<?php

namespace CipiGui\Infrastructure\S3;

use CipiGui\Infrastructure\Aws\Signer;
use CipiGui\Infrastructure\Aws\Xml;
use CipiGui\Infrastructure\ConnectorException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * The IAM query API (AWS, and Wasabi's copy of it), for one thing: keys an
 * app can use on one bucket only. Each is an IAM user under the path
 * /cipi/ with an inline policy for that bucket, so the panel lists and
 * removes only the keys it made.
 */
class Iam
{
    public const PATH = '/cipi/';

    public function __construct(
        private readonly Signer $signer,
        public readonly string $endpoint = 'https://iam.amazonaws.com/',
        private readonly string $region = 'us-east-1',
    ) {}

    public static function make(string $accessKey, string $secretKey, string $endpoint = 'https://iam.amazonaws.com/', ?string $sessionToken = null): self
    {
        return new self(new Signer($accessKey, $secretKey, $sessionToken), $endpoint);
    }

    /**
     * @param  array<string, scalar|null>  $params
     * @return array<mixed>
     */
    public function query(string $action, array $params = []): array
    {
        $body = http_build_query(['Action' => $action, 'Version' => '2010-05-08', ...array_filter($params, fn ($v) => $v !== null)], '', '&', PHP_QUERY_RFC3986);

        return Xml::parse($this->call($body));
    }

    /**
     * The keys the panel made, with the bucket each opens.
     *
     * @return list<array<string, mixed>>
     */
    public function appKeys(): array
    {
        $users = Xml::list(data_get($this->query('ListUsers', ['PathPrefix' => self::PATH, 'MaxItems' => 1000]), 'ListUsersResult.Users'));
        $rows = [];

        foreach ($users as $user) {
            if (! is_array($user)) {
                continue;
            }

            $name = (string) ($user['UserName'] ?? '');
            $keys = Xml::list(data_get($this->query('ListAccessKeys', ['UserName' => $name]), 'ListAccessKeysResult.AccessKeyMetadata'));

            $rows[] = [
                'user' => $name,
                'bucket' => self::bucketOf($name),
                'keys' => implode(', ', array_map(fn ($k) => is_array($k) ? (string) ($k['AccessKeyId'] ?? '') : '', $keys)),
                'created' => (string) ($user['CreateDate'] ?? ''),
            ];
        }

        return $rows;
    }

    /**
     * A user for one bucket, its policy and an access key: what an app puts in its .env.
     *
     * @return array{user: string, access_key_id: string, secret_access_key: string}
     */
    public function createAppKey(string $bucket, bool $readOnly = false): array
    {
        $user = 'cipi-'.substr((string) preg_replace('/[^A-Za-z0-9+=,.@-]+/', '-', $bucket), 0, 48).'-'.Str::lower(Str::random(6));

        $this->query('CreateUser', ['UserName' => $user, 'Path' => self::PATH]);

        try {
            $this->query('PutUserPolicy', [
                'UserName' => $user,
                'PolicyName' => 'cipi-bucket',
                'PolicyDocument' => (string) json_encode(self::policy($bucket, $readOnly), JSON_UNESCAPED_SLASHES),
            ]);

            $key = data_get($this->query('CreateAccessKey', ['UserName' => $user]), 'CreateAccessKeyResult.AccessKey', []);
        } catch (ConnectorException $e) {
            // No user left behind without its key.
            $this->deleteUser($user);

            throw $e;
        }

        return [
            'user' => $user,
            'access_key_id' => (string) data_get($key, 'AccessKeyId', ''),
            'secret_access_key' => (string) data_get($key, 'SecretAccessKey', ''),
        ];
    }

    /** The user, its keys and its policy: the app's access is gone. */
    public function deleteUser(string $user): void
    {
        if (! str_starts_with($user, 'cipi-')) {
            throw new ConnectorException(__('Only the keys the panel made can be removed from here.'));
        }

        foreach (Xml::list(data_get($this->query('ListAccessKeys', ['UserName' => $user]), 'ListAccessKeysResult.AccessKeyMetadata')) as $key) {
            if (is_array($key) && ($key['AccessKeyId'] ?? '') !== '') {
                $this->query('DeleteAccessKey', ['UserName' => $user, 'AccessKeyId' => (string) $key['AccessKeyId']]);
            }
        }

        try {
            $this->query('DeleteUserPolicy', ['UserName' => $user, 'PolicyName' => 'cipi-bucket']);
        } catch (ConnectorException) {
            // Never written (the creation stopped before): nothing to remove.
        }

        $this->query('DeleteUser', ['UserName' => $user]);
    }

    /**
     * What the user may do: list and read the bucket, and write in it unless read-only.
     *
     * @return array<string, mixed>
     */
    public static function policy(string $bucket, bool $readOnly = false): array
    {
        $objects = ['s3:GetObject'];

        if (! $readOnly) {
            $objects = [...$objects, 's3:PutObject', 's3:DeleteObject', 's3:AbortMultipartUpload', 's3:ListMultipartUploadParts'];
        }

        return [
            'Version' => '2012-10-17',
            'Statement' => [
                ['Effect' => 'Allow', 'Action' => ['s3:ListBucket', 's3:GetBucketLocation', 's3:ListBucketMultipartUploads'], 'Resource' => 'arn:aws:s3:::'.$bucket],
                ['Effect' => 'Allow', 'Action' => $objects, 'Resource' => 'arn:aws:s3:::'.$bucket.'/*'],
            ],
        ];
    }

    /** cipi-<bucket>-<random>: the bucket a user was made for. */
    public static function bucketOf(string $user): string
    {
        return (string) preg_replace('/^cipi-(.+)-[a-z0-9]{6}$/', '$1', $user);
    }

    /** One signed POST; the body of the answer. Tests stand in here. */
    protected function call(string $body): string
    {
        $headers = ['content-type' => 'application/x-www-form-urlencoded; charset=utf-8'];
        $signed = $this->signer->sign('POST', $this->endpoint, $this->region, 'iam', $headers, $body);

        try {
            $response = Http::withHeaders($signed)->connectTimeout(10)->timeout(30)
                ->withBody($body, $headers['content-type'])->post($this->endpoint);
        } catch (ConnectionException $e) {
            throw new ConnectorException(Str::limit(__('Not reachable: :reason', ['reason' => $e->getMessage()]), 250), previous: $e);
        }

        if ($response->failed()) {
            try {
                $error = Xml::parse($response->body());
            } catch (ConnectorException) {
                $error = [];
            }

            $error = data_get($error, 'Error', $error);

            throw new ConnectorException(Str::limit(trim(data_get($error, 'Code', 'IAM').': '.data_get($error, 'Message', __('refused (:status)', ['status' => $response->status()]))), 400), $response->status());
        }

        return $response->body();
    }
}
