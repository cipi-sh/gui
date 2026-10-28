<?php

namespace CipiGui\Tests\Feature;

use CipiGui\Livewire\Servers;
use CipiGui\Tests\TestCase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;

class ServersTest extends TestCase
{
    /** @param  array<string, mixed>  $whitelist */
    private function fakeApi(array $whitelist): void
    {
        Http::fake(function ($request) use ($whitelist) {
            $path = parse_url($request->url(), PHP_URL_PATH);

            return match (true) {
                $path === '/api/status' => Http::response(['data' => ['system' => ['hostname' => 'fra1', 'cipi' => '5.8.2', 'ip' => '203.0.113.10']]]),
                $path === '/api/ip-whitelist' && $request->method() === 'GET' => Http::response(['data' => $whitelist]),
                $path === '/api/ip-whitelist' && $request->method() === 'POST' => Http::response(['data' => ['entries' => array_merge($whitelist['entries'], [$request['ip']]), 'allow_all' => false, 'client_ip' => $whitelist['client_ip']]]),
                default => Http::response(['data' => []]),
            };
        });
    }

    #[Test]
    public function the_token_card_shows_one_command_and_copies_it_without_the_prompt(): void
    {
        Http::fake();
        $this->server();

        $this->actingAs($this->admin())->get('/servers')
            ->assertOk()
            ->assertSeeHtml('terminal-line prompt">$ cipi api token create --name=gui --abilities=')
            ->assertDontSeeHtml('prompt">$ cipi api</div>')
            ->assertSeeHtml("raw: 'cipi api token create --name=gui")
            ->assertDontSeeHtml("raw: '$ cipi api");
    }

    #[Test]
    public function a_successful_test_adds_the_panel_address_to_a_restricted_whitelist(): void
    {
        $this->fakeApi(['entries' => ['10.0.0.0/8'], 'allow_all' => false, 'client_ip' => '198.51.100.7']);
        $server = $this->server();
        $this->actingAs($this->admin());

        Livewire::test(Servers::class)->call('testConnection', $server->id)
            ->assertSet("testResults.{$server->id}.ok", true)
            ->assertSet("testResults.{$server->id}.whitelisted", '198.51.100.7')
            ->assertSet("testResults.{$server->id}.message", 'Connected — 198.51.100.7 (this panel) added to the API IP whitelist');

        Http::assertSent(fn ($r) => $r->method() === 'POST' && str_ends_with((string) parse_url($r->url(), PHP_URL_PATH), '/api/ip-whitelist') && $r['ip'] === '198.51.100.7');
        $this->assertSame('198.51.100.7', Cache::get(Servers::PANEL_IP_CACHE_KEY));
    }

    #[Test]
    public function an_open_whitelist_is_left_open_and_the_panel_address_is_shown_next_to_the_token_command(): void
    {
        $this->fakeApi(['entries' => ['*'], 'allow_all' => true, 'client_ip' => '198.51.100.7']);
        $server = $this->server();
        $this->actingAs($this->admin());

        Livewire::test(Servers::class)->call('testConnection', $server->id)
            ->assertSet("testResults.{$server->id}.ok", true)
            ->assertSet("testResults.{$server->id}.message", 'Connected');

        Http::assertNotSent(fn ($r) => $r->method() === 'POST' && str_ends_with((string) parse_url($r->url(), PHP_URL_PATH), '/api/ip-whitelist'));

        $this->get('/servers')->assertOk()->assertSee('cipi api ip-whitelist add 198.51.100.7');
    }

    #[Test]
    public function a_panel_address_already_on_the_list_is_not_added_again(): void
    {
        $this->fakeApi(['entries' => ['198.51.100.7', '10.0.0.1'], 'allow_all' => false, 'client_ip' => '198.51.100.7']);
        $server = $this->server();
        $this->actingAs($this->admin());

        Livewire::test(Servers::class)->call('testConnection', $server->id)
            ->assertSet("testResults.{$server->id}.ok", true)
            ->assertSet("testResults.{$server->id}.message", 'Connected');

        Http::assertNotSent(fn ($r) => $r->method() === 'POST' && str_ends_with((string) parse_url($r->url(), PHP_URL_PATH), '/api/ip-whitelist'));
    }

    #[Test]
    public function a_whitelist_the_api_cannot_answer_does_not_fail_the_test(): void
    {
        Http::fake(function ($request) {
            $path = parse_url($request->url(), PHP_URL_PATH);

            return match (true) {
                $path === '/api/status' => Http::response(['data' => ['system' => ['hostname' => 'fra1', 'cipi' => '5.4.1']]]),
                $path === '/api/ip-whitelist' => Http::response(['error' => 'Forbidden'], 403),
                default => Http::response(['data' => []]),
            };
        });
        $server = $this->server();
        $this->actingAs($this->admin());

        Livewire::test(Servers::class)->call('testConnection', $server->id)
            ->assertSet("testResults.{$server->id}.ok", true)
            ->assertSet("testResults.{$server->id}.message", 'Connected');
    }
}
