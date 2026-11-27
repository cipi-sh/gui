<?php

namespace CipiGui\Tests\Feature;

use CipiGui\Livewire\ServerManage;
use CipiGui\Models\CipiServer;
use CipiGui\Services\Access;
use CipiGui\Tests\TestCase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;

/**
 * The Transfer tab of a server: its apps sent to another server (`cipi sync`)
 * as a wizard. The source does the work; a target that is in the panel is
 * handed the source's key by the panel and gives it back when the transfer
 * ends; the passphrase of the archive is the server's own and never passes
 * through here.
 */
class SyncTransferTest extends TestCase
{
    private const KEY = 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAINu+yA0T1qlR7jIZ6xsIgkPSF3mfcP1NwHsNGgyUUI4G cipi@production';

    private CipiServer $source;

    private CipiServer $target;

    /** `POST /api/sync/check` of the source: what it says of the target. */
    private array $check = ['ok' => true, 'cipi' => '5.18.0', 'auto_passphrase' => true, 'reason' => null, 'message' => ''];

    /** The job of the transfer, next to its id and type. */
    private array $job = ['status' => 'completed', 'exit_code' => 0, 'output' => '✓ 2 created, 0 updated', 'result' => []];

    /** @var array<string, array{0: array<string, mixed>, 1: int}> "host METHOD path" => [body, status] */
    private array $answers = [];

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake(function (Request $request) {
            $host = parse_url($request->url(), PHP_URL_HOST);
            $path = parse_url($request->url(), PHP_URL_PATH);
            $key = "{$host} {$request->method()} {$path}";
            if (isset($this->answers[$key])) {
                return Http::response(...$this->answers[$key]);
            }

            return match (true) {
                $path === '/api/apps' => Http::response(['data' => [
                    ['app' => 'shop', 'domain' => 'shop.example.com', 'php' => '8.5', 'node' => false],
                    ['app' => 'docs', 'domain' => 'docs.example.com', 'node' => true, 'node_mode' => 'static'],
                ]]),
                $path === '/api/sync/key' => Http::response(['data' => ['key' => self::KEY, 'type' => 'ssh-ed25519', 'comment' => 'cipi@production', 'fingerprint' => 'SHA256:0+Op161dtkuC']]),
                $path === '/api/sync/check' => Http::response(['data' => $this->check + ['host' => $request['host'], 'port' => $request['port'], 'user' => 'cipi']]),
                $path === '/api/sync/trust' => Http::response(['data' => ['key' => self::KEY, 'type' => 'ssh-ed25519', 'fingerprint' => 'SHA256:0+Op161dtkuC', 'comment' => 'cipi@production', 'changed' => true]]),
                $path === '/api/sync/push' => Http::response(['job_id' => 'job-1', 'status' => 'pending'], 202),
                str_starts_with((string) $path, '/api/jobs/') => Http::response(['data' => ['id' => 'job-1', 'type' => 'sync-push'] + $this->job]),
                default => Http::response(['data' => []]),
            };
        });

