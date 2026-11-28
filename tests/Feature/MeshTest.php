<?php

namespace CipiGui\Tests\Feature;

use CipiGui\Livewire\Firewall;
use CipiGui\Livewire\ServerManage;
use CipiGui\Models\CipiServer;
use CipiGui\Services\Access;
use CipiGui\Tests\TestCase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;

/**
 * The mesh network of Zero Trust — Tailscale or NetBird — managed from the
 * server's Zero Trust tab: join, leave, close and reopen SSH and 80/443,
 * Tailscale SSH. The server decides; the page shows what it would refuse
 * before the click, and what it answered after it.
 */
class MeshTest extends TestCase
{
    private CipiServer $server;

    /** `details.mesh` of `GET /api/zt`, as the server has it now. */
    private array $mesh = ['enabled' => false, 'provider' => null, 'active' => null];

    /** @var array<string, array{0: array<string, mixed>, 1: int}> "METHOD path" => [body, status]; a change without one succeeds */
    private array $answers = [];

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake(function (Request $request) {
            $path = parse_url($request->url(), PHP_URL_PATH);
            $key = $request->method().' '.$path;
            if (isset($this->answers[$key])) {
                return Http::response(...$this->answers[$key]);
            }
            if ($path === '/api/zt') {
                return Http::response(['data' => $this->zt()]);
            }
            if (str_starts_with((string) $path, '/api/zt/mesh')) {
                return Http::response(['data' => $this->zt(), 'message' => "Done.\nThis server runs Cipi 9.9.9"]);
            }

            return Http::response(['data' => ['system' => ['cipi' => '5.18.0']]]);
        });

