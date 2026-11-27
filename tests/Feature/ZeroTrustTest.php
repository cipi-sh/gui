<?php

namespace CipiGui\Tests\Feature;

use CipiGui\Livewire\ServerManage;
use CipiGui\Livewire\Servers;
use CipiGui\Models\CipiServer;
use CipiGui\Tests\TestCase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;

/**
 * Zero Trust in the server page (4.8.0): with Cipi 5.16 the tab reads the
 * whole of `cipi zt status --json` — tunnel, connectors, hostnames with their
 * Access paths and people, TCP services, SSH certificates — and a server's
 * API behind Cloudflare Access is reached with a service token.
 *
 * 4.10.0: the mesh network the server is on (Tailscale, NetBird) has a card
 * of its own under Cloudflare's — where a server says something of a mesh.
 * What the card does (join, leave, locks) is in MeshTest.
 */
class ZeroTrustTest extends TestCase
{
    private CipiServer $server;

    private ?array $details = null;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake(function (Request $request) {
            $path = parse_url($request->url(), PHP_URL_PATH);
            if ($path === '/api/zt') {
                return Http::response(['data' => [
                    'enabled' => true, 'cloudflared' => 'running', 'tunnel' => 'cipi-mozart', 'real_ip' => 'CF-Connecting-IP',
                    'lock_http' => 'true (dark)', 'lock_ssh' => 'false', 'ssh_hostname' => 'ssh.example.com', 'raw' => $this->details ? '' : "Cloudflare Zero Trust\n  cloudflared  running",
                    'details' => $this->details,
                ]]);
            }

            return Http::response(['data' => ['system' => ['cipi' => '5.16.0']]]);
        });

