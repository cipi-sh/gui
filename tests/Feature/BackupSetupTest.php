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
 * Server → Backups, set up and managed from the panel: the first setup (this
 * server only, or a default bucket), S3 buckets (the secret sent once, never
 * kept), the encryption key, the retention now. Server → Updates: signed
 * releases cannot be required while no release is signed.
 */
class BackupSetupTest extends TestCase
{
    private CipiServer $srv;

    private bool $configured = true;

    /** The API answers 404 to the new routes: an older server. */
    private bool $oldApi = false;

    /** @var list<array<string, mixed>> */
    private array $destinations = [
        ['name' => 'local', 'type' => 'local', 'path' => '/var/backups/cipi', 'default' => false, 'profiles' => []],
        ['name' => 's3', 'type' => 's3', 'bucket' => 'acme-backups', 'region' => 'fsn1', 'endpoint' => 'https://fsn1.your-objectstorage.com', 'default' => true, 'profiles' => ['default']],
    ];

    private array $policy = ['verify' => false, 'delay_hours' => 0, 'delay_default_hours' => 12, 'signers' => [], 'signers_ready' => false,
        'shipped_signers' => [], 'signed_releases' => false, 'pending' => null, 'held' => null];

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake(function (Request $request) {
            $path = (string) parse_url($request->url(), PHP_URL_PATH);
            $method = $request->method();
            if ($this->oldApi && in_array($path, ['/api/backups/config', '/api/backups/destinations'], true) && $method !== 'GET') {
                return Http::response(['message' => 'Not found'], 404);
            }
            if (($request->data()['bucket'] ?? '') === 'refused') {
                return Http::response(['error' => "S3 connection failed — nothing saved:\n  An error occurred (InvalidAccessKeyId)"], 422);
            }

            return match (true) {
                $path === '/api/backups' => Http::response(['data' => $this->configured
                    ? ['configured' => true, 'local_dir' => '/var/backups/cipi', 'encryption_key' => true,
                        'destinations' => $this->destinations, 'profiles' => [], 'apps' => [], 'databases' => [], 'uncovered' => ['apps' => [], 'databases' => []]]
                    : ['configured' => false, 'host' => 'web1', 'config_file' => '/etc/cipi/backup.json']]),
                $path === '/api/backups/config' && $method === 'PUT' => $this->configure($request),
                $path === '/api/backups/destinations' && $method === 'POST' => Http::response(['data' => ['name' => $request->data()['name'] ?? '', 'type' => 's3', 'bucket' => $request->data()['bucket'] ?? '']], 201),
                $path === '/api/backups/destinations/offsite' && $method === 'PUT' => Http::response(['data' => ['name' => 'offsite', 'type' => 's3']]),
                $path === '/api/backups/destinations/offsite' && $method === 'DELETE' => Http::response(['data' => ['name' => 'offsite', 'removed' => true]]),
                $path === '/api/backups/destinations/test' => Http::response(['data' => ['ok' => true, 'output' => '  offsite  s3://acme-offsite  OK']]),
                $path === '/api/backups/key' => Http::response(['data' => ['key' => 'kEy0123456789abcdefKEY']]),
                $path === '/api/backups/key/rotate' => Http::response(['data' => ['rotated' => true]]),
                $path === '/api/backups/prune' => Http::response(['job_id' => '11111111-2222-4333-8444-555555555555', 'status' => 'pending'], 202),
                $path === '/api/self-update/policy' && $method === 'GET' => Http::response(['data' => $this->policy]),
                default => Http::response(['data' => []]),
            };
        });