        $this->source = $this->server();
        $this->target = $this->server(['name' => 'clients', 'url' => 'https://clients.example.com', 'ip' => '198.51.100.42', 'token' => 'demo-token-clients-12345']);
        $this->actingAs($this->admin());
        session(['cipi_gui_server_id' => $this->source->id]);
    }

    private function tab(array $query = [])
    {
        return Livewire::withQueryParams(['tab' => 'sync'] + $query)->test(ServerManage::class, ['serverId' => $this->source->id])->call('init');
    }

    /** @return list<Request> */
    private function sent(string $host, string $method, string $path): array
    {
        return Http::recorded(fn (Request $r) => parse_url($r->url(), PHP_URL_HOST) === $host && $r->method() === $method && parse_url($r->url(), PHP_URL_PATH) === $path)
            ->map(fn ($pair) => $pair[0])->values()->all();
    }

    private function changes(): int
    {
        return Http::recorded(fn (Request $r) => $r->method() !== 'GET')->count();
    }

    #[Test]
    public function the_tab_lists_the_apps_and_shows_the_key_for_a_server_outside_the_panel(): void
    {
        $this->tab()
            ->assertSee('Send apps to another server')
            ->assertSeeHtml('data-sync-step="what"')->assertSeeHtml('data-sync-step="where"')->assertSeeHtml('data-sync-step="go"')
            ->assertSee('All 2 of this server')
            ->assertSeeHtml('data-sync-key')->assertSee(self::KEY)->assertSee('SHA256:0+Op161dtkuC')
            ->assertSeeHtml('data-sync-encryption')->assertSee('never passes through this panel')
            ->assertSeeHtml('data-sync-receive')->assertSee('From clients')
            ->assertDontSeeHtml('data-sync-managed')
            ->assertDontSeeHtml('data-sync-verdict')
            ->assertSeeHtml('data-sync-start="wait"');

        $this->assertSame(0, $this->changes());
        $this->assertContains('sync-manage', (array) config('cipi-gui.token_abilities'));
    }

    #[Test]
    public function the_apps_can_be_chosen_one_by_one(): void
    {
        $this->tab()
            ->assertDontSeeHtml('data-sync-apps')
            ->set('syncScope', 'chosen')
            ->assertSeeHtml('data-sync-apps')->assertSee('shop.example.com')->assertSee('docs.example.com')
            ->set('syncHost', '203.0.113.9')
            ->call('checkSync')
            ->assertHasErrors('syncApps');

        $this->assertSame(0, $this->changes(), 'nothing is asked of a server while the form is wrong');
    }

    #[Test]
    public function a_server_of_the_panel_is_prepared_by_the_panel_and_then_checked(): void
    {
        $tab = $this->tab()
            ->set('syncPeerId', $this->target->id)
            ->assertSet('syncHost', '198.51.100.42')
            ->assertSeeHtml('data-sync-managed')
            ->assertDontSeeHtml('data-sync-key')
            ->call('checkSync')
            ->assertHasNoErrors()
            ->assertSet('syncTrustedPeerId', $this->target->id)
            ->assertSeeHtml('data-sync-verdict="ok"')
            ->assertSeeHtml('data-sync-trusted');

        // The source's key went to the target's API, with the target's token…
        $trust = $this->sent('clients.example.com', 'POST', '/api/sync/trust');
        $this->assertCount(1, $trust);
        $this->assertSame(['key' => self::KEY], $trust[0]->data());
        $this->assertSame('Bearer demo-token-clients-12345', $trust[0]->header('Authorization')[0]);

        // …and the source was asked whether the way is open. Nothing else changed.
        $check = $this->sent('vps.example.com', 'POST', '/api/sync/check');
        $this->assertCount(1, $check);
        $this->assertSame(['host' => '198.51.100.42', 'port' => 22], $check[0]->data());
        $this->assertSame(2, $this->changes());
        $tab->assertSeeHtml('data-sync-start="ready"');
    }

    #[Test]
    public function the_transfer_is_a_job_of_the_source_and_carries_no_passphrase(): void
    {
        $tab = $this->tab()
            ->set('syncPeerId', $this->target->id)
            ->set('syncWithStorage', true)
            ->call('checkSync')
            ->call('startSync')
            ->assertHasNoErrors()
            ->assertSet('jobRunning', true)
            ->assertSet('jobLabel', 'Transfer 2 apps to clients');

        $push = $this->sent('vps.example.com', 'POST', '/api/sync/push');
        $this->assertCount(1, $push);
        $this->assertSame(['host' => '198.51.100.42', 'port' => 22, 'apps' => [], 'with_db' => true, 'with_storage' => true], $push[0]->data());
        $this->assertStringNotContainsString('pass', strtolower($push[0]->body()));
        $this->assertSame([], $this->sent('clients.example.com', 'POST', '/api/sync/push'), 'the target is never the one asked to send');

        // When the job ends, the key the panel added on the target is taken out again.
        $tab->call('pollJob')
            ->assertSet('activeJobStatus', 'completed')
            ->assertSet('syncTrustedPeerId', 0)
            ->assertSee('no longer trusted there');
        $untrust = $this->sent('clients.example.com', 'DELETE', '/api/sync/trust');
        $this->assertCount(1, $untrust);
        $this->assertSame(['key' => self::KEY], $untrust[0]->data());
    }

    #[Test]
    public function chosen_apps_are_the_only_ones_named(): void
    {
        $this->tab()
            ->set('syncPeerId', $this->target->id)
            ->set('syncScope', 'chosen')->set('syncApps', ['docs'])
            ->set('syncWithDb', false)
            ->call('checkSync')->call('startSync')
            ->assertSet('jobLabel', 'Transfer 1 app to clients');

        $this->assertSame(
            ['host' => '198.51.100.42', 'port' => 22, 'apps' => ['docs'], 'with_db' => false, 'with_storage' => false],
            $this->sent('vps.example.com', 'POST', '/api/sync/push')[0]->data(),
        );
    }

    #[Test]
    public function an_app_that_is_not_on_the_server_is_refused_before_anything_is_sent(): void
    {
        $this->tab()
            ->set('syncPeerId', $this->target->id)
            ->call('checkSync')
            ->set('syncScope', 'chosen')->set('syncApps', ['ghost'])
            ->call('startSync')
            ->assertHasErrors('syncApps')
            ->assertSet('jobRunning', false);

        $this->assertSame([], $this->sent('vps.example.com', 'POST', '/api/sync/push'));
    }

    #[Test]
    public function the_key_stays_on_the_target_when_asked_and_also_when_the_transfer_fails_it_goes(): void
    {
        $this->tab()
            ->set('syncPeerId', $this->target->id)->set('syncKeepTrust', true)
            ->call('checkSync')->call('startSync')->call('pollJob')
            ->assertSet('syncTrustedPeerId', $this->target->id);
        $this->assertSame([], $this->sent('clients.example.com', 'DELETE', '/api/sync/trust'));

        $this->job = ['status' => 'failed', 'exit_code' => 1, 'output' => '[ERROR] import failed', 'result' => ['error' => 'import failed']];
        $this->tab()
            ->set('syncPeerId', $this->target->id)
            ->call('checkSync')->call('startSync')->call('pollJob')
            ->assertSet('activeJobStatus', 'failed')
            ->assertSet('syncTrustedPeerId', 0);
        $this->assertCount(1, $this->sent('clients.example.com', 'DELETE', '/api/sync/trust'));
    }

    #[Test]
    public function a_key_that_was_trusted_already_is_not_the_wizards_to_take_back(): void
    {
        $this->answers['clients.example.com POST /api/sync/trust'] = [['data' => ['key' => self::KEY, 'changed' => false]], 200];

        $this->tab()
            ->set('syncPeerId', $this->target->id)
            ->call('checkSync')
            ->assertSet('syncTrustedPeerId', 0)
            ->assertDontSeeHtml('data-sync-trusted')
            ->call('startSync')->call('pollJob');

        $this->assertSame([], $this->sent('clients.example.com', 'DELETE', '/api/sync/trust'));
    }

    #[Test]
    public function the_key_can_be_removed_from_a_server_of_the_panel_at_any_time(): void
    {
        $this->tab()
            ->set('syncPeerId', $this->target->id)
            ->assertSeeHtml('data-sync-forget')
            ->call('forgetSyncTrust')
            ->assertSee('no longer trusted there');

        $this->assertCount(1, $this->sent('clients.example.com', 'DELETE', '/api/sync/trust'));
    }

    #[Test]
    public function nothing_starts_before_a_check_of_the_same_target(): void
    {
        $tab = $this->tab()
            ->set('syncHost', '203.0.113.9')
            ->call('startSync')
            ->assertHasErrors('syncHost')
            ->assertSet('jobRunning', false);

        // Checked, then the address changed: the check is not that target's any more.
        $tab->call('checkSync')
            ->assertSeeHtml('data-sync-verdict="ok"')
            ->set('syncHost', '203.0.113.10')
            ->assertDontSeeHtml('data-sync-verdict')
            ->call('startSync')
            ->assertHasErrors('syncHost');

        $this->assertSame([], $this->sent('vps.example.com', 'POST', '/api/sync/push'));
        $this->assertSame([], $this->sent('clients.example.com', 'POST', '/api/sync/trust'), 'a host typed by hand is not prepared: its key is shown instead');
    }

    #[Test]
    public function what_the_check_found_is_said_in_words(): void
    {
        $says = function (array $check, string $state, string $words): void {
            $this->check = $check + ['ok' => false, 'cipi' => null, 'auto_passphrase' => false, 'message' => ''];
            $this->tab()->set('syncHost', '203.0.113.9')->call('checkSync')
                ->assertSeeHtml('data-sync-verdict="'.$state.'"')->assertSee($words)
                ->call('startSync')->assertSet('jobRunning', false);
        };

        $says(['reason' => 'not-trusted'], 'trust', 'does not trust this server');
        $says(['reason' => 'unreachable'], 'error', 'does not answer over SSH');
        $says(['reason' => 'host-key-changed'], 'error', 'host key of the target');
        $says(['reason' => 'no-cipi'], 'error', 'Cipi does not');
        $says(['ok' => true, 'cipi' => '5.17.2'], 'old', 'too old for a transfer from the panel');

        $this->assertSame([], $this->sent('vps.example.com', 'POST', '/api/sync/push'));
    }

    #[Test]
    public function the_form_is_checked_before_a_server_is_asked(): void
    {
        $this->tab()->set('syncHost', 'not a host; rm -rf /')->call('checkSync')->assertHasErrors('syncHost');
        $this->tab()->set('syncHost', '203.0.113.9')->set('syncPort', '70000')->call('checkSync')->assertHasErrors('syncPort');
        $this->tab()->set('syncHost', '')->call('checkSync')->assertHasErrors('syncHost');

        $this->assertSame(0, $this->changes());
    }

    #[Test]
    public function the_link_from_the_other_server_arrives_with_the_target_chosen(): void
    {
        $this->tab(['to' => $this->target->id])
            ->assertSet('syncPeerId', $this->target->id)
            ->assertSet('syncHost', '198.51.100.42')
            ->assertSeeHtml('data-sync-managed');

        // An id that is not a server this user manages is no target at all.
        $this->tab(['to' => 999])->assertSet('syncPeerId', 0)->assertSet('syncHost', '');
    }

    #[Test]
    public function a_token_without_the_ability_and_an_older_server_are_told_apart(): void
    {
        $this->answers['vps.example.com GET /api/sync/key'] = [['message' => 'Invalid ability provided.'], 403];
        $this->tab()->assertSee('This token cannot move apps')->assertSee('sync-manage')->assertDontSeeHtml('data-sync-step');

        $this->answers['vps.example.com GET /api/sync/key'] = [['message' => 'Not found'], 404];
        $this->tab()->assertSee('Transfers from the panel need a newer server')->assertDontSeeHtml('data-sync-step');
    }

    #[Test]
    public function a_target_whose_token_cannot_take_the_key_is_said_so_and_still_checked(): void
    {
        $this->answers['clients.example.com POST /api/sync/trust'] = [['message' => 'Invalid ability provided.'], 403];
        $this->check = ['ok' => false, 'cipi' => null, 'auto_passphrase' => false, 'reason' => 'not-trusted', 'message' => ''];

        $this->tab()
            ->set('syncPeerId', $this->target->id)
            ->call('checkSync')
            ->assertSee('missing the sync-manage ability')
            ->assertSeeHtml('data-sync-verdict="trust"')
            ->assertSet('syncTrustedPeerId', 0);

        $this->assertCount(1, $this->sent('vps.example.com', 'POST', '/api/sync/check'));
    }

    #[Test]
    public function moving_apps_needs_manage_on_the_source_and_on_a_target_of_the_panel(): void
    {
        $viewer = $this->member();
        Access::replaceGrants($viewer->id, [$this->source->id => 'view', $this->target->id => 'manage'], []);
        $this->actingAs($viewer);

        $this->tab()
            ->assertSee('Moving apps needs Manage access')
            ->assertDontSeeHtml('data-sync-step')
            ->set('syncHost', '198.51.100.42')
            ->call('checkSync')->call('startSync')->call('forgetSyncTrust');
        $this->assertSame(0, $this->changes());
        $this->assertSame([], $this->sent('vps.example.com', 'GET', '/api/sync/key'));

        // Manage here, only a view there: that server is not offered as a target of the panel.
        Access::replaceGrants($viewer->id, [$this->source->id => 'manage', $this->target->id => 'view'], []);
        $this->tab(['to' => $this->target->id])
            ->assertSet('syncPeerId', 0)
            ->assertDontSee('From clients')
            ->set('syncPeerId', $this->target->id)
            ->assertSet('syncPeerId', 0);
        $this->assertSame([], $this->sent('clients.example.com', 'POST', '/api/sync/trust'));
    }
}
