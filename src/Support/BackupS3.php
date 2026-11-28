<?php

namespace CipiGui\Support;

/**
 * An S3 backup destination — a named bucket, or the default one ('s3'): the
 * providers the form offers, and its fields checked as the server checks
 * them. The secret key is only ever sent; nothing here keeps it.
 */
final class BackupS3
{
    public const NAME_RE = '/^[a-z][a-z0-9-]{1,31}$/D';

    public const BUCKET_RE = '/^[a-z0-9][a-z0-9._-]{1,62}$/D';

    public const REGION_RE = '/^[A-Za-z0-9-]{1,64}$/D';

    public const ENDPOINT_RE = '#^https?://[A-Za-z0-9.:/_-]{1,255}$#D';

    /** A key or a secret: printable, no space, no quote, no backslash (the server writes them to /root/.aws/credentials). */
    public const CRED_RE = '/^[\x21\x23-\x26\x28-\x5b\x5d-\x7e]{1,256}$/D';

    /**
     * The providers of the form: a label, the endpoint to fill in (with the
     * part to change in angle brackets), the region to suggest.
     *
     * @var array<string, array{label: string, endpoint: string, region: string, hint: string}>
     */
    public const PROVIDERS = [
        'aws' => ['label' => 'AWS S3', 'endpoint' => '', 'region' => 'eu-central-1', 'hint' => 'No endpoint: AWS is the default. The region of the bucket.'],
        'hetzner' => ['label' => 'Hetzner Object Storage', 'endpoint' => 'https://fsn1.your-objectstorage.com', 'region' => 'fsn1', 'hint' => 'fsn1, nbg1 or hel1 — the location of the bucket, in the endpoint and as the region.'],
        'r2' => ['label' => 'Cloudflare R2', 'endpoint' => 'https://<account-id>.r2.cloudflarestorage.com', 'region' => 'auto', 'hint' => 'Your account ID in the endpoint (R2 → Overview). Region: auto.'],
        'spaces' => ['label' => 'DigitalOcean Spaces', 'endpoint' => 'https://fra1.digitaloceanspaces.com', 'region' => 'fra1', 'hint' => 'The region of the Space, in the endpoint and as the region.'],
        'b2' => ['label' => 'Backblaze B2', 'endpoint' => 'https://s3.eu-central-003.backblazeb2.com', 'region' => 'eu-central-003', 'hint' => 'The endpoint shown on the bucket page; an application key as key and secret.'],
        'scaleway' => ['label' => 'Scaleway', 'endpoint' => 'https://s3.fr-par.scw.cloud', 'region' => 'fr-par', 'hint' => 'fr-par, nl-ams or pl-waw.'],
        'other' => ['label' => 'Other S3-compatible (MinIO, Wasabi…)', 'endpoint' => '', 'region' => 'eu-central-1', 'hint' => 'The endpoint URL of your S3-compatible service.'],
    ];

    /** The provider of an endpoint, for a destination being changed. */
    public static function providerOf(string $endpoint): string
    {
        $host = (string) parse_url($endpoint, PHP_URL_HOST);

        return match (true) {
            $endpoint === '' => 'aws',
            str_ends_with($host, 'your-objectstorage.com') => 'hetzner',
            str_ends_with($host, 'r2.cloudflarestorage.com') => 'r2',
            str_ends_with($host, 'digitaloceanspaces.com') => 'spaces',
            str_ends_with($host, 'backblazeb2.com') => 'b2',
            str_ends_with($host, 'scw.cloud') => 'scaleway',
            default => 'other',
        };
    }

    /**
     * What is wrong with the form, by property. A new bucket needs its keys;
     * a change keeps the ones it has when they are left empty.
     *
     * @param  list<string>  $taken  names already used (a new destination only)
     * @return array<string, string>
     */
    public static function errors(?string $name, string $bucket, string $key, string $secret, string $region, string $endpoint, bool $isNew, array $taken = []): array
    {
        $errors = [];
        if ($name !== null) {
            if (! preg_match(self::NAME_RE, $name) || in_array($name, ['local', 's3'], true)) {
                $errors['s3Name'] = 'Lowercase letters, digits and dashes, 2 to 32 — not local or s3.';
            } elseif (in_array($name, $taken, true)) {
                $errors['s3Name'] = "There is a destination called {$name} already.";
            }
        }
        if (! preg_match(self::BUCKET_RE, $bucket)) {
            $errors['s3Bucket'] = 'The bucket: lowercase letters, digits, . _ - (up to 63).';
        }
        if ($isNew && $key === '') {
            $errors['s3Key'] = 'The access key ID.';
        } elseif ($key !== '' && ! preg_match(self::CRED_RE, $key)) {
            $errors['s3Key'] = 'Printable characters, no space, no quote.';
        }
        if ($isNew && $secret === '') {
            $errors['s3Secret'] = 'The secret access key.';
        } elseif ($secret !== '' && ! preg_match(self::CRED_RE, $secret)) {
            $errors['s3Secret'] = 'Printable characters, no space, no quote.';
        }
        if ($region !== '' && ! preg_match(self::REGION_RE, $region)) {
            $errors['s3Region'] = 'Letters, digits and dashes — eu-central-1, fsn1, auto.';
        }
        if ($endpoint !== '' && (! preg_match(self::ENDPOINT_RE, $endpoint) || str_contains($endpoint, '<'))) {
            $errors['s3Endpoint'] = str_contains($endpoint, '<') ? 'Fill in the part in angle brackets.' : 'An https:// URL — empty for AWS.';
        }

        return $errors;
    }
}