        $this->srv = $this->server();
        $this->actingAs($this->admin());
        session(['cipi_gui_server_id' => $this->srv->id]);
    }

    private function configure(Request $request): mixed
    {
        $this->configured = true;
        $d = $request->data();
        $this->destinations = [$this->destinations[0]];
        if (($d['type'] ?? '') === 'sftp') {
            $dest = ['name' => $d['name'], 'type' => 'sftp', 'host' => $d['host'], 'port' => $d['port'], 'user' => $d['user'], 'path' => $d['path'], 'default' => false];
            $this->destinations[] = $dest + ['profiles' => ['default']];

            return Http::response(['data' => ['configured' => true], 'type' => 'sftp', 'destination' => $dest + ['public_key' => 'ssh-ed25519 AAAASetupKey cipi-backup-sftp@web1']]);
        }
        if (($d['type'] ?? '') !== 'local') {
            $this->destinations[] = ['name' => 's3', 'type' => 's3', 'bucket' => $d['bucket'] ?? 'acme-backups', 'region' => $d['region'] ?? 'eu-central-1', 'endpoint' => null, 'default' => true, 'profiles' => []];
        }

        return Http::response(['data' => ['configured' => true], 'type' => $d['type'] ?? 's3', 'destination' => null]);
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
    public function a_server_without_backups_is_set_up_from_the_panel_on_this_server_only(): void
    {
        $this->configured = false;

        $this->tab('backups')
            ->assertSeeHtml('data-backup-unconfigured')
            ->assertSeeHtml('data-backup-setup-local')
            ->assertSeeHtml('data-backup-setup-s3')
            ->call('configureLocalBackups')
            ->assertDispatched('notify')
            ->assertDontSeeHtml('data-backup-unconfigured');

        $this->assertSame(['type' => 'local'], $this->sent('PUT', '/api/backups/config')->data());
    }

    #[Test]
    public function a_server_without_backups_is_set_up_to_an_sftp_server_and_its_key_shown(): void
    {
        $this->configured = false;

        $this->tab('backups')
            ->assertSeeHtml('data-backup-setup-sftp')
            ->call('openSftpSetup')
            ->assertSet('sftpSetup', true)
            ->assertSet('sftpName', 'sftp')
            ->assertSeeHtml('data-backup-sftp-form="setup"')
            ->set('sftpUser', 'u1234')
            ->call('sftpPresetStorageBox')
            ->set('sftpPath', 'cipi')
            ->call('saveSftpDestination')
            ->assertHasNoErrors()
            ->assertSet('sftpSetup', false)
            ->assertSet('showSftpForm', false)
            ->assertDontSeeHtml('data-backup-unconfigured')
            ->assertSeeHtml('data-backup-sftp-install')
            ->assertSee('ssh-ed25519 AAAASetupKey cipi-backup-sftp@web1')
            ->assertSee('ssh-copy-id -p 23 -s -f -i cipi-backup-sftp.pub u1234@u1234.your-storagebox.de');

        $this->assertSame(['type' => 'sftp', 'name' => 'sftp', 'host' => 'u1234.your-storagebox.de', 'port' => 23, 'user' => 'u1234', 'path' => 'cipi'], $this->sent('PUT', '/api/backups/config')->data());
        $this->assertNull($this->sent('POST', '/api/backups/destinations'));
    }

    #[Test]
    public function a_server_without_backups_is_set_up_with_a_bucket_and_the_secret_is_not_kept(): void
    {
        $this->configured = false;

        $this->tab('backups')
            ->call('openS3Form', 's3')
            ->assertSet('s3Editing', 's3')
            ->assertSeeHtml('data-backup-s3-form="default"')
            ->set('s3Provider', 'hetzner')
            ->assertSet('s3Endpoint', 'https://fsn1.your-objectstorage.com')
            ->assertSet('s3Region', 'fsn1')
            ->set('s3Bucket', 'acme-backups')
            ->call('saveS3Destination')
            ->assertHasErrors(['s3Key', 's3Secret'])
            ->set('s3Key', 'AKIAEXAMPLE')->set('s3Secret', 'sEcReT/+=xyz')
            ->call('saveS3Destination')
            ->assertHasNoErrors()
            ->assertSet('s3Secret', '')
            ->assertSet('showS3Form', false)
            ->assertDontSee('sEcReT/+=xyz');

        $this->assertSame(['bucket' => 'acme-backups', 'key' => 'AKIAEXAMPLE', 'secret' => 'sEcReT/+=xyz', 'region' => 'fsn1', 'endpoint' => 'https://fsn1.your-objectstorage.com'], $this->sent('PUT', '/api/backups/config')->data());
    }

    #[Test]
    public function an_s3_bucket_is_added_its_fields_checked_first(): void
    {
        $page = $this->tab('backups')
            ->assertSeeHtml('data-backup-s3-add')
            ->call('openS3Form')
            ->assertSeeHtml('data-backup-s3-form="new"')
            ->set('s3Name', 's3')->set('s3Bucket', 'Bad Bucket')->set('s3Key', 'AK IA')->set('s3Secret', "x'y")->set('s3Endpoint', 'ftp://x')
            ->call('saveS3Destination')
            ->assertHasErrors(['s3Name', 's3Bucket', 's3Key', 's3Secret', 's3Endpoint']);
        $page->set('s3Provider', 'r2')->assertSet('s3Region', 'auto')
            ->set('s3Name', 'offsite')->set('s3Bucket', 'acme-offsite')->set('s3Key', 'AKIAOTHER')->set('s3Secret', 'dEsTsEcReT')
            ->call('saveS3Destination')
            ->assertHasErrors(['s3Endpoint']); // the <account-id> still to fill in
        $this->assertNull($this->sent('POST', '/api/backups/destinations'));

        $page->set('s3Endpoint', 'https://abc123.r2.cloudflarestorage.com')
            ->call('saveS3Destination')
            ->assertHasNoErrors()
            ->assertSet('s3Secret', '');
        $this->assertSame(['type' => 's3', 'name' => 'offsite', 'bucket' => 'acme-offsite', 'key' => 'AKIAOTHER', 'secret' => 'dEsTsEcReT', 'region' => 'auto', 'endpoint' => 'https://abc123.r2.cloudflarestorage.com'],
            $this->sent('POST', '/api/backups/destinations')->data());
    }

    #[Test]
    public function a_bucket_the_server_cannot_reach_is_said_and_the_secret_cleared(): void
    {
        $this->tab('backups')
            ->call('openS3Form')
            ->set('s3Name', 'offsite')->set('s3Bucket', 'refused')->set('s3Key', 'AKIA')->set('s3Secret', 'wrong')
            ->call('saveS3Destination')
            ->assertHasErrors(['s3Bucket'])
            ->assertSet('s3Secret', '')
            ->assertSet('showS3Form', true);
    }

    #[Test]
    public function an_edit_keeps_the_keys_left_empty_and_clears_an_emptied_endpoint(): void
    {
        $this->destinations[] = ['name' => 'offsite', 'type' => 's3', 'bucket' => 'acme-offsite', 'region' => 'eu-central-003', 'endpoint' => 'https://s3.eu-central-003.backblazeb2.com', 'default' => false, 'profiles' => []];

        $this->tab('backups')
            ->assertSeeHtml('data-backup-s3-edit')
            ->assertSeeHtml('data-backup-s3-remove')
            ->call('openS3Form', 'offsite')
            ->assertSet('s3Provider', 'b2')
            ->assertSet('s3Bucket', 'acme-offsite')
            ->assertSet('s3Key', '')
            ->set('s3Provider', 'aws')
            ->assertSet('s3Endpoint', '')
            ->call('saveS3Destination')
            ->assertHasNoErrors();

        $this->assertSame(['bucket' => 'acme-offsite', 'region' => 'eu-central-1', 'endpoint' => 'none'], $this->sent('PUT', '/api/backups/destinations/offsite')->data());
    }

    #[Test]
    public function an_s3_destination_is_tested_and_removed(): void
    {
        $this->destinations[] = ['name' => 'offsite', 'type' => 's3', 'bucket' => 'acme-offsite', 'region' => 'eu-central-1', 'endpoint' => null, 'default' => false, 'profiles' => []];

        $this->tab('backups')
            ->call('testBackupDestination', 'offsite')->assertDispatched('notify')
            ->call('deleteBackupDestination', 'offsite')->assertDispatched('notify')
            ->call('deleteBackupDestination', 's3');

        $this->assertSame(['name' => 'offsite'], $this->sent('POST', '/api/backups/destinations/test')->data());
        $this->assertNotNull($this->sent('DELETE', '/api/backups/destinations/offsite'));
        $this->assertNull($this->sent('DELETE', '/api/backups/destinations/s3'));
    }

    #[Test]
    public function the_encryption_key_is_shown_hidden_and_rotated(): void
    {
        $this->tab('backups')
            ->assertSeeHtml('data-backup-key-show')
            ->assertDontSee('kEy0123456789abcdefKEY')
            ->call('showBackupKey')
            ->assertSee('kEy0123456789abcdefKEY')
            ->assertSeeHtml('data-backup-key-hide')
            ->call('hideBackupKey')
            ->assertDontSee('kEy0123456789abcdefKEY')
            ->call('rotateBackupKey')
            ->assertDispatched('notify');

        $this->assertNotNull($this->sent('GET', '/api/backups/key'));
        $this->assertNotNull($this->sent('POST', '/api/backups/key/rotate'));
    }

    #[Test]
    public function the_retention_is_previewed_and_applied_as_a_job(): void
    {
        $this->tab('backups')->call('pruneBackups', true);
        $this->assertSame(['dry_run' => true], $this->sent('POST', '/api/backups/prune')->data());
    }

    #[Test]
    public function an_older_server_says_what_to_update(): void
    {
        $this->oldApi = true;

        $this->tab('backups')
            ->call('openS3Form')
            ->set('s3Name', 'offsite')->set('s3Bucket', 'acme-offsite')->set('s3Key', 'AKIA')->set('s3Secret', 'sEcReT')
            ->call('saveS3Destination')
            ->assertHasErrors(['s3Bucket'])
            ->assertSee('cipi self-update && cipi api update')
            ->assertSet('s3Secret', '');
    }

    #[Test]
    public function a_viewer_changes_nothing(): void
    {
        $viewer = $this->member();
        Access::replaceGrants($viewer->id, [$this->srv->id => 'view'], []);
        $this->actingAs($viewer);

        $this->tab('backups')
            ->assertDontSeeHtml('data-backup-s3-add')
            ->assertDontSeeHtml('data-backup-key-show')
            ->call('openS3Form')->assertSet('showS3Form', false)
            ->set('s3Name', 'x1')->set('s3Bucket', 'acme')->set('s3Key', 'k')->set('s3Secret', 's')
            ->call('saveS3Destination')
            ->call('configureLocalBackups')
            ->call('showBackupKey')->assertSet('backupKeyShown', null)
            ->call('rotateBackupKey')
            ->call('pruneBackups', false);
        $this->assertSame(0, Http::recorded(fn (Request $r) => $r->method() !== 'GET')->count());
        $this->assertNull($this->sent('GET', '/api/backups/key'));
    }

    #[Test]
    public function signed_releases_cannot_be_required_while_none_is_signed(): void
    {
        $this->tab('updates')
            ->assertSeeHtml('data-updates-unsigned')
            ->assertSee('not available yet')
            ->set('upVerify', true)
            ->assertDontSeeHtml('data-updates-first-trust')
            ->call('saveUpdatePolicy')
            ->assertSet('upVerify', false)
            ->assertSeeHtml('data-updates-refused');

        $this->assertNull($this->sent('PUT', '/api/self-update/policy'));
    }
}
