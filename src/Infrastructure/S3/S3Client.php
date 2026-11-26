<?php

namespace CipiGui\Infrastructure\S3;

use CipiGui\Infrastructure\Aws\Signer;
use CipiGui\Infrastructure\Aws\Xml;
use CipiGui\Infrastructure\ConnectorException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * The S3 API of any provider that speaks it (AWS S3, Wasabi, DigitalOcean
 * Spaces), signed with an access key: buckets, the objects of a folder,
 * presigned links, CORS. The endpoint is a template with {region} in it.
 */
class S3Client
{
    public function __construct(
        private readonly Signer $signer,
        public readonly string $endpoint,
        public readonly string $region,
        public readonly bool $pathStyle = false,
    ) {}

    public static function make(string $accessKey, string $secretKey, string $endpoint, string $region, bool $pathStyle = false, ?string $sessionToken = null): self
    {
        return new self(new Signer($accessKey, $secretKey, $sessionToken), $endpoint, $region, $pathStyle);
    }

    /** The region asked for, if it looks like one (us-east-1, nyc3, fra1), else the account's. */
    public function region(?string $region = null): string
    {
        return is_string($region) && preg_match('/^[a-z0-9]+(-[a-z0-9]+)*$/', $region) ? $region : $this->region;
    }

    /** https://host of the region, no trailing slash. */
    public function host(?string $region = null): string
    {
        return rtrim(str_replace('{region}', $this->region($region), $this->endpoint), '/');
    }

    /**
     * The address of a bucket, or of an object in it: virtual-hosted unless
     * the provider wants paths or the name has a dot (TLS would not match).
     *
     * @param  array<string, string>  $query
     */
    public function url(string $bucket, string $key = '', array $query = [], ?string $region = null): string
    {
        $host = $this->host($region);
        $path = $key === '' ? '' : implode('/', array_map('rawurlencode', explode('/', $key)));

        if ($this->pathStyle || str_contains($bucket, '.')) {
            $url = $host.'/'.rawurlencode($bucket).'/'.$path;
        } else {
            $url = (string) preg_replace('#^(https?://)#', '$1'.$bucket.'.', $host).'/'.$path;
        }

        if ($query !== []) {
            $pairs = [];

            foreach ($query as $name => $value) {
                $pairs[] = rawurlencode((string) $name).($value === '' ? '' : '='.rawurlencode($value));
            }

            $url .= '?'.implode('&', $pairs);
        }

        return $url;
    }

    /**
     * Every bucket the key can see.
     *
     * @return list<array<string, mixed>>
     */
    public function buckets(?string $region = null): array
    {
        $answer = $this->xml('GET', $this->host($region).'/', '', [], $region);

        return array_values(array_filter(Xml::list(data_get($answer, 'Buckets.Bucket')), 'is_array'));
    }

    /** A new bucket; $location is the LocationConstraint for providers that want one. */
    public function createBucket(string $bucket, ?string $region = null, ?string $location = null): void
    {
        $body = $location === null || $location === '' || $location === 'us-east-1'
            ? ''
            : '<CreateBucketConfiguration xmlns="http://s3.amazonaws.com/doc/2006-03-01/"><LocationConstraint>'.Xml::escape($location).'</LocationConstraint></CreateBucketConfiguration>';

        $this->call('PUT', $this->url($bucket, region: $region), $body, $body === '' ? [] : ['content-type' => 'application/xml'], $region);
    }

    public function deleteBucket(string $bucket, ?string $region = null): void
    {
        $this->call('DELETE', $this->url($bucket, region: $region), '', [], $region);
    }

    /** Where a bucket lives, asked in us-east-1 as S3 wants; empty (us-east-1 on AWS) becomes $fallback. */
    public function location(string $bucket, string $fallback = 'us-east-1'): string
    {
        $answer = $this->xml('GET', $this->url($bucket, query: ['location' => ''], region: 'us-east-1'), '', [], 'us-east-1');
        $region = is_string($answer[0] ?? null) ? trim($answer[0]) : '';

        return match ($region) {
            '' => $fallback,
            'EU' => 'eu-west-1',
            default => $region,
        };
    }

