<?php

namespace CipiGui\Tests\Feature;

use CipiGui\Http\Controllers\DbUploadController;
use CipiGui\Livewire\AppDetail;
use CipiGui\Livewire\Apps;
use CipiGui\Livewire\Databases;
use CipiGui\Livewire\ServerManage;
use CipiGui\Models\CipiServer;
use CipiGui\Services\Access;
use CipiGui\Support\DeployLog;
use CipiGui\Support\Presets;
use CipiGui\Support\Theme;
use CipiGui\Tests\TestCase;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;

/**
 * 4.2.0 — what the panel gained with API 1.39 / Cipi 5.9.0: the last deploy of
 * every app, deploy durations from the rotated logs, databases linked to
 * apps, dump upload, Node runtimes, backup profiles; and what changed in the
 * panel itself: its own confirmation dialog, editable saved commands, the
 * Server page sent before the server is asked.
 */
class Release420Test extends TestCase
{
    private CipiServer $server;

    /** Paths this test wants a 404 for, as an older API would answer. */
    private array $missing = [];

    private const UPLOAD = '0b3e4a52-9f6c-4d1e-8a77-2c5d9e1f0a34';

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake(function (Request $request) {
            $path = (string) parse_url($request->url(), PHP_URL_PATH);
            $method = $request->method();
            if (in_array($path, $this->missing, true)) {
                return Http::response(['message' => 'Not Found'], 404);
            }

            return match (true) {
                $path === '/api/apps' => Http::response(['data' => [
                    ['app' => 'shop', 'domain' => 'shop.example.com', 'php' => '8.5', 'engine' => 'mariadb', 'node' => false, 'aliases' => [], 'repository' => 'git@github.com:acme/shop.git', 'branch' => 'main', 'databases' => []],
                    ['app' => 'blog', 'domain' => 'blog.example.com', 'php' => '8.5', 'engine' => null, 'node' => false, 'custom' => true, 'aliases' => [], 'repository' => '', 'branch' => '', 'databases' => [['name' => 'wpblog', 'engine' => 'mariadb']]],
                ]]),
                $path === '/api/apps/shop' => Http::response(['data' => [
                    'app' => 'shop', 'domain' => 'shop.example.com', 'php' => '8.5', 'engine' => 'mariadb', 'node' => false, 'aliases' => [],
                    'repository' => 'git@github.com:acme/shop.git', 'branch' => 'main',
                ]]),
                $path === '/api/deploys/last' => Http::response(['data' => [
                    'shop' => ['result' => 'ok', 'kind' => 'deploy', 'release' => '31', 'duration' => 76, 'exit' => 0, 'trigger' => 'webhook', 'branch' => 'main', 'from_release' => '30', 'started_at' => '2026-11-15T09:00:00+0100', 'finished_at' => '2026-11-15T09:01:16+0100'],
                    'blog' => null,
                ]]),
                $path === '/api/apps/shop/deploy/audit' => Http::response(['data' => ['app' => 'shop', 'records' => [
                    ['seq' => 1, 'ts' => '2026-10-20T08:00:00Z', 'event' => 'published', 'release' => '28', 'origin' => 'webhook'],
                    ['seq' => 2, 'ts' => '2026-10-24T08:55:55Z', 'event' => 'published', 'release' => '29', 'origin' => 'webhook'],
                    ['seq' => 3, 'ts' => '2026-10-26T09:00:00Z', 'event' => 'published', 'release' => '31', 'origin' => 'webhook'],
                ]]]),
                // Newest first, as `cipi deploy --history` answers: every run still has its duration.
                $path === '/api/apps/shop/deploy/history' => Http::response(['data' => ['app' => 'shop', 'count' => 3, 'runs' => [
                    ['result' => 'ok', 'kind' => 'deploy', 'release' => '31', 'duration' => 76, 'exit' => 0, 'trigger' => 'webhook'],
                    ['result' => 'ok', 'kind' => 'deploy', 'release' => '29', 'duration' => 54, 'exit' => 0, 'trigger' => 'webhook'],
                    ['result' => 'ok', 'kind' => 'deploy', 'release' => '28', 'duration' => 41, 'exit' => 0, 'trigger' => 'webhook'],
                ]]]),
                $path === '/api/apps/shop/logs' => Http::response(['data' => ['available_types' => ['deploy'], 'files' => [['path' => '/var/log/cipi-apps/shop/deploy.log', 'lines' => []]], 'warnings' => []]]),
                $path === '/api/dbs/engines' => Http::response(['data' => ['default' => 'mariadb', 'engines' => [['engine' => 'mariadb', 'status' => 'running', 'port' => 3306, 'default' => true]]]]),
                $path === '/api/dbs/uploads/limits' => Http::response(['data' => ['max_size' => 2147483648, 'chunk_size' => 8388608, 'extensions' => ['.sql', '.sql.gz']]]),
                $path === '/api/dbs/uploads' && $method === 'POST' => Http::response(['data' => ['upload_id' => self::UPLOAD, 'size' => 1000, 'chunk_size' => 8388608, 'chunks' => 1, 'max_size' => 2147483648]], 201),
                $path === '/api/dbs/uploads/'.self::UPLOAD.'/chunks' => Http::response(['data' => ['upload_id' => self::UPLOAD, 'received' => 1000, 'size' => 1000, 'next' => 1, 'complete' => true]]),
                $path === '/api/dbs/wpblog/link' && $method === 'POST' => Http::response(['data' => ['database' => 'wpblog', 'app' => 'shop', 'linked' => true, 'message' => 'linked']]),
                $path === '/api/dbs/wpblog/link' && $method === 'DELETE' => Http::response(['data' => ['database' => 'wpblog', 'app' => null, 'linked' => false, 'message' => 'unlinked']]),
                $path === '/api/dbs/wpblog/restore' => Http::response(['job_id' => 'job-restore', 'status' => 'pending'], 202),
                $path === '/api/dbs' && $method === 'POST' => Http::response(['job_id' => 'job-create', 'status' => 'pending'], 202),
                $path === '/api/dbs' => Http::response(['data' => [
                    ['engine' => 'mariadb', 'name' => 'shop', 'user' => 'shop', 'size' => '842 MB', 'size_mb' => 842, 'app' => 'shop', 'link' => 'own'],
                    ['engine' => 'mariadb', 'name' => 'wpblog', 'user' => 'wpblog', 'size' => '96 MB', 'size_mb' => 96, 'app' => 'blog', 'link' => 'linked'],
                    ['engine' => 'mariadb', 'name' => 'standalone', 'user' => 'standalone', 'size' => '1 MB', 'size_mb' => 1, 'app' => null, 'link' => null],
                ]]),
                $path === '/api/node' => Http::response(['data' => [
                    ['major' => '22', 'version' => '22.23.4', 'default' => true, 'apps' => ['front']],
                    ['major' => '24', 'version' => '24.22.1', 'default' => false, 'apps' => []],
                    ['major' => 'system', 'version' => '20.19.0', 'default' => false, 'apps' => []],
                ]]),
                str_starts_with($path, '/api/node/') => Http::response(['job_id' => 'job-node', 'status' => 'pending'], 202),
                $path === '/api/backups' => Http::response(['data' => $this->backupStatus()]),
                $path === '/api/backups/run' => Http::response(['data' => ['started' => true, 'profile' => 'fast-db']], 202),
                $path === '/api/backups/profiles' && $method === 'POST' => Http::response(['data' => ['profile' => 'weekly', 'saved' => true]], 201),
                $path === '/api/status' => Http::response(['data' => ['system' => ['hostname' => 'fra1', 'cipi' => '5.9.0'], 'resources' => [], 'services' => ['nginx' => 'running'], 'apps' => 2]]),
                default => Http::response(['data' => []]),
            };
        });

