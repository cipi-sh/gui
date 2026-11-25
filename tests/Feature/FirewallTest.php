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
 * The Firewall tab (4.6.0, API 1.44 / Cipi 5.14.0): UFW, fail2ban, CrowdSec.
 */
class FirewallTest extends TestCase
{
    private CipiServer $server;

    /** @var array<string, array{0: array<string, mixed>, 1: int}> "METHOD path" => [body, status] */
    private array $answers = [];

    private function rule(array $r): array
    {
        return $r + ['action' => 'allow', 'proto' => 'tcp', 'from' => 'any', 'to' => 'any', 'direction' => 'in', 'interface' => '', 'app' => '', 'comment' => '',
            'family' => 'both', 'log' => false, 'kind' => 'port', 'managed_by' => '', 'protected' => false, 'removable' => true];
    }

    private function firewall(array $crowdsec = []): array
    {
        return [
            'ufw' => ['installed' => true, 'active' => true, 'default_incoming' => 'deny', 'default_outgoing' => 'allow', 'rules' => [
                $this->rule(['action' => 'deny', 'port' => 'any', 'proto' => 'any', 'from' => '192.0.2.66', 'family' => 'v4', 'kind' => 'block']),
                $this->rule(['port' => '22', 'protected' => true, 'removable' => false]),
                $this->rule(['port' => '443', 'protected' => true, 'removable' => false]),
                $this->rule(['port' => '9100', 'from' => '203.0.113.5', 'family' => 'v4', 'comment' => 'cipi metrics', 'managed_by' => 'metrics', 'removable' => false]),
                $this->rule(['port' => '5432', 'from' => '10.0.0.0/8', 'family' => 'v4']),
            ]],
            'protected_ports' => [22, 80, 443],
            'zero_trust' => ['lock_http' => false, 'lock_ssh' => false],
            'fail2ban' => ['installed' => true, 'running' => true, 'attempts' => 3, 'attempts_default' => 3, 'attempts_custom' => null, 'attempts_pending' => false,
                'findtime' => 3600, 'bantime' => 86400, 'jails' => [['jail' => 'sshd', 'banned' => ['198.51.100.20'], 'count' => 1, 'total' => 9, 'failed' => 2]]],
            'crowdsec' => $crowdsec + ['installed' => false, 'running' => false, 'bouncer' => 'not installed', 'mode' => '', 'rescue' => 'stopped', 'rescue_port' => null,
                'allowlist' => [], 'decisions' => [], 'ram_available_mb' => 2048, 'ram_required_mb' => 512, 'enable_blockers' => []],
        ];
    }

    protected function setUp(): void
    {
        parent::setUp();

        $fw = $this->firewall();
        $changed = ['data' => $fw, 'message' => 'done'];
        $this->answers = [
            'GET /api/firewall' => [['data' => $fw], 200],
            'POST /api/firewall/rules' => [$changed, 200],
            'DELETE /api/firewall/rules' => [$changed, 200],
            'POST /api/firewall/blocks' => [$changed, 200],
            'DELETE /api/firewall/blocks' => [$changed, 200],
            'PUT /api/firewall/attempts' => [$changed, 200],
            'DELETE /api/firewall/bans' => [$changed, 200],
            'POST /api/firewall/crowdsec/enable' => [['job_id' => '11111111-2222-4333-8444-555555555555', 'status' => 'pending'], 202],
        ];

        Http::fake(function (Request $request) {
            $key = $request->method().' '.parse_url($request->url(), PHP_URL_PATH);
            [$body, $status] = $this->answers[$key] ?? [['data' => []], 200];

            return Http::response($body, $status);
        });

        $this->server = $this->server();
        $this->actingAs($this->admin());
        session(['cipi_gui_server_id' => $this->server->id]);
    }

    private function sent(string $method, string $path): ?Request
    {
        $sent = Http::recorded(fn (Request $r) => $r->method() === $method && parse_url($r->url(), PHP_URL_PATH) === $path);

        return $sent->isEmpty() ? null : $sent->last()[0];
    }

    private function tab()
    {
        return Livewire::test(Firewall::class, ['serverId' => $this->server->id])->call('init');
    }

    #[Test]
    public function the_server_page_has_a_firewall_tab(): void
    {
        $this->get('/server/'.$this->server->id.'?tab=firewall')->assertOk()->assertSee('Firewall');
        Livewire::test(ServerManage::class, ['serverId' => $this->server->id])
            ->call('setTab', 'firewall')
            ->assertSet('activeTab', 'firewall')
            ->assertSeeHtml('data-firewall');
    }

    #[Test]
    public function the_tab_shows_ufw_fail2ban_and_crowdsec(): void
    {
        $this->tab()
            ->assertSet('loaded', true)
            ->assertSet('unsupported', false)
            ->assertSee('Kept open')
            ->assertSee('Metrics')
            ->assertSee('10.0.0.0/8')
            ->assertSee('192.0.2.66')
            ->assertSee('198.51.100.20')
            ->assertSee('fail2ban · sshd')
            ->assertSee('22, 80, 443')
            ->assertSee('Enable CrowdSec')
            ->assertSet('attempts', '3');
    }