        $this->server = $this->server();
        $this->actingAs($this->admin());
        session(['cipi_gui_server_id' => $this->server->id]);
    }

    /** `GET /api/zt` of a server with no tunnel and the mesh of the moment. */
    private function zt(): array
    {
        $on = ! empty($this->mesh['enabled']);

        return [
            'enabled' => false, 'cloudflared' => null, 'tunnel' => null, 'real_ip' => null, 'lock_http' => null, 'lock_ssh' => null, 'ssh_hostname' => null,
            'mesh' => $on ? $this->mesh['provider'] : null, 'mesh_state' => $on ? 'connected' : null, 'mesh_ip' => $on ? ($this->mesh['ip'] ?? null) : null, 'raw' => '',
            'details' => ['enabled' => false, 'token' => false, 'tunnel' => null, 'real_ip' => false, 'lock' => ['http' => false, 'http_mode' => null, 'ssh' => false],
                'hostnames' => [], 'gui' => null, 'api' => null, 'ssh' => null, 'tcp' => [], 'service_tokens' => [], 'previews' => [], 'missing' => [], 'mesh' => $this->mesh],
        ];
    }

    private function tailscale(array $over = [], array $blocked = [], string $via = 'mesh'): array
    {
        return $over + ['enabled' => true, 'provider' => 'tailscale', 'label' => 'Tailscale', 'installed' => true, 'running' => true, 'connected' => true, 'state' => 'Running',
            'interface' => 'tailscale0', 'ip' => '100.101.102.103', 'fqdn' => 'web1.tail1234.ts.net', 'network' => 'example.com', 'control_url' => null,
            'tags' => ['tag:cipi'], 'key_expiry' => null, 'key_expires_in_days' => null, 'peers' => ['total' => 6, 'online' => 4],
            'ssh' => ['provider_ssh' => false, 'ports' => [22], 'command' => 'ssh cipi@100.101.102.103', 'seen' => ['at' => '2026-11-27T12:00:01', 'from' => '100.99.1.2']],
            'lock' => ['ssh' => false, 'http' => false], 'direct' => ['port' => 41641, 'open' => false], 'firewall_active' => true, 'http01_apps' => [], 'warnings' => [],
            'panel' => ['via' => $via, 'from' => '100.99.1.9', 'client' => null, 'blocked' => $blocked + ['lock_ssh' => null, 'lock_http' => null, 'disable' => null]]];
    }

    private function tab()
    {
        return Livewire::withQueryParams(['tab' => 'zt'])->test(ServerManage::class, ['serverId' => $this->server->id])->call('init');
    }

    private function sent(string $method, string $path): ?Request
    {
        $sent = Http::recorded(fn (Request $r) => $r->method() === $method && parse_url($r->url(), PHP_URL_PATH) === $path);

        return $sent->isEmpty() ? null : $sent->last()[0];
    }

    private function changes(): int
    {
        return Http::recorded(fn (Request $r) => $r->method() !== 'GET')->count();
    }

    #[Test]
    public function a_server_on_no_mesh_shows_the_join_form_and_reads_for_this_browser(): void
    {
        $this->tab()
            ->assertSeeHtml('data-zt-mesh-join')
            ->assertSee('Auth key')->assertSee('Join the network')->assertSee('In three steps')
            ->assertDontSeeHtml('data-zt-mesh-leave');

        $read = $this->sent('GET', '/api/zt');
        $this->assertNotNull($read);
        $this->assertStringContainsString('client=127.0.0.1', $read->url());
        $this->assertSame(0, $this->changes());
    }

    #[Test]
    public function netbird_has_its_own_key_name_and_none_of_tailscales_options(): void
    {
        $this->tab()
            ->assertSeeHtml('id="mesh-tags"')
            ->set('meshProvider', 'netbird')
            ->assertSee('Setup key')->assertSee('Setup Keys')
            ->assertDontSeeHtml('id="mesh-tags"')
            ->assertDontSeeHtml('wire:model="meshSsh"');
    }

    #[Test]
    public function joining_sends_what_was_filled_in_once_and_forgets_the_key(): void
    {
        $tab = $this->tab()
            ->set('meshProvider', 'tailscale')
            ->set('meshKey', 'tskey-auth-kTEST-0123456789')
            ->set('meshHostname', 'web1')
            ->set('meshTags', 'cipi, tag:prod')
            ->set('meshDirect', true);

        $this->mesh = $this->tailscale([], [], 'public');
        $tab->call('joinMesh')
            ->assertHasNoErrors()
            ->assertSet('meshKey', '')
            ->assertSet('meshHostname', '')
            ->assertSet('meshDirect', false)
            ->assertDispatched('notify', type: 'success')
            ->assertSee('Tailscale connected')->assertSee('100.101.102.103')
            // What the server said after it, without the version it names.
            ->assertSeeHtml('data-zt-mesh-message')->assertSee('This server runs Cipi')->assertDontSee('9.9.9')
            ->assertDontSee('tskey-auth-kTEST-0123456789')
            ->assertDontSeeHtml('data-zt-mesh-join');

        $join = $this->sent('POST', '/api/zt/mesh');
        $this->assertNotNull($join);
        $this->assertSame(
            ['provider' => 'tailscale', 'key' => 'tskey-auth-kTEST-0123456789', 'hostname' => 'web1', 'direct' => true, 'tags' => ['tag:cipi', 'tag:prod'], 'client' => '127.0.0.1'],
            $join->data(),
        );
        $this->assertSame(1, $this->changes());
    }

    #[Test]
    public function a_form_that_is_not_valid_goes_nowhere(): void
    {
        $this->tab()
            ->set('meshKey', 'has a space')
            ->set('meshHostname', 'web_1')
            ->set('meshControlUrl', 'ftp://x')
            ->set('meshTags', 'no way')
            ->call('joinMesh')
            ->assertHasErrors(['meshKey', 'meshHostname', 'meshControlUrl', 'meshTags'])
            ->assertSee('does not look like a key');

        $this->assertSame(0, $this->changes());
    }

    #[Test]
    public function a_join_the_server_refuses_shows_its_words_and_still_forgets_the_key(): void
    {
        $this->answers['POST /api/zt/mesh'] = [['error' => 'The key was refused by the coordination server: invalid key'], 422];

        $this->tab()
            ->set('meshKey', 'tskey-auth-kBAD-0123456789')
            ->call('joinMesh')
            ->assertSet('meshKey', '')
            ->assertSet('error', 'The key was refused by the coordination server: invalid key')
            ->assertDispatched('notify', type: 'error')
            ->assertSeeHtml('data-zt-mesh-join');
    }

    #[Test]
    public function a_connected_server_offers_each_lock_and_says_how_the_panel_reaches_it(): void
    {
        $this->mesh = $this->tailscale();

        $this->tab()
            ->assertSeeHtml('data-zt-mesh-actions')
            ->assertSeeHtml("wire:click=\"lockMesh('ssh')\"")->assertSeeHtml("wire:click=\"lockMesh('http')\"")
            ->assertSeeHtml('wire:click="setMeshSsh(true)"')->assertSeeHtml('wire:click="leaveMesh"')
            ->assertSee('Last login over the network from 100.99.1.2')
            ->assertSee('This panel reaches the server over Tailscale (from 100.99.1.9)')
            ->assertDontSeeHtml('data-zt-mesh-why')
            ->assertDontSeeHtml('data-zt-mesh-join');
    }

    #[Test]
    public function closing_and_reopening_a_port_asks_the_server_and_shows_what_it_has(): void
    {
        $this->mesh = $this->tailscale();
        $tab = $this->tab();

        $this->mesh = $this->tailscale(['lock' => ['ssh' => true, 'http' => false]]);
        $tab->call('lockMesh', 'ssh')
            ->assertDispatched('notify', type: 'success')
            ->assertSee('Closed to the world — from the network only')
            ->assertSeeHtml("wire:click=\"unlockMesh('ssh')\"")->assertDontSeeHtml("wire:click=\"lockMesh('ssh')\"");
        $this->assertSame(['port' => 'ssh', 'client' => '127.0.0.1'], $this->sent('POST', '/api/zt/mesh/lock')->data());

        $this->mesh = $this->tailscale(['lock' => ['ssh' => true, 'http' => true]]);
        $tab->call('lockMesh', 'http')->assertSee('the sites, the panel and the API answer on the network only');
        $this->assertSame(['port' => 'http', 'client' => '127.0.0.1'], $this->sent('POST', '/api/zt/mesh/lock')->data());

        $this->mesh = $this->tailscale(['lock' => ['ssh' => false, 'http' => true]]);
        $tab->call('unlockMesh', 'ssh')->assertSeeHtml("wire:click=\"lockMesh('ssh')\"");
        $this->assertSame(['port' => 'ssh', 'client' => '127.0.0.1'], $this->sent('DELETE', '/api/zt/mesh/lock')->data());
        $this->assertSame(3, $this->changes());
    }

    #[Test]
    public function what_the_server_would_refuse_is_greyed_out_with_its_reason(): void
    {
        $this->mesh = $this->tailscale(['ssh' => ['provider_ssh' => false, 'ports' => [22], 'command' => 'ssh cipi@100.101.102.103', 'seen' => null]], [
            'lock_ssh' => 'Nobody has signed in over SSH through Tailscale yet.',
            'lock_http' => 'The panel reaches this server on its public address: it could not reach it any more.',
        ], 'public');

        $html = $this->tab()
            ->assertSeeHtml('data-zt-mesh-why="ssh"')->assertSee('Nobody has signed in over SSH through Tailscale yet.')
            ->assertSeeHtml('data-zt-mesh-why="http"')->assertSee('it could not reach it any more')
            ->assertSee('This panel reaches the server on its public address')
            ->assertDontSeeHtml('data-zt-mesh-why="leave"')
            ->assertDontSeeHtml('data-zt-mesh-seen')
            ->html();

        foreach (["lockMesh('ssh')", "lockMesh('http')"] as $action) {
            $this->assertMatchesRegularExpression('/<button[^>]*wire:click="'.preg_quote($action, '/').'"[^>]*\sdisabled[\s>=]/', $html);
        }
        $this->assertDoesNotMatchRegularExpression('/<button[^>]*wire:click="leaveMesh"[^>]*\sdisabled[\s>=]/', $html);
    }

    #[Test]
    public function a_refusal_after_the_click_is_shown_as_the_server_said_it(): void
    {
        $this->mesh = $this->tailscale();
        $this->answers['POST /api/zt/mesh/lock'] = [['error' => 'The node key of this machine expires on 2027-01-06 (40 days): disable key expiry for the machine.'], 422];

        $this->tab()->call('lockMesh', 'ssh')
            ->assertSet('error', 'The node key of this machine expires on 2027-01-06 (40 days): disable key expiry for the machine.')
            ->assertDispatched('notify', type: 'error')
            ->assertSeeHtml("wire:click=\"lockMesh('ssh')\"");
    }

    #[Test]
    public function a_panel_on_the_server_itself_says_when_closing_http_would_close_its_own_page(): void
    {
        $mesh = $this->tailscale([], ['lock_http' => 'You are using this panel on the public address of the server: the page itself would stop answering you.'], 'local');
        $mesh['panel']['from'] = '127.0.0.1';
        $mesh['panel']['client'] = ['via' => 'public', 'from' => '198.51.100.7'];
        $this->mesh = $mesh;

        $this->tab()
            ->assertSee('This panel runs on the server itself.')
            ->assertSee('You are looking at it on its public address (from 198.51.100.7)')
            ->assertSeeHtml('data-zt-mesh-why="http"')->assertSee('the page itself would stop answering you');
    }

    #[Test]
    public function tailscale_ssh_is_turned_on_and_off_and_netbird_has_no_such_row(): void
    {
        $this->mesh = $this->tailscale();
        $tab = $this->tab();

        $this->mesh = $this->tailscale(['ssh' => ['provider_ssh' => true, 'ports' => [22], 'command' => 'ssh cipi@100.101.102.103', 'seen' => null]]);
        $tab->call('setMeshSsh', true)->assertSeeHtml('wire:click="setMeshSsh(false)"')->assertDontSeeHtml('wire:click="setMeshSsh(true)"');
        $this->assertSame(['enabled' => true, 'client' => '127.0.0.1'], $this->sent('PUT', '/api/zt/mesh/ssh')->data());

        $tab->call('setMeshSsh', false);
        $this->assertSame(['enabled' => false, 'client' => '127.0.0.1'], $this->sent('PUT', '/api/zt/mesh/ssh')->data());

        $this->mesh = ['enabled' => true, 'provider' => 'netbird', 'label' => 'NetBird', 'connected' => true, 'state' => 'Connected', 'ip' => '100.76.3.41', 'fqdn' => null, 'network' => null,
            'control_url' => null, 'peers' => ['total' => 2, 'online' => 2], 'ssh' => ['provider_ssh' => false, 'ports' => [22], 'command' => 'ssh cipi@100.76.3.41', 'seen' => null],
            'lock' => ['ssh' => false, 'http' => false], 'warnings' => [], 'panel' => ['via' => 'public', 'from' => '203.0.113.9', 'client' => null, 'blocked' => ['lock_ssh' => null, 'lock_http' => null, 'disable' => null]]];
        $this->tab()->assertSee('NetBird connected')->assertSeeHtml('data-zt-mesh-ssh')->assertDontSeeHtml('data-zt-mesh-tailscale-ssh');
    }

    #[Test]
    public function leaving_the_network_brings_the_join_form_back(): void
    {
        $this->mesh = $this->tailscale([], [], 'public');
        $tab = $this->tab();

        $this->mesh = ['enabled' => false, 'provider' => null, 'active' => null];
        $tab->call('leaveMesh')
            ->assertDispatched('notify', type: 'success')
            ->assertSeeHtml('data-zt-mesh-join')
            ->assertDontSeeHtml('data-zt-mesh-leave');

        $this->assertNotNull($this->sent('DELETE', '/api/zt/mesh'));
    }

    #[Test]
    public function without_manage_access_the_card_is_status_only_and_no_action_goes(): void
    {
        $member = $this->member();
        Access::replaceGrants((int) $member->getKey(), [$this->server->id => Access::VIEW], []);
        $this->actingAs($member);

        $this->mesh = $this->tailscale();
        $tab = $this->tab()
            ->assertSee('Tailscale connected')
            ->assertSee('needs Manage access to this server')
            ->assertDontSeeHtml('wire:click="leaveMesh"')
            ->assertDontSeeHtml("wire:click=\"lockMesh('ssh')\"");

        foreach ([['lockMesh', 'ssh'], ['unlockMesh', 'http'], ['setMeshSsh', true], ['leaveMesh'], ['joinMesh']] as $call) {
            $tab->call(...$call);
        }
        $this->assertSame(0, $this->changes());

        $this->mesh = ['enabled' => false, 'provider' => null, 'active' => null];
        $this->tab()->assertDontSeeHtml('data-zt-mesh-join')->assertSee('Joining a network needs Manage access');
    }

    #[Test]
    public function a_port_that_is_neither_ssh_nor_http_goes_nowhere(): void
    {
        $this->mesh = $this->tailscale();

        $this->tab()->call('lockMesh', 'rdp')->call('unlockMesh', '22; id');
        $this->assertSame(0, $this->changes());
    }

    #[Test]
    public function a_token_without_the_ability_and_an_older_server_are_said_in_words(): void
    {
        $this->mesh = $this->tailscale();

        $this->answers['POST /api/zt/mesh/lock'] = [['message' => 'Invalid ability provided.'], 403];
        $this->tab()->call('lockMesh', 'ssh')->assertSet('error', 'The token of this connection is missing the zt-manage ability: create it again with the command on Connections, on the server.');

        $this->answers['POST /api/zt/mesh/lock'] = [['message' => 'Not Found'], 404];
        $this->tab()->call('lockMesh', 'ssh')->assertSet('error', 'Managing the mesh from the panel needs an update of Cipi on this server (sudo cipi self-update && sudo cipi api update).');
    }

    #[Test]
    public function a_mesh_the_server_says_nothing_more_about_is_left_to_its_shell(): void
    {
        $mesh = $this->tailscale();
        unset($mesh['panel']);
        $this->mesh = $mesh;

        $this->tab()
            ->assertSeeHtml('data-zt-mesh-shell')->assertSee('cipi zt tailscale')
            ->assertDontSeeHtml('wire:click="leaveMesh"')->assertDontSeeHtml("wire:click=\"lockMesh('ssh')\"");
    }

    #[Test]
    public function the_firewall_tab_sends_a_lock_of_the_mesh_to_the_zero_trust_tab(): void
    {
        $this->answers['GET /api/firewall'] = [['data' => [
            'ufw' => ['installed' => true, 'active' => true, 'default_incoming' => 'deny', 'default_outgoing' => 'allow', 'rules' => []],
            'protected_ports' => [22, 80, 443],
            'zero_trust' => ['lock_http' => false, 'lock_ssh' => true, 'by' => ['http' => [], 'ssh' => ['tailscale']]],
            'fail2ban' => ['installed' => true, 'running' => true, 'attempts' => 3, 'attempts_default' => 3, 'attempts_custom' => null, 'attempts_pending' => false,
                'findtime' => 3600, 'bantime' => 86400, 'jails' => []],
            'crowdsec' => ['installed' => false, 'running' => false, 'bouncer' => 'not installed', 'mode' => '', 'rescue' => 'stopped', 'rescue_port' => null,
                'allowlist' => [], 'decisions' => [], 'ram_available_mb' => 2048, 'ram_required_mb' => 512, 'enable_blockers' => []],
        ]], 200];

        Livewire::test(Firewall::class, ['serverId' => $this->server->id])->call('init')
            ->assertSeeHtml('data-firewall-zt-tab')->assertSee('Zero Trust tab')->assertSee('cipi zt tailscale unlock ssh');
    }

    #[Test]
    public function the_command_on_connections_asks_for_the_ability(): void
    {
        $this->assertContains('zt-manage', (array) config('cipi-gui.token_abilities'));
    }
}
