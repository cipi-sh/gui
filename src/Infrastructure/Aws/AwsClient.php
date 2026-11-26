<?php

namespace CipiGui\Infrastructure\Aws;

use CipiGui\Infrastructure\ConnectorException;
use CipiGui\Infrastructure\S3\Iam;
use CipiGui\Infrastructure\S3\S3Client;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * The AWS APIs the panel drives, signed with an access key: the query APIs
 * (EC2, RDS, STS: form in, XML out), the JSON ones (Lightsail, Cost
 * Explorer) and the REST ones (S3, Route 53, CloudFront: XML both ways).
 */
final class AwsClient
{
    private readonly Signer $signer;

    public function __construct(
        string $accessKey,
        string $secretKey,
        public readonly string $defaultRegion,
        ?string $sessionToken = null,
    ) {
        $this->signer = new Signer($accessKey, $secretKey, $sessionToken);
    }

    /** The region of a request: the one picked on the page, else the account's. */
    public function region(mixed $region = null): string
    {
        return is_string($region) && preg_match('/^[a-z]{2}(-[a-z]+)+-\d$/', $region) ? $region : $this->defaultRegion;
    }

    /**
     * A query API call (EC2, RDS, STS): Action and Version and the parameters, flattened.
     *
     * @param  array<string, scalar|null>  $params
     * @return array<mixed>
     */
    public function query(string $service, string $version, string $action, array $params = [], ?string $region = null): array
    {
        $region = $this->region($region);
        $body = http_build_query(['Action' => $action, 'Version' => $version, ...array_filter($params, fn ($value) => $value !== null)], '', '&', PHP_QUERY_RFC3986);

        return Xml::parse($this->send('POST', "https://{$service}.{$region}.amazonaws.com/", $region, $service, $body, [
            'content-type' => 'application/x-www-form-urlencoded; charset=utf-8',
        ])->body());
    }

    /**
     * A JSON 1.1 API call (Lightsail, Cost Explorer).
     *
     * @param  array<string, mixed>  $payload
     * @return array<mixed>
     */
    public function json(string $service, string $target, array $payload = [], ?string $region = null): array
    {
        $region = $this->region($region);
        $response = $this->send('POST', "https://{$service}.{$region}.amazonaws.com/", $region, $service, (string) json_encode($payload === [] ? new \stdClass : $payload), [
            'content-type' => 'application/x-amz-json-1.1',
            'x-amz-target' => $target,
        ]);

        $json = $response->json();

        return is_array($json) ? $json : [];
    }

    /**
     * A REST call with JSON both ways (SES v2): the host of the service in the region.
     *
     * @param  array<string, mixed>|null  $payload
     * @return array<mixed>
     */
    public function restJson(string $method, string $service, string $path, ?array $payload = null, ?string $region = null, string $host = ''): array
    {
        $region = $this->region($region);
        $host = $host !== '' ? $host : "{$service}.{$region}.amazonaws.com";
        $body = $payload === null ? '' : (string) json_encode($payload === [] ? new \stdClass : $payload, JSON_UNESCAPED_SLASHES);
        $response = $this->send($method, 'https://'.$host.$path, $region, $service === 'email' ? 'ses' : $service, $body, $body === '' ? [] : ['content-type' => 'application/json']);
        $json = $response->json();

        return is_array($json) ? $json : [];
    }

    /** The S3 API of the account, bucket by bucket, region by region. */
    public function s3(): S3Client
    {
        return new S3Client($this->signer, 'https://s3.{region}.amazonaws.com', $this->defaultRegion);
    }

    /** IAM (global, signed for us-east-1): the keys the panel makes for apps. */
    public function iam(): Iam
    {
        return new Iam($this->signer);
    }

    /**
     * A REST call with XML (S3, Route 53, CloudFront).
     *
     * @return array<mixed>
     */
    public function rest(string $method, string $url, string $service, string $region = 'us-east-1', string $body = ''): array
    {
        return Xml::parse($this->send($method, $url, $region, $service, $body, $body === '' ? [] : ['content-type' => 'application/xml'])->body());
    }

    /**
     * @param  array<string, string>  $headers
     */
    private function send(string $method, string $url, string $region, string $service, string $body, array $headers): Response
    {
        $signed = $this->signer->sign($method, $url, $region, $service, $headers, $body);

        try {
            $request = Http::withHeaders($signed)->connectTimeout(10)->timeout(30);
            $response = $body === ''
                ? $request->send($method, $url)
                : $request->withBody($body, $headers['content-type'] ?? 'application/octet-stream')->send($method, $url);
        } catch (ConnectionException $e) {
            throw new ConnectorException(Str::limit(__('Not reachable: :reason', ['reason' => $e->getMessage()]), 250), previous: $e);
        }

        if ($response->failed()) {
            throw new ConnectorException($this->error($response));
        }

        return $response;
    }

    /** AWS's own code and message, from XML or JSON. */
    private function error(Response $response): string
    {
        $json = $response->json();

        if (is_array($json)) {
            $code = (string) Str::afterLast((string) ($json['__type'] ?? $json['code'] ?? ''), '#');
            $message = (string) ($json['message'] ?? $json['Message'] ?? '');
        } else {
            try {
                $xml = Xml::parse($response->body());
            } catch (ConnectorException) {
                $xml = [];
            }

            $error = data_get($xml, 'Errors.Error') ?? data_get($xml, 'Error') ?? $xml;
            $error = is_array($error) && array_is_list($error) ? ($error[0] ?? []) : $error;
            $code = (string) data_get($error, 'Code', '');
            $message = (string) data_get($error, 'Message', '');
        }

        return Str::limit(trim(($code !== '' ? $code.': ' : '').($message !== '' ? $message : __('AWS refused the request (:status).', ['status' => $response->status()]))), 400);
    }
}