    /**
     * One folder of a bucket: its subfolders and its objects.
     *
     * @return array{folders: list<string>, objects: list<array<string, mixed>>, truncated: bool}
     */
    public function objects(string $bucket, string $prefix = '', ?string $region = null, int $max = 1000): array
    {
        $answer = $this->xml('GET', $this->url($bucket, query: [
            'list-type' => '2',
            'delimiter' => '/',
            'max-keys' => (string) $max,
            'prefix' => $prefix,
        ], region: $region), '', [], $region);

        $folders = [];

        foreach (Xml::list($answer['CommonPrefixes'] ?? null) as $common) {
            $folder = is_array($common) ? (string) ($common['Prefix'] ?? '') : '';

            if ($folder !== '') {
                $folders[] = $folder;
            }
        }

        $objects = array_values(array_filter(
            Xml::list($answer['Contents'] ?? null),
            fn ($object) => is_array($object) && (string) ($object['Key'] ?? '') !== $prefix,
        ));

        return ['folders' => $folders, 'objects' => $objects, 'truncated' => ($answer['IsTruncated'] ?? '') === 'true'];
    }

    public function deleteObject(string $bucket, string $key, ?string $region = null): void
    {
        $this->call('DELETE', $this->url($bucket, $key, region: $region), '', [], $region);
    }

    /** A link anyone can download the object from, for $seconds (a week at most). */
    public function presign(string $bucket, string $key, int $seconds, ?string $region = null): string
    {
        return $this->signer->presign('GET', $this->url($bucket, $key, region: $region), $this->region($region), $seconds);
    }

    /**
     * The CORS rules of a bucket; none is an empty list.
     *
     * @return list<array<string, mixed>>
     */
    public function cors(string $bucket, ?string $region = null): array
    {
        try {
            $answer = $this->xml('GET', $this->url($bucket, query: ['cors' => ''], region: $region), '', [], $region);
        } catch (ConnectorException $e) {
            if (str_contains($e->getMessage(), 'NoSuchCORSConfiguration')) {
                return [];
            }

            throw $e;
        }

        return array_values(array_filter(Xml::list($answer['CORSRule'] ?? null), 'is_array'));
    }

    /**
     * One CORS rule for the whole bucket: browsers on $origins may use $methods.
     *
     * @param  list<string>  $origins
     * @param  list<string>  $methods
     */
    public function putCors(string $bucket, array $origins, array $methods, int $maxAge = 3600, ?string $region = null): void
    {
        $rule = '<CORSRule>'
            .implode('', array_map(fn ($o) => '<AllowedOrigin>'.Xml::escape($o).'</AllowedOrigin>', $origins))
            .implode('', array_map(fn ($m) => '<AllowedMethod>'.Xml::escape(strtoupper($m)).'</AllowedMethod>', $methods))
            .'<AllowedHeader>*</AllowedHeader><ExposeHeader>ETag</ExposeHeader><MaxAgeSeconds>'.$maxAge.'</MaxAgeSeconds></CORSRule>';
        $body = '<CORSConfiguration xmlns="http://s3.amazonaws.com/doc/2006-03-01/">'.$rule.'</CORSConfiguration>';

        $this->call('PUT', $this->url($bucket, query: ['cors' => ''], region: $region), $body, [
            'content-type' => 'application/xml',
            'content-md5' => base64_encode(md5($body, true)),
        ], $region);
    }

    public function deleteCors(string $bucket, ?string $region = null): void
    {
        $this->call('DELETE', $this->url($bucket, query: ['cors' => ''], region: $region), '', [], $region);
    }

    /**
     * @param  array<string, string>  $headers
     * @return array<mixed>
     */
    private function xml(string $method, string $url, string $body = '', array $headers = [], ?string $region = null): array
    {
        return Xml::parse($this->call($method, $url, $body, $headers, $region));
    }

    /**
     * One signed request; the body of the answer. Tests stand in here.
     *
     * @param  array<string, string>  $headers
     */
    protected function call(string $method, string $url, string $body = '', array $headers = [], ?string $region = null): string
    {
        $signed = $this->signer->sign($method, $url, $this->region($region), 's3', $headers, $body);

        try {
            $request = Http::withHeaders($signed)->connectTimeout(10)->timeout(30);
            $response = $body === ''
                ? $request->send($method, $url)
                : $request->withBody($body, $headers['content-type'] ?? 'application/octet-stream')->send($method, $url);
        } catch (ConnectionException $e) {
            throw new ConnectorException(Str::limit(__('Not reachable: :reason', ['reason' => $e->getMessage()]), 250), previous: $e);
        }

        if ($response->failed()) {
            try {
                $error = Xml::parse($response->body());
            } catch (ConnectorException) {
                $error = [];
            }

            $code = (string) data_get($error, 'Code', '');
            $message = (string) data_get($error, 'Message', '');

            throw new ConnectorException(Str::limit(trim(($code !== '' ? $code.': ' : '').($message !== '' ? $message : __('The storage refused the request (:status).', ['status' => $response->status()]))), 400), $response->status());
        }

        return $response->body();
    }
}
