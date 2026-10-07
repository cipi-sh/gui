<?php

namespace CipiGui\Tests\Feature;

use CipiGui\Services\CipiApiClient;
use CipiGui\Services\CipiApiException;
use CipiGui\Tests\TestCase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;

class CipiApiClientTest extends TestCase
{
    #[Test]
    public function it_sends_the_bearer_token_to_the_api_prefix(): void
    {
        Http::fake(['vps.example.com/api/apps' => Http::response(['data' => [['app' => 'shop']]])]);

        $apps = CipiApiClient::for($this->server())->listApps();

        $this->assertSame([['app' => 'shop']], $apps);
        Http::assertSent(fn (Request $request) => $request->url() === 'https://vps.example.com/api/apps'
            && $request->hasHeader('Authorization', 'Bearer demo-token-1234567890')
            && str_starts_with($request->header('User-Agent')[0] ?? '', 'cipi-gui/'));
    }

    #[Test]
    public function validation_errors_do_not_flag_the_server_as_broken(): void
    {
        $server = $this->server();
        Http::fake(['*' => Http::response(['error' => "App 'nope' not found"], 404)]);

        try {
            CipiApiClient::for($server)->showApp('nope');
            $this->fail('Expected an exception');
        } catch (CipiApiException $e) {
            $this->assertSame(404, $e->getStatusCode());
            $this->assertSame("App 'nope' not found", $e->getMessage());
        }

        $this->assertNull($server->fresh()->last_error);
    }

    #[Test]
    public function auth_failures_are_recorded_on_the_server(): void
    {
        $server = $this->server();
        Http::fake(['*' => Http::response(['message' => 'Unauthenticated.'], 401)]);

        $this->expectException(CipiApiException::class);

        try {
            CipiApiClient::for($server)->getStatus();
        } finally {
            $this->assertSame('Unauthenticated.', $server->fresh()->last_error);
        }
    }

    #[Test]
    public function path_redirect_removal_sends_a_json_body_with_delete(): void
    {
        Http::fake(['*' => Http::response(['data' => ['app' => 'shop', 'redirect' => null, 'redirects' => []]])]);

        CipiApiClient::for($this->server())->removePathRedirect('shop', '/blog/');

        Http::assertSent(fn (Request $request) => $request->method() === 'DELETE'
            && $request->url() === 'https://vps.example.com/api/apps/shop/redirects'
            && $request['from'] === '/blog/');
    }

    #[Test]
    public function status_for_many_queries_servers_concurrently_and_isolates_failures(): void
    {
        $ok = $this->server();
        $down = $this->server(['name' => 'staging', 'url' => 'https://staging.example.com']);

        Http::fake([
            'vps.example.com/api/status' => Http::response(['data' => ['apps' => 3, 'system' => ['cipi' => '5.5.0']]]),
            'staging.example.com/api/status' => Http::response(['error' => 'IP not allowed', 'ip' => '203.0.113.10'], 403),
        ]);

        $results = CipiApiClient::statusForMany([$ok, $down]);

        $this->assertSame(3, $results[$ok->id]['status']['apps']);
        $this->assertNull($results[$ok->id]['error']);
        $this->assertNull($results[$down->id]['status']);
        $this->assertSame('IP not allowed', $results[$down->id]['error']);
        $this->assertSame('IP not allowed', $down->fresh()->last_error);
    }
}
