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
 * Server → Backups: SFTP destinations (a VPS, a Storage Box) — no secret,
 * the key is made on the server. Server → Updates: the signature check and
 * the delay of self-update, both off unless turned on.
 */
class SftpAndUpdatesTest extends TestCase
{
    private const KEY = 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAITestKey cipi-backup-box@web1';

    private CipiServer $srv;

    /** @var list<array<string, mixed>> */
    private array $destinations = [['name' => 'local', 'type' => 'local', 'path' => '/var/backups/cipi', 'default' => false, 'profiles' => []]];

    private array $policy = ['verify' => false, 'delay_hours' => 0, 'delay_default_hours' => 12, 'signers' => [], 'signers_ready' => false,
        'shipped_signers' => [['fingerprint' => 'SHA256:abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQ', 'principal' => 'andrea@cipi.sh']], 'pending' => null, 'held' => null];

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake(function (Request $request) {
            $path = (string) parse_url($request->url(), PHP_URL_PATH);
            $method = $request->method();

            return match (true) {
                $path === '/api/backups' => Http::response(['data' => ['configured' => true, 'local_dir' => '/var/backups/cipi', 'encryption_key' => false,
                    'destinations' => $this->destinations, 'profiles' => [], 'apps' => [], 'databases' => [], 'uncovered' => ['apps' => [], 'databases' => []]]]),
                $path === '/api/backups/runs' => Http::response(['data' => ['profiles' => [], 'errors' => []]]),
                $path === '/api/backups/destinations' && $method === 'POST' => $this->addDestination($request),
                $path === '/api/backups/destinations/box' && $method === 'PUT' => Http::response(['data' => ['name' => 'box', 'type' => 'sftp', 'public_key' => self::KEY] + $request->data()]),
                $path === '/api/backups/destinations/box/key' => Http::response(['data' => ['name' => 'box', 'public_key' => self::KEY]]),
                $path === '/api/backups/destinations/box' && $method === 'DELETE' => Http::response(['data' => ['name' => 'box', 'removed' => true]]),
                $path === '/api/backups/destinations/test' => Http::response(['data' => ['ok' => true, 'output' => 'reachable']]),
                $path === '/api/self-update/policy' && $method === 'GET' => Http::response(['data' => $this->policy]),
                $path === '/api/self-update/policy' && $method === 'PUT' => $this->putPolicy($request),
                default => Http::response(['data' => []]),
            };
        });