    #[Test]
    public function a_port_is_opened_with_what_the_form_says_and_a_bad_one_is_refused_here(): void
    {
        $this->tab()
            ->set('rulePort', '8080')->set('ruleFrom', '203.0.113.0/24')->set('ruleProto', 'udp')
            ->call('addRule')
            ->assertHasNoErrors()
            ->assertSet('rulePort', '');

        $sent = $this->sent('POST', '/api/firewall/rules');
        $this->assertSame(['port' => '8080', 'from' => '203.0.113.0/24', 'proto' => 'udp'], $sent?->data());

        $this->tab()->set('rulePort', '70000')->call('addRule')->assertHasErrors('rulePort');
        $this->tab()->set('rulePort', '22')->set('ruleFrom', 'example.com')->call('addRule')->assertHasErrors('ruleFrom');
        $this->assertCount(1, Http::recorded(fn (Request $r) => $r->method() === 'POST'), 'a value the form refuses never reaches the server');
    }

    #[Test]
    public function only_a_rule_the_server_marks_removable_is_removed(): void
    {
        $tab = $this->tab();

        $tab->call('removeRule', 1); // 22, protected
        $tab->call('removeRule', 3); // 9100, Metrics'
        $tab->call('removeRule', 99);
        $this->assertNull($this->sent('DELETE', '/api/firewall/rules'));

        $tab->call('removeRule', 4)->assertDispatched('notify');
        $this->assertSame(['port' => '5432', 'from' => '10.0.0.0/8', 'proto' => 'tcp', 'action' => 'allow'], $this->sent('DELETE', '/api/firewall/rules')?->data());
    }

    #[Test]
    public function block_unblock_and_unban_take_only_what_the_page_lists(): void
    {
        $tab = $this->tab();

        $tab->set('blockAddress', '0.0.0.0/0')->call('blockAddress')->assertHasErrors('blockAddress');
        $tab->set('blockAddress', '198.51.100.23')->call('blockAddress')->assertHasNoErrors();
        $this->assertSame(['address' => '198.51.100.23'], $this->sent('POST', '/api/firewall/blocks')?->data());

        $tab->call('unblock', '203.0.113.200');
        $this->assertNull($this->sent('DELETE', '/api/firewall/blocks'));
        $tab->call('unblock', '192.0.2.66');
        $this->assertSame(['address' => '192.0.2.66'], $this->sent('DELETE', '/api/firewall/blocks')?->data());

        $tab->call('unban', '1.2.3.4');
        $this->assertNull($this->sent('DELETE', '/api/firewall/bans'));
        $tab->call('unban', '198.51.100.20');
        $this->assertSame(['ip' => '198.51.100.20'], $this->sent('DELETE', '/api/firewall/bans')?->data());
    }

    #[Test]
    public function the_fail2ban_threshold_is_saved_or_set_back_to_the_default(): void
    {
        $tab = $this->tab();
        $tab->set('attempts', '0')->call('saveAttempts')->assertHasErrors('attempts');
        $tab->set('attempts', '5')->call('saveAttempts')->assertHasNoErrors();
        $this->assertSame(['attempts' => 5], $this->sent('PUT', '/api/firewall/attempts')?->data());

        $tab->call('defaultAttempts');
        $this->assertSame(['attempts' => null], $this->sent('PUT', '/api/firewall/attempts')?->data());
    }

    #[Test]
    public function enabling_crowdsec_is_a_job_and_what_stops_it_is_said_before(): void
    {
        $this->tab()->call('enableCrowdsec')->assertSet('jobRunning', true)->assertSet('jobLabel', 'Enable CrowdSec');
        $this->assertNotNull($this->sent('POST', '/api/firewall/crowdsec/enable'));

        $this->answers['GET /api/firewall'] = [['data' => $this->firewall(['enable_blockers' => ['Only 300 MB of RAM free: CrowdSec wants 512 MB']])], 200];
        $this->tab()->assertSee('This server cannot take CrowdSec now')->assertSee('Only 300 MB of RAM free');
    }

    #[Test]
    public function a_view_only_user_reads_it_and_changes_nothing(): void
    {
        $member = $this->member();
        Access::replaceGrants((int) $member->getKey(), [$this->server->id => Access::VIEW], []);
        $this->actingAs($member);

        $tab = $this->tab()->assertSee('198.51.100.20')->assertDontSee('Open port')->assertSee('Changing the firewall needs Manage access');
        $tab->set('rulePort', '8080')->call('addRule');
        $tab->call('unban', '198.51.100.20');
        $this->assertNull($this->sent('POST', '/api/firewall/rules'));
        $this->assertNull($this->sent('DELETE', '/api/firewall/bans'));
    }

    #[Test]
    public function an_older_server_says_what_to_update(): void
    {
        $this->answers['GET /api/firewall'] = [['message' => 'Not Found'], 404];
        $this->tab()->assertSet('unsupported', true)->assertSee('an up-to-date Cipi')->assertSee('firewall-manage')->assertDontSee('5.14.0');

        $this->answers['GET /api/firewall'] = [['error' => 'The firewall from the panel needs Cipi 5.14.0+ — this server runs Cipi 5.13.1. Run: cipi self-update'], 503];
        $this->tab()->assertSet('unsupported', true)->assertSee('needs an update of Cipi on this server')->assertDontSee('5.13.1');
    }

    #[Test]
    public function the_servers_refusal_is_shown_as_it_said_it(): void
    {
        $this->answers['POST /api/firewall/rules'] = [['error' => 'Zero Trust keeps 80/443 to Cloudflare (cipi zt lock http): an allow to everyone would reopen them'], 422];
        $this->tab()->set('rulePort', '443')->call('addRule')
            ->assertSet('error', 'Zero Trust keeps 80/443 to Cloudflare (cipi zt lock http): an allow to everyone would reopen them')
            ->assertSet('rulePort', '443');
    }
}
