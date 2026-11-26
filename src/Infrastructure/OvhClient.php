<?php

namespace CipiGui\Infrastructure;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * The OVHcloud API: every request signed with the application secret and
 * the consumer key — "$1$" + SHA-1 of secret+consumer+method+URL+body+time —
 * with OVH's clock, whose difference from ours is asked once an hour.
 */
final class OvhClient extends ApiClient
{
    public function __construct(
        string $baseUrl,
        private readonly string $applicationKey,
        private readonly string $applicationSecret,
        private readonly string $consumerKey,
        int $timeout = 30,
    ) {
        parent::__construct($baseUrl, [], $timeout);
    }

    /** The signature of one request (public for the tests). */
    public static function signature(string $secret, string $consumer, string $method, string $url, string $body, string $timestamp): string
    {
        return '$1$'.sha1($secret.'+'.$consumer.'+'.strtoupper($method).'+'.$url.'+'.$body.'+'.$timestamp);
    }

    protected function response(string $method, string $path, array $options): Response
    {
        $url = $this->url($path);

        if (isset($options['query']) && is_array($options['query']) && $options['query'] !== []) {
            $url .= (str_contains($url, '?') ? '&' : '?').http_build_query($options['query']);
        }

        $body = isset($options['json']) ? (string) json_encode($options['json'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : '';
        $timestamp = (string) (time() + $this->delta());

        $request = Http::withHeaders([
            'X-Ovh-Application' => $this->applicationKey,
            'X-Ovh-Consumer' => $this->consumerKey,
            'X-Ovh-Timestamp' => $timestamp,
            'X-Ovh-Signature' => self::signature($this->applicationSecret, $this->consumerKey, $method, $url, $body, $timestamp),
        ])->acceptJson()->connectTimeout(10)->timeout(30);

        if ($body !== '') {
            $request = $request->withBody($body, 'application/json');
        }

        try {
            $response = $request->send($method, $url);
        } catch (ConnectionException $e) {
            throw new ConnectorException(__('Not reachable: :reason', ['reason' => $e->getMessage()]), previous: $e);
        }

        if ($response->failed()) {
            throw new ConnectorException($this->error($response), $response->status());
        }

        return $response;
    }

    /** OVH's time minus ours, in seconds: a signature older than a few minutes is refused. */
    private function delta(): int
    {
        return (int) Cache::remember('cipi-gui.infra-ovh-delta:'.md5($this->baseUrl), 3600, function () {
            try {
                $server = (int) trim(Http::connectTimeout(5)->timeout(10)->get($this->url('/auth/time'))->body());
            } catch (\Throwable) {
                return 0;
            }

            return $server > 0 ? $server - time() : 0;
        });
    }
}
