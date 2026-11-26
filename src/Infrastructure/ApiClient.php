<?php

namespace CipiGui\Infrastructure;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * A JSON API with a base URL and its headers (usually a bearer token). Every
 * answer comes back as an array; a refusal, a timeout or an error status
 * becomes a ConnectorException with the provider's own message.
 */
class ApiClient
{
    /**
     * @param  array<string, string>  $headers
     */
    public function __construct(
        public readonly string $baseUrl,
        private readonly array $headers = [],
        private readonly int $timeout = 30,
    ) {}

    /**
     * @param  array<string, mixed>  $query
     * @return array<mixed>
     */
    public function get(string $path, array $query = []): array
    {
        // A query option replaces the one in the address (a "next page" link): keep both.
        if ($query !== [] && str_contains($path, '?')) {
            [$path, $inline] = explode('?', $path, 2);
            parse_str($inline, $parsed);
            $query = [...$parsed, ...$query];
        }

        return $this->send('GET', $path, $query === [] ? [] : ['query' => $query]);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<mixed>
     */
    public function post(string $path, array $data = []): array
    {
        return $this->send('POST', $path, $data === [] ? [] : ['json' => $data]);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<mixed>
     */
    public function put(string $path, array $data = []): array
    {
        return $this->send('PUT', $path, ['json' => $data]);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<mixed>
     */
    public function patch(string $path, array $data = []): array
    {
        return $this->send('PATCH', $path, ['json' => $data]);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<mixed>
     */
    public function delete(string $path, array $data = []): array
    {
        return $this->send('DELETE', $path, $data === [] ? [] : ['json' => $data]);
    }

    /** The raw body of a GET, for exports and logs that are not JSON. */
    public function text(string $path): string
    {
        return $this->response('GET', $path, [])->body();
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<mixed>
     */
    public function send(string $method, string $path, array $options = []): array
    {
        $json = $this->response($method, $path, $options)->json();

        return is_array($json) ? $json : [];
    }

    /**
     * @param  array<string, mixed>  $options
     */
    protected function response(string $method, string $path, array $options): Response
    {
        try {
            $response = $this->request()->send($method, $this->url($path), $options);
        } catch (ConnectionException $e) {
            throw new ConnectorException(Str::limit(__('Not reachable: :reason', ['reason' => $e->getMessage()]), 250), previous: $e);
        }

        // the HTTP status rides along as the code: a missing ability (403) is told from a missing endpoint (404)
        if ($response->failed()) {
            throw new ConnectorException($this->error($response), $response->status());
        }

        return $response;
    }

    protected function request(): PendingRequest
    {
        return Http::withHeaders($this->headers)
            ->acceptJson()
            ->connectTimeout(10)
            ->timeout($this->timeout);
    }

    protected function url(string $path): string
    {
        return str_starts_with($path, 'http://') || str_starts_with($path, 'https://')
            ? $path
            : rtrim($this->baseUrl, '/').'/'.ltrim($path, '/');
    }

    /** The provider's own words for a refusal, whatever shape its errors take. */
    protected function error(Response $response): string
    {
        $json = $response->json();
        $message = null;

        if (is_array($json)) {
            $message = data_get($json, 'error.message')
                ?? data_get($json, 'errors.0.message')
                ?? data_get($json, 'errors.0.detail')
                ?? (is_string($json['errors'][0] ?? null) ? $json['errors'][0] : null)
                ?? data_get($json, 'message')
                ?? data_get($json, 'Message')
                ?? data_get($json, 'reason_phrase')
                ?? data_get($json, 'detail')
                ?? data_get($json, 'error_description')
                ?? (is_string($json['error'] ?? null) ? $json['error'] : null);

            if (is_array($json['errors'] ?? null) && ! array_is_list($json['errors'])) {
                $first = collect($json['errors'])->flatten()->first();
                $message = is_string($first) ? $first : $message;
            }
        }

        $message = is_string($message) && $message !== '' ? $message : Str::limit(trim(strip_tags($response->body())), 200);

        return match ($response->status()) {
            401 => __('Refused (401): check the credentials. :message', ['message' => $message]),
            403 => __('Not allowed (403): the token lacks a permission. :message', ['message' => $message]),
            404 => __('Not found (404). :message', ['message' => $message]),
            default => Str::limit(__(':status: :message', ['status' => $response->status(), 'message' => $message ?: __('no details')]), 400),
        };
    }

    /**
     * Every page of a list the provider pages through.
     *
     * @param  callable(array<mixed>): list<mixed>  $items  the items of one answer
     * @param  callable(array<mixed>, int): (array<string, mixed>|string|null)  $next  the query, or URL, of the next page; null at the end
     * @param  array<string, mixed>  $query
     * @return list<mixed>
     */
    public function all(string $path, array $query, callable $items, callable $next, int $maxPages = 20): array
    {
        $rows = [];
        $page = 1;

        do {
            $answer = $this->get($path, $query);
            $rows = [...$rows, ...$items($answer)];
            $following = $next($answer, $page);

            if (is_string($following)) {
                $path = $following;
                $query = [];
            } elseif (is_array($following)) {
                $query = $following;
            }
        } while ($following !== null && ++$page <= $maxPages);

        return $rows;
    }
}