        $this->server = $this->server();
        $this->actingAs($this->admin());
        session(['cipi_gui_server_id' => $this->server->id]);
    }

    private function tab()
    {
        return Livewire::withQueryParams(['tab' => 'zt'])->test(ServerManage::class, ['serverId' => $this->server->id])->call('init');
    }

    #[Test]
    public function an_older_server_shows_the_summary_and_its_text(): void
    {
        $this->tab()->assertSee('Cloudflare Zero Trust')->assertSee('cipi-mozart')->assertDontSee('data-zt-details', false);
    }

    #[Test]
    public function the_details_show_the_tunnel_hostnames_access_and_services(): void
    {
        $this->details = [
            'enabled' => true, 'token' => true,
            'tunnel' => ['id' => 't1', 'name' => 'cipi-mozart', 'running' => true, 'status' => 'healthy', 'replica' => false,
                'connectors' => [['id' => 'c1', 'origin_ips' => ['203.0.113.10'], 'conns' => 4, 'this_server' => true], ['id' => 'c2', 'origin_ips' => ['198.51.100.9'], 'conns' => 4, 'this_server' => false]]],
            'real_ip' => true, 'lock' => ['http' => true, 'http_mode' => 'dark', 'ssh' => false],
            'hostnames' => [['app' => 'shop', 'domains' => ['shop.example.com'], 'access' => ['enabled' => true, 'paths' => ['/admin', '/horizon'], 'allow' => ['@acme.io']], 'service_tokens' => ['ci'], 'purge_on_deploy' => true, 'preview' => null]],
            'gui' => null,
            'api' => ['domain' => 'api.example.com', 'on_tunnel' => true, 'access' => ['enabled' => true, 'paths' => [], 'allow' => ['none']], 'service_tokens' => ['panel']],
            'ssh' => ['hostname' => 'ssh.example.com', 'access' => ['enabled' => true, 'paths' => [], 'allow' => []], 'certificates' => ['enabled' => true, 'users' => ['cipi' => ['andrea']]]],
            'tcp' => [['hostname' => 'db.example.com', 'service' => 'mysql', 'port' => 3306, 'allow' => ['andrea@acme.io']]],
            'service_tokens' => [], 'previews' => [], 'missing' => ['blog'],
        ];

        $this->tab()
            ->assertSee('data-zt-details', false)
            ->assertSee('Tunnel healthy')
            ->assertSee('198.51.100.9')->assertSee('this server')
            ->assertSee('Closed — every site comes through the tunnel')
            ->assertSee('on /admin, /horizon · @acme.io')
            ->assertSee('purges Cloudflare')
            ->assertSee('service tokens only')
            ->assertSee('db.example.com')->assertSee('127.0.0.1:3306')
            ->assertSee('short-lived certificates')
            ->assertSee('Not on the tunnel: blog');
    }

    /** `cipi zt status --json` of a server with no tunnel: Cloudflare off, and whatever mesh is given. */
    private function withoutTunnel(?array $mesh): array
    {
        $details = ['enabled' => false, 'token' => false, 'tunnel' => null, 'real_ip' => false, 'lock' => ['http' => false, 'http_mode' => null, 'ssh' => false],
            'hostnames' => [], 'gui' => null, 'api' => null, 'ssh' => null, 'tcp' => [], 'service_tokens' => [], 'previews' => [], 'missing' => []];

        return $mesh === null ? $details : $details + ['mesh' => $mesh];
    }

    private function tailscale(array $over = []): array
    {
        return $over + ['enabled' => true, 'provider' => 'tailscale', 'label' => 'Tailscale', 'installed' => true, 'running' => true, 'connected' => true, 'state' => 'Running',
            'interface' => 'tailscale0', 'ip' => '100.101.102.103', 'fqdn' => 'web1.tail1234.ts.net', 'network' => 'example.com', 'control_url' => null,
            'tags' => ['tag:cipi'], 'key_expiry' => null, 'key_expires_in_days' => null, 'peers' => ['total' => 6, 'online' => 4],
            'ssh' => ['provider_ssh' => false, 'ports' => [22], 'command' => 'ssh cipi@100.101.102.103'],
            'lock' => ['ssh' => true, 'http' => false], 'direct' => ['port' => 41641, 'open' => false], 'warnings' => []];
    }

    #[Test]
    public function the_mesh_network_has_its_own_card_with_or_without_a_tunnel(): void
    {
        $this->details = $this->withoutTunnel($this->tailscale());

        $this->tab()
            ->assertSee('data-zt-mesh', false)
            ->assertSee('Tailscale connected')
            ->assertSee('100.101.102.103')->assertSee('web1.tail1234.ts.net')->assertSee('example.com')
            ->assertSee('4 of 6 peers online')
            ->assertSee('Does not expire')
            ->assertSee('ssh cipi@100.101.102.103')->assertSee('OpenSSH, with the server')
            ->assertSee('Closed to the world — from the network only')
            // Cloudflare's card says its own: off.
            ->assertSee('cipi zt token set')
            ->assertDontSee('data-zt-hostnames', false);
    }

    #[Test]
    public function a_node_key_that_expires_and_the_servers_warnings_are_said(): void
    {
        $this->details = $this->withoutTunnel($this->tailscale([
            'key_expiry' => '2027-01-06T10:00:00Z', 'key_expires_in_days' => 40,
            'warnings' => ['The node key expires in 40 days: disable key expiry for this machine, or join with a tagged key'],
            'lock' => ['ssh' => false, 'http' => true],
        ]));

        $this->tab()
            ->assertSee('Expires on 2027-01-06 — in 40 days')
            ->assertSee('disable key expiry for this machine')
            ->assertSee('the sites, the panel and the API answer on the network only')
            ->assertDontSee('Closed to the world — from the network only');
    }

    #[Test]
    public function a_mesh_that_is_down_is_red_and_netbird_has_no_node_key_line(): void
    {
        $this->details = $this->withoutTunnel(['enabled' => true, 'provider' => 'netbird', 'label' => 'NetBird', 'connected' => false, 'state' => 'NeedsLogin',
            'ip' => null, 'fqdn' => null, 'network' => null, 'control_url' => 'https://nb.example.com:443', 'peers' => ['total' => 0, 'online' => 0],
            'ssh' => ['provider_ssh' => false, 'ports' => [22], 'command' => null], 'lock' => ['ssh' => false, 'http' => false],
            'warnings' => ['NetBird is not connected (NeedsLogin)']]);

        $this->tab()
            ->assertSee('NetBird disconnected')->assertSee('badge-red', false)
            ->assertSee('NeedsLogin')->assertSee('https://nb.example.com:443')
            ->assertDontSee('Node key');
    }

    #[Test]
    public function a_server_with_no_mesh_offers_to_join_one_and_an_older_server_says_to_update(): void
    {
        $this->details = $this->withoutTunnel(['enabled' => false, 'provider' => null, 'active' => null]);
        $this->tab()->assertSee('data-zt-mesh-join', false)->assertSee('Join the network')->assertSee('Auth key')->assertSee('In three steps')
            ->assertDontSee('data-zt-mesh-update', false);

        $this->details = $this->withoutTunnel(null);
        $this->tab()->assertSee('data-zt-details', false)->assertSee('data-zt-mesh-update', false)->assertSee('cipi self-update')
            ->assertDontSee('data-zt-mesh-join', false)->assertDontSee('data-zt-mesh-actions', false);
    }

    #[Test]
    public function the_tab_changes_nothing_on_the_server(): void
    {
        $this->details = $this->withoutTunnel($this->tailscale());
        $this->tab();

        Http::assertNotSent(fn (Request $r) => $r->method() !== 'GET');
    }

    #[Test]
    public function a_connection_sends_its_cloudflare_access_service_token(): void
    {
        Livewire::test(Servers::class)
            ->call('openEdit', $this->server->id)
            ->set('accessClientId', 'abc123.access')
            ->set('accessClientSecret', 'sekret-value')
            ->call('save')
            ->assertHasNoErrors();

        $this->server->refresh();
        $this->assertSame('abc123.access', $this->server->cf_access_client_id);
        $this->assertSame('sekret-value', $this->server->cf_access_client_secret);
        $this->assertStringNotContainsString('sekret-value', (string) DB::table('cipi_servers')->value('cf_access_client_secret'));
        Http::assertSent(fn (Request $r) => $r->hasHeader('CF-Access-Client-Id', 'abc123.access') && $r->hasHeader('CF-Access-Client-Secret', 'sekret-value'));
    }

    #[Test]
    public function the_secret_is_kept_when_left_empty_and_both_go_on_remove(): void
    {
        $this->server->forceFill(['cf_access_client_id' => 'abc123.access', 'cf_access_client_secret' => 'sekret-value'])->save();

        Livewire::test(Servers::class)
            ->call('openEdit', $this->server->id)
            ->assertSet('accessClientId', 'abc123.access')
            ->assertSet('accessClientSecret', '')
            ->call('save')
            ->assertHasNoErrors();
        $this->assertSame('sekret-value', $this->server->refresh()->cf_access_client_secret);

        Livewire::test(Servers::class)
            ->call('openEdit', $this->server->id)
            ->set('accessClear', true)
            ->call('save');
        $this->server->refresh();
        $this->assertNull($this->server->cf_access_client_id);
        $this->assertNull($this->server->cf_access_client_secret);
    }

    #[Test]
    public function an_id_without_a_secret_is_refused(): void
    {
        Livewire::test(Servers::class)
            ->call('openEdit', $this->server->id)
            ->set('accessClientId', 'abc123.access')
            ->call('save')
            ->assertHasErrors('accessClientSecret');
    }
}