        $this->server = $this->server();
        $this->actingAs($this->admin());
        session(['cipi_gui_server_id' => $this->server->id]);
    }

    /** @return array<string, mixed> */
    private function backupStatus(): array
    {
        $now = time();

        return [
            'configured' => true, 'local_dir' => '/var/backups/cipi', 'encryption_key' => true,
            'destinations' => [
                ['name' => 'local', 'type' => 'local', 'path' => '/var/backups/cipi', 'default' => false, 'profiles' => ['default']],
                ['name' => 's3', 'type' => 's3', 'bucket' => 'acme-backups', 'region' => 'eu-central-1', 'endpoint' => null, 'default' => true, 'profiles' => ['default', 'fast-db']],
            ],
            'profiles' => [
                ['name' => 'default', 'scope' => 'all', 'enabled' => true, 'cron' => '0 2 * * *', 'interval_seconds' => 86400, 'apps' => ['*'], 'databases' => ['*'], 'exclude_databases' => ['tmp_*'], 'exclude_tables' => [],
                    'destinations' => ['s3', 'local'], 'retention' => ['keep' => 0, 'days' => 28, 'weeks' => 0], 'encrypt' => false, 'created_at' => $now - 9000000,
                    'covers' => ['apps' => ['blog', 'shop'], 'databases' => [['engine' => 'mariadb', 'name' => 'shop'], ['engine' => 'mariadb', 'name' => 'wpblog']]],
                    'last' => ['status' => 'ok', 'detail' => '2 app(s), 2 database(s)', 'started_at' => '2026-11-16 02:00:00', 'finished_at' => '2026-11-16 02:03:10', 'epoch' => $now - 3600, 'last_success_epoch' => $now - 3600],
                    'overdue' => false, 'running' => false],
                ['name' => 'fast-db', 'scope' => 'db', 'enabled' => true, 'cron' => '*/30 * * * *', 'interval_seconds' => 1800, 'apps' => [], 'databases' => ['shop'], 'exclude_databases' => [], 'exclude_tables' => ['*.jobs'],
                    'destinations' => ['s3'], 'retention' => ['keep' => 48, 'days' => 0, 'weeks' => 0], 'encrypt' => true, 'created_at' => $now - 9000000,
                    'covers' => ['apps' => [], 'databases' => [['engine' => 'mariadb', 'name' => 'shop']]],
                    'last' => ['status' => 'error', 'detail' => 'dest:s3', 'started_at' => 'a', 'finished_at' => 'b', 'epoch' => $now - 7200, 'last_success_epoch' => $now - 90000],
                    'overdue' => true, 'running' => false],
            ],
            'apps' => ['blog', 'shop'],
            'databases' => [['engine' => 'mariadb', 'name' => 'shop'], ['engine' => 'mariadb', 'name' => 'tmp_scratch'], ['engine' => 'mariadb', 'name' => 'wpblog']],
            'uncovered' => ['apps' => [], 'databases' => [['engine' => 'mariadb', 'name' => 'tmp_scratch']]],
        ];
    }

    private function sent(string $method, string $path): int
    {
        return Http::recorded(fn (Request $r) => $r->method() === $method && parse_url($r->url(), PHP_URL_PATH) === $path)->count();
    }

    // ── the apps list ─────────────────────────────────────────────────

    #[Test]
    public function the_apps_list_shows_when_each_app_was_last_deployed_once_the_list_is_on_the_page(): void
    {
        $page = Livewire::test(Apps::class)->call('loadApps')
            ->assertSee('shop.example.com')
            ->assertSeeHtml('wire:init="loadLastDeploys"')   // asked after the list, not with it
            ->assertSet('deploysLoaded', false);
        $this->assertSame(0, $this->sent('GET', '/api/deploys/last'));

        $page->call('loadLastDeploys')
            ->assertSet('deploysSupported', true)
            ->assertSeeHtml('data-last-deploy')
            ->assertSee('Last deploy')
            ->assertSee('never')                                            // blog was never deployed
            ->assertSeeHtml('Deploy OK · release 31 · took 1m16s · by a Git push')
            ->assertDontSeeHtml('wire:init="loadLastDeploys"');
        $this->assertSame(1, $this->sent('GET', '/api/deploys/last'));
    }

    #[Test]
    public function a_server_without_last_deploys_has_no_such_column_and_keeps_its_connection_state(): void
    {
        $this->missing = ['/api/deploys/last'];

        Livewire::test(Apps::class)->call('loadApps')->call('loadLastDeploys')
            ->assertSet('deploysSupported', false)
            ->assertSee('shop.example.com')
            ->assertDontSeeHtml('data-last-deploy');
        $this->assertNull($this->server->fresh()->last_error);
    }

    #[Test]
    public function a_database_linked_to_a_custom_app_shows_in_the_apps_list(): void
    {
        Livewire::test(Apps::class)->call('loadApps')
            ->assertSeeHtml('data-linked-dbs')
            ->assertSee('wpblog');
    }

    // ── deploy durations ──────────────────────────────────────────────

    #[Test]
    public function deploy_durations_come_from_the_history_which_reads_the_rotated_logs(): void
    {
        // deploy.log is empty — the morning after the nightly rotation — and every deploy still has its time.
        Livewire::test(AppDetail::class, ['name' => 'shop'])->call('setTab', 'deploy')
            ->assertSet('deployHistoryUnsupported', false)
            ->assertSet('auditTook', [0 => 76, 1 => 54, 2 => 41])
            ->assertSeeInOrder(['31', '1m16s', '29', '54s', '28', '41s']);

        $this->assertSame(1, $this->sent('GET', '/api/apps/shop/deploy/history'));
        $this->assertSame(0, $this->sent('GET', '/api/apps/shop/logs'));
    }

    #[Test]
    public function an_older_server_still_gets_its_durations_from_the_deploy_log(): void
    {
        $this->missing = ['/api/apps/shop/deploy/history'];

        Livewire::test(AppDetail::class, ['name' => 'shop'])->call('setTab', 'deploy')
            ->assertSet('deployHistoryUnsupported', true)
            ->assertSet('auditTook', []);
        $this->assertSame(1, $this->sent('GET', '/api/apps/shop/logs'));
    }

    #[Test]
    public function history_runs_become_the_banners_the_ledger_is_matched_with(): void
    {
        $banners = DeployLog::bannersFromRuns([
            ['result' => 'ok', 'kind' => 'rollback', 'release' => '11', 'duration' => 9],
            ['result' => 'failed', 'kind' => 'deploy', 'release' => '11', 'duration' => 70],
            ['result' => 'ok', 'kind' => 'deploy', 'release' => '11', 'duration' => 41],
            ['result' => 'ok', 'kind' => 'deploy', 'release' => null, 'duration' => 5],     // no release: not a banner
            ['result' => 'ok', 'kind' => 'deploy', 'release' => '10'],                      // no duration: not a banner
            'not a run',
        ]);

        $this->assertSame([
            ['event' => 'published', 'release' => '11', 'seconds' => 41],
            ['event' => 'failed', 'release' => '11', 'seconds' => 70],
            ['event' => 'rollback', 'release' => '11', 'seconds' => 9],
        ], $banners);
    }

    // ── the overview asks its answers together ────────────────────────

    #[Test]
    public function the_app_page_asks_each_answer_once_and_together(): void
    {
        Livewire::test(AppDetail::class, ['name' => 'shop'])->assertSee('shop.example.com');

        foreach (['/api/apps/shop', '/api/php', '/api/node', '/api/apps/shop/health', '/api/search', '/api/apps/shop/deploy/status'] as $path) {
            $this->assertSame(1, $this->sent('GET', $path), $path.' is asked once');
        }
    }

    // ── databases ─────────────────────────────────────────────────────

    #[Test]
    public function the_databases_page_shows_the_app_of_each_database_and_links_one(): void
    {
        $page = Livewire::test(Databases::class)->call('loadDatabases')
            ->assertSeeHtml('data-db-app')
            ->assertSee('linked')
            ->call('openLink', 'wpblog', 'mariadb', $this->server->id)
            ->assertSet('linkName', 'wpblog')->assertSet('linkApp', 'blog')
            ->assertSee('Remove the link');

        // An app that is not one of the server's is refused before anything is sent.
        $page->set('linkApp', 'ghost')->call('saveLink')->assertHasErrors('linkApp');
        $this->assertSame(0, $this->sent('POST', '/api/dbs/wpblog/link'));

        $page->set('linkApp', 'shop')->call('saveLink')->assertSet('linkName', null);
        Http::assertSent(fn (Request $r) => $r->method() === 'POST' && str_ends_with((string) parse_url($r->url(), PHP_URL_PATH), '/api/dbs/wpblog/link') && $r['app'] === 'shop' && $r['engine'] === 'mariadb');

        $page->call('unlink', 'wpblog', 'mariadb', $this->server->id);
        $this->assertSame(1, $this->sent('DELETE', '/api/dbs/wpblog/link'));
    }

    #[Test]
    public function a_database_is_created_for_an_app(): void
    {
        Livewire::test(Databases::class)->call('loadDatabases')->call('openCreate')
            ->assertSee('Belongs to app')
            ->set('dbName', 'wpshop')->set('dbApp', 'blog')
            ->call('createDatabase')->assertHasNoErrors();

        Http::assertSent(fn (Request $r) => $r->method() === 'POST' && str_ends_with((string) parse_url($r->url(), PHP_URL_PATH), '/api/dbs') && $r['name'] === 'wpshop' && $r['app'] === 'blog');
    }

    #[Test]
    public function an_app_that_was_not_offered_is_not_sent_with_a_new_database(): void
    {
        Livewire::test(Databases::class)->call('loadDatabases')->call('openCreate')
            ->set('dbName', 'wpshop')->set('dbApp', 'ghost')
            ->call('createDatabase');

        $this->assertSame(0, $this->sent('POST', '/api/dbs'));
    }

    // ── dump upload ───────────────────────────────────────────────────

    #[Test]
    public function a_dump_goes_to_the_server_in_chunks_through_the_panel(): void
    {
        $this->postJson(route('cipi-gui.db-uploads.start'), ['server' => $this->server->id, 'filename' => 'shop.sql.gz', 'size' => 1000])
            ->assertOk()->assertJsonPath('data.upload_id', self::UPLOAD)
            // never more per request than the panel takes, whatever the server asks for
            ->assertJsonPath('data.chunk_size', min(8388608, DbUploadController::panelChunkBytes()));

        $this->post(route('cipi-gui.db-uploads.chunk', ['id' => self::UPLOAD]), [
            'server' => $this->server->id, 'index' => 0, 'chunk' => UploadedFile::fake()->createWithContent('chunk.bin', str_repeat('x', 1000)),
        ], ['Accept' => 'application/json'])->assertOk()->assertJsonPath('data.complete', true);

        Http::assertSent(fn (Request $r) => str_ends_with((string) parse_url($r->url(), PHP_URL_PATH), '/api/dbs/uploads/'.self::UPLOAD.'/chunks') && $r->isMultipart());

        $this->deleteJson(route('cipi-gui.db-uploads.cancel', ['id' => self::UPLOAD]).'?server='.$this->server->id)->assertOk();
        $this->assertSame(1, $this->sent('DELETE', '/api/dbs/uploads/'.self::UPLOAD));
    }

    #[Test]
    public function only_who_manages_the_server_uploads_a_dump_to_it(): void
    {
        $member = $this->member();
        Access::replaceGrants($member->id, [$this->server->id => 'view'], []);
        $this->actingAs($member);

        $this->postJson(route('cipi-gui.db-uploads.start'), ['server' => $this->server->id, 'filename' => 'shop.sql.gz', 'size' => 1000])->assertForbidden();
        $this->post(route('cipi-gui.db-uploads.chunk', ['id' => self::UPLOAD]), [
            'server' => $this->server->id, 'index' => 0, 'chunk' => UploadedFile::fake()->createWithContent('chunk.bin', 'x'),
        ], ['Accept' => 'application/json'])->assertForbidden();
        $this->assertSame(0, $this->sent('POST', '/api/dbs/uploads'));
    }

    #[Test]
    public function the_restore_dialog_offers_the_upload_and_restores_from_it_after_the_same_confirmation(): void
    {
        $page = Livewire::test(Databases::class)->call('loadDatabases')
            ->call('openRestore', 'wpblog', 'mariadb', $this->server->id)
            ->assertSee('Upload a dump')
            ->call('setRestoreMode', 'upload')->assertSet('restoreMode', 'upload')
            ->assertSee('up to 2 GB')
            ->assertSeeHtml('x-data="cipiDumpUpload(');

        $page->call('restoreFromUpload', self::UPLOAD)->assertHasErrors('restoreConfirmation');
        $page->call('restoreFromUpload', '../../etc/passwd')->assertHasErrors('restoreUpload');
        $this->assertSame(0, $this->sent('POST', '/api/dbs/wpblog/restore'));

        $page->set('restoreConfirmation', 'wpblog')->call('restoreFromUpload', self::UPLOAD)->assertSet('restoreName', null);
        Http::assertSent(fn (Request $r) => str_ends_with((string) parse_url($r->url(), PHP_URL_PATH), '/api/dbs/wpblog/restore') && $r['upload_id'] === self::UPLOAD && ! isset($r['file']));
    }

    #[Test]
    public function a_server_without_uploads_keeps_the_restore_dialog_it_had(): void
    {
        $this->missing = ['/api/dbs/uploads/limits'];

        Livewire::test(Databases::class)->call('loadDatabases')
            ->call('openRestore', 'wpblog', 'mariadb', $this->server->id)
            ->assertDontSee('Upload a dump')
            ->assertSee('Dump file on the server')
            ->call('setRestoreMode', 'upload')->assertSet('restoreMode', 'file');
    }

    #[Test]
    public function php_upload_limits_are_read_as_bytes(): void
    {
        $this->assertSame(67108864, DbUploadController::iniBytes('64M'));
        $this->assertSame(2147483648, DbUploadController::iniBytes('2G'));
        $this->assertSame(8388608, DbUploadController::iniBytes('8388608'));
        $this->assertSame(0, DbUploadController::iniBytes('-1'));
        $this->assertSame('2 GB', DbUploadController::humanBytes(2147483648));
        $this->assertSame('500 MB', DbUploadController::humanBytes(524288000));
    }

    // ── the Server page ───────────────────────────────────────────────

    #[Test]
    public function node_runtimes_are_installed_upgraded_and_removed_from_the_node_tab(): void
    {
        $page = Livewire::withQueryParams(['tab' => 'node'])->test(ServerManage::class, ['serverId' => $this->server->id])->call('init')
            ->assertSee('Install a Node major')->assertSee('System Node')
            ->assertSet('nodeInstallMajor', '26');                       // 22 and 24 are installed

        $page->call('installNode');
        Http::assertSent(fn (Request $r) => $r->method() === 'POST' && str_ends_with((string) parse_url($r->url(), PHP_URL_PATH), '/api/node/install') && $r['major'] === '26');

        $page->call('dismissJob')->call('upgradeNode', '24');
        Http::assertSent(fn (Request $r) => str_ends_with((string) parse_url($r->url(), PHP_URL_PATH), '/api/node/upgrade') && $r['major'] === '24' && $r['restart'] === true);

        $page->call('dismissJob')->call('setNodeDefault', 'system');
        Http::assertSent(fn (Request $r) => $r->method() === 'PUT' && str_ends_with((string) parse_url($r->url(), PHP_URL_PATH), '/api/node/default') && $r['major'] === 'system');

        // A major the page does not list is never sent.
        $page->call('dismissJob')->call('removeNode', '18')->call('setNodeDefault', '18');
        $this->assertSame(0, $this->sent('DELETE', '/api/node/18'));
        $this->assertSame(1, $this->sent('PUT', '/api/node/default'));
    }

    #[Test]
    public function the_backups_tab_is_a_matrix_of_profiles_apps_and_databases(): void
    {
        $page = Livewire::withQueryParams(['tab' => 'backups'])->test(ServerManage::class, ['serverId' => $this->server->id])->call('init')
            ->assertSee('What is backed up, by which profile')
            ->assertSeeHtml('data-backup-profile="default"')->assertSeeHtml('data-backup-profile="fast-db"')
            ->assertSeeHtml('data-backup-state="ok"')->assertSeeHtml('data-backup-state="overdue"')
            ->assertSee('every 30 min')->assertSee('daily')->assertSee('28 days')->assertSee('48 runs')
            ->assertSee('tmp_scratch')->assertSee('not backed up')
            ->assertSee('acme-backups')
            ->assertDontSee('aws_secret');

        $page->call('runBackup', 'fast-db');
        Http::assertSent(fn (Request $r) => str_ends_with((string) parse_url($r->url(), PHP_URL_PATH), '/api/backups/run') && $r['profile'] === 'fast-db');

        // A profile the page does not list is never sent.
        $page->call('runBackup', 'ghost')->call('deleteBackupProfile', 'ghost');
        $this->assertSame(1, $this->sent('POST', '/api/backups/run'));
        $this->assertSame(0, $this->sent('DELETE', '/api/backups/profiles/ghost'));
    }

    #[Test]
    public function a_backup_profile_is_created_from_the_form(): void
    {
        $page = Livewire::withQueryParams(['tab' => 'backups'])->test(ServerManage::class, ['serverId' => $this->server->id])->call('init')
            ->call('openBackupForm', '')->assertSet('showBackupForm', true)->assertSet('bpDestinations', ['s3']);

        $page->set('bpName', 'default')->call('saveBackupProfile')->assertHasErrors('bpName');                      // the name is taken
        $page->set('bpName', 'weekly')->set('bpKeepDays', '0')->call('saveBackupProfile')->assertHasErrors('bpKeepDays');   // keeps nothing
        $page->set('bpApps', 'shop; rm -rf /')->set('bpKeepDays', '28')->call('saveBackupProfile')->assertHasErrors('bpApps');
        $this->assertSame(0, $this->sent('POST', '/api/backups/profiles'));

        $page->set('bpApps', 'shop, blog')->set('bpScope', 'files')->set('bpEvery', '6h')->set('bpKeep', '8')->set('bpKeepDays', '0')
            ->call('saveBackupProfile')->assertSet('showBackupForm', false);

        Http::assertSent(fn (Request $r) => $r->method() === 'POST' && str_ends_with((string) parse_url($r->url(), PHP_URL_PATH), '/api/backups/profiles')
            && $r['name'] === 'weekly' && $r['scope'] === 'files' && $r['apps'] === ['shop', 'blog'] && ! isset($r['databases'])
            && $r['every'] === '6h' && $r['destinations'] === ['s3'] && $r['keep'] === 8 && $r['keep_days'] === 0);
    }

    #[Test]
    public function a_user_who_only_views_the_server_sees_the_backups_and_changes_nothing(): void
    {
        $member = $this->member();
        Access::replaceGrants($member->id, [$this->server->id => 'view'], []);
        $this->actingAs($member);

        Livewire::withQueryParams(['tab' => 'backups'])->test(ServerManage::class, ['serverId' => $this->server->id])->call('init')
            ->assertSee('fast-db')->assertDontSee('New profile')
            ->call('runBackup', 'fast-db')->call('openBackupForm', '')->assertSet('showBackupForm', false);
        $this->assertSame(0, $this->sent('POST', '/api/backups/run'));
    }

    #[Test]
    public function the_backups_tab_says_what_an_older_server_needs(): void
    {
        $this->missing = ['/api/backups'];

        Livewire::withQueryParams(['tab' => 'backups'])->test(ServerManage::class, ['serverId' => $this->server->id])->call('init')
            ->assertSee('Not available on this server')->assertSee('Cipi 5.9.0+');
    }

    // ── saved commands ────────────────────────────────────────────────

    #[Test]
    public function admins_edit_the_saved_artisan_commands_the_defaults_included(): void
    {
        $page = Livewire::test(AppDetail::class, ['name' => 'shop'])->call('setTab', 'artisan')
            ->assertSee('migrate:status')->assertSee('Edit list')
            ->call('editPresets', 'artisan')->assertSet('presetEditing', 'artisan')
            ->set('presetDraft', 'php artisan horizon:terminate')->call('addPreset', 'artisan')
            ->assertHasNoErrors()->assertSee('horizon:terminate');
        $this->assertContains('horizon:terminate', Presets::get(Presets::ARTISAN));

        $page->set('presetDraft', 'horizon:terminate')->call('addPreset', 'artisan')->assertHasErrors('presetDraft');   // already there

        $page->call('removePreset', 'artisan', 'storage:link');                                                          // a default
        $this->assertNotContains('storage:link', Presets::get(Presets::ARTISAN));
        $this->assertTrue(Presets::isCustom(Presets::ARTISAN));

        $page->call('resetPresets', 'artisan');
        $this->assertSame(Presets::DEFAULTS[Presets::ARTISAN], Presets::get(Presets::ARTISAN));
        $this->assertFalse(Presets::isCustom(Presets::ARTISAN));
    }

    #[Test]
    public function a_user_who_is_not_an_admin_uses_the_saved_commands_and_cannot_change_them(): void
    {
        $member = $this->member();
        Access::replaceGrants($member->id, [], [$this->server->id => ['shop' => ['view', 'run']]]);
        $this->actingAs($member);

        Livewire::test(AppDetail::class, ['name' => 'shop'])->call('setTab', 'artisan')
            ->assertSee('migrate:status')->assertDontSee('Edit list')
            ->call('editPresets', 'artisan')->assertSet('presetEditing', '')
            ->set('presetDraft', 'down')->call('addPreset', 'artisan')
            ->call('removePreset', 'artisan', 'about');

        $this->assertSame(Presets::DEFAULTS[Presets::ARTISAN], Presets::get(Presets::ARTISAN));
    }

    #[Test]
    public function a_saved_command_is_one_line_within_the_limits(): void
    {
        $this->assertSame('migrate --force', Presets::normalize(Presets::ARTISAN, "  php   artisan  migrate   --force "));
        $this->assertSame('php artisan x', Presets::normalize(Presets::RUN, 'php artisan x'));
        $this->assertNotNull(Presets::error(Presets::ARTISAN, ''));
        $this->assertNotNull(Presets::error(Presets::ARTISAN, str_repeat('a', Presets::MAX_LENGTH + 1)));
        $this->assertNotNull(Presets::error(Presets::ARTISAN, 'about'));          // a default: already in the list
        $this->assertNull(Presets::error(Presets::ARTISAN, 'horizon:status'));
        $this->assertNotNull(Presets::error('nope', 'x'));
    }

    // ── the panel's own confirmation dialog ───────────────────────────

    #[Test]
    public function confirmations_use_the_panels_dialog_not_the_browsers(): void
    {
        $this->get('/databases')->assertOk()
            ->assertSeeHtml('id="cipi-confirm"')
            ->assertSeeHtml('data-cipi-confirm-script');

        Livewire::test(Databases::class)->call('loadDatabases')
            ->assertSeeHtml('data-confirm="Generate a new password for wpblog?')
            ->assertDontSeeHtml('wire:confirm');

        foreach (glob(Theme::packageRoot().'/resources/views/{livewire,partials,components}/*.blade.php', GLOB_BRACE) as $view) {
            if (! str_ends_with($view, 'confirm-dialog.blade.php')) {
                $this->assertStringNotContainsString('wire:confirm', (string) file_get_contents($view), basename($view).' still opens the browser\'s confirm()');
            }
        }
    }

    #[Test]
    public function the_version_is_4_2_0(): void
    {
        $this->assertSame('4.2.0', Theme::VERSION);
        $this->assertStringContainsString('## [4.2.0]', file_get_contents(Theme::packageRoot().'/CHANGELOG.md'));
    }
}