        $this->srv = $this->server();
        $this->actingAs($this->admin());
        session(['cipi_gui_server_id' => $this->srv->id]);
    }

    private function addDestination(Request $request): mixed
    {
        $d = $request->data();
        $this->destinations[] = ['name' => $d['name'], 'type' => 'sftp', 'host' => $d['host'], 'port' => $d['port'], 'user' => $d['user'], 'path' => $d['path'], 'default' => false, 'profiles' => []];

        return Http::response(['data' => ['name' => $d['name'], 'type' => 'sftp', 'host' => $d['host'], 'port' => $d['port'], 'user' => $d['user'], 'path' => $d['path'], 'public_key' => self::KEY]], 201);
    }

    private function putPolicy(Request $request): mixed
    {
        $d = $request->data();
        if (($d['verify'] ?? false) && ! $this->policy['signers_ready'] && ($d['fingerprint'] ?? '') !== $this->policy['shipped_signers'][0]['fingerprint']) {
            return Http::response(['error' => 'That is not the fingerprint of the signers shipped with this release — nothing changed'], 422);
        }
        $this->policy['verify'] = (bool) ($d['verify'] ?? $this->policy['verify']);
        $this->policy['delay_hours'] = (int) ($d['delay_hours'] ?? $this->policy['delay_hours']);
        if ($this->policy['verify']) {
            $this->policy['signers'] = $this->policy['shipped_signers'];
            $this->policy['signers_ready'] = true;
        }

        return Http::response(['data' => $this->policy]);
    }

    private function tab(string $tab)
    {
        return Livewire::withQueryParams(['tab' => $tab])->test(ServerManage::class, ['serverId' => $this->srv->id])->call('init');
    }

    private function sent(string $method, string $path): ?Request
    {
        return Http::recorded(fn (Request $r) => $r->method() === $method && parse_url($r->url(), PHP_URL_PATH) === $path)->map(fn ($p) => $p[0])->last();
    }

    #[Test]
    public function an_sftp_server_is_added_and_its_key_shown_with_how_to_install_it(): void
    {
        $page = $this->tab('backups')
            ->assertSeeHtml('data-backup-sftp-add')
            ->call('openSftpForm')
            ->assertSeeHtml('data-backup-sftp-form')
            ->set('sftpName', 'box')->set('sftpUser', 'u1234')
            ->call('sftpPresetStorageBox')
            ->assertSet('sftpPort', '23')
            ->assertSet('sftpHost', 'u1234.your-storagebox.de')
            ->set('sftpPath', 'cipi/')
            ->call('saveSftpDestination')
            ->assertHasNoErrors()
            ->assertSet('showSftpForm', false)
            ->assertSeeHtml('data-backup-sftp-install')
            ->assertSee(self::KEY)
            ->assertSee('ssh-copy-id -p 23 -s -f -i cipi-backup-box.pub u1234@u1234.your-storagebox.de')
            ->assertSeeHtml('data-backup-dest="sftp"')
            ->assertSee('sftp://u1234@u1234.your-storagebox.de:23/cipi');

        $this->assertSame(['type' => 'sftp', 'name' => 'box', 'host' => 'u1234.your-storagebox.de', 'port' => 23, 'user' => 'u1234', 'path' => 'cipi'], $this->sent('POST', '/api/backups/destinations')->data());
        $this->assertStringNotContainsString('password', strtolower($this->sent('POST', '/api/backups/destinations')->body()));

        $page->call('testBackupDestination', 'box')->assertDispatched('notify');
        $this->assertSame(['name' => 'box'], $this->sent('POST', '/api/backups/destinations/test')->data());
    }

    #[Test]
    public function a_server_of_your_own_gets_the_authorized_keys_line(): void
    {
        $this->destinations[] = ['name' => 'box', 'type' => 'sftp', 'host' => '203.0.113.5', 'port' => 22, 'user' => 'backup', 'path' => '', 'default' => false, 'profiles' => []];

        $this->tab('backups')
            ->call('showSftpKey', 'box')
            ->assertSee(self::KEY)
            ->assertSee('>> ~/.ssh/authorized_keys', false)
            ->assertDontSee('ssh-copy-id');
    }

    #[Test]
    public function the_form_is_checked_before_anything_is_sent(): void
    {
        $page = $this->tab('backups')->call('openSftpForm');
        $page->set('sftpName', 's3')->set('sftpHost', 'a;b')->set('sftpPort', '70000')->set('sftpUser', 'x y')->set('sftpPath', '../etc')
            ->call('saveSftpDestination')
            ->assertHasErrors(['sftpName', 'sftpHost', 'sftpPort', 'sftpUser', 'sftpPath']);
        $page->set('sftpName', 'local')->set('sftpHost', 'h')->set('sftpPort', '22')->set('sftpUser', 'u')->set('sftpPath', '')
            ->call('saveSftpDestination')
            ->assertHasErrors(['sftpName']);

        $this->assertNull($this->sent('POST', '/api/backups/destinations'));
    }

    #[Test]
    public function an_edit_changes_the_fields_and_may_make_a_new_key(): void
    {
        $this->destinations[] = ['name' => 'box', 'type' => 'sftp', 'host' => 'u1.your-storagebox.de', 'port' => 23, 'user' => 'u1', 'path' => 'cipi', 'default' => false, 'profiles' => []];

        $this->tab('backups')
            ->call('openSftpForm', 'box')
            ->assertSet('sftpEditing', 'box')->assertSet('sftpPort', '23')
            ->set('sftpPath', 'backups')
            ->call('saveSftpDestination', true)
            ->assertHasNoErrors();

        $this->assertSame(['host' => 'u1.your-storagebox.de', 'port' => 23, 'user' => 'u1', 'path' => 'backups', 'new_key' => true], $this->sent('PUT', '/api/backups/destinations/box')->data());
    }

    #[Test]
    public function an_unused_sftp_destination_can_be_removed(): void
    {
        $this->destinations[] = ['name' => 'box', 'type' => 'sftp', 'host' => 'h', 'port' => 22, 'user' => 'u', 'path' => '', 'default' => false, 'profiles' => []];

        $this->tab('backups')->assertSeeHtml('data-backup-sftp-remove')->call('deleteBackupDestination', 'box')->assertDispatched('notify');
        $this->assertNotNull($this->sent('DELETE', '/api/backups/destinations/box'));
    }

    #[Test]
    public function a_viewer_sees_the_destinations_and_changes_nothing(): void
    {
        $viewer = $this->member();
        Access::replaceGrants($viewer->id, [$this->srv->id => 'view'], []);
        $this->actingAs($viewer);

        $this->tab('backups')->assertDontSeeHtml('data-backup-sftp-add')
            ->call('openSftpForm')->assertSet('showSftpForm', false)
            ->set('sftpName', 'box')->set('sftpHost', 'h')->set('sftpUser', 'u')->call('saveSftpDestination')
            ->call('deleteBackupDestination', 'box');
        $this->assertSame(0, Http::recorded(fn (Request $r) => $r->method() !== 'GET')->count());
    }

    #[Test]
    public function the_updates_tab_is_off_by_default_and_turns_both_safeguards_on(): void
    {
        $page = $this->tab('updates')
            ->assertSeeHtml('data-updates')
            ->assertSet('upVerify', false)->assertSet('upDelay', '0')
            ->assertSee('Install only signed releases')
            ->assertSee('not checked')
            ->set('upVerify', true)
            ->assertSeeHtml('data-updates-first-trust')
            ->assertSee('SHA256:abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQ')
            ->set('upDelay', '12')
            // The first trust needs the fingerprint the person compared.
            ->call('saveUpdatePolicy')
            ->assertHasErrors('upFingerprint');
        $this->assertNull($this->sent('PUT', '/api/self-update/policy'));

        $page->set('upFingerprint', 'SHA256:abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQ')
            ->call('saveUpdatePolicy')
            ->assertHasNoErrors()
            ->assertSet('updatePolicy.verify', true)->assertSet('updatePolicy.delay_hours', 12)
            ->assertSee('required');
        $this->assertSame(['verify' => true, 'delay_hours' => 12, 'fingerprint' => 'SHA256:abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQ'], $this->sent('PUT', '/api/self-update/policy')->data());

        // Trusted already: no fingerprint asked or sent again.
        $page->set('upDelay', '48')->call('saveUpdatePolicy')->assertHasNoErrors();
        $this->assertSame(['verify' => true, 'delay_hours' => 48], $this->sent('PUT', '/api/self-update/policy')->data());
    }

    #[Test]
    public function a_fingerprint_the_server_refuses_is_said_and_nothing_changes(): void
    {
        $this->tab('updates')
            ->set('upVerify', true)->set('upFingerprint', 'SHA256:'.str_repeat('Z', 43))
            ->call('saveUpdatePolicy')
            ->assertSet('upRefused', 'That is not the fingerprint of the signers shipped with this release — nothing changed')
            ->assertSeeHtml('data-updates-refused');
        $this->assertFalse($this->policy['verify']);
    }

    #[Test]
    public function a_server_without_the_policy_says_what_to_update(): void
    {
        Http::fake(['*' => Http::response(['message' => 'Not found'], 404)]);
        $this->tab('updates')->assertSeeHtml('data-updates-unsupported')->assertSee('cipi self-update && cipi api update');
    }
}
