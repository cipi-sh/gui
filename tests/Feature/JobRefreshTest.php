<?php

namespace CipiGui\Tests\Feature;

use CipiGui\Livewire\AppDetail;
use CipiGui\Livewire\Apps;
use CipiGui\Livewire\Databases;
use CipiGui\Livewire\LogViewer;
use CipiGui\Livewire\ServerManage;
use CipiGui\Models\CipiServer;
use CipiGui\Tests\TestCase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;

/**
 * When a job finishes — completed or failed, with the overlay open or closed
 * with "Run in background" — the component that started it reloads what it shows.
 */
class JobRefreshTest extends TestCase
{
    /** @var array<string, mixed> Answer of GET /api/jobs/job-1 next to id and type */
    private array $job = ['status' => 'completed', 'exit_code' => 0, 'output' => 'done', 'result' => []];

    private CipiServer $server;

    protected function setUp(): void
    {
        parent::setUp();

        $app = ['app' => 'shop', 'domain' => 'shop.example.com', 'php' => '8.5', 'engine' => 'mariadb', 'node' => false, 'aliases' => [], 'repository' => 'git@github.com:acme/shop.git', 'branch' => 'main'];

        Http::fake(function (Request $request) use ($app) {
            $path = parse_url($request->url(), PHP_URL_PATH);

            return match (true) {
                $request->method() === 'POST' => Http::response(['job_id' => 'job-1', 'status' => 'pending'], 202),
                str_starts_with($path, '/api/jobs/') => Http::response(['data' => ['id' => 'job-1', 'type' => 'app-deploy'] + $this->job]),
                $path === '/api/apps' => Http::response(['data' => [$app]]),
                $path === '/api/apps/shop' => Http::response(['data' => $app]),
                $path === '/api/apps/shop/deploy/audit' => Http::response(['data' => ['app' => 'shop', 'records' => [['seq' => 1, 'ts' => '2026-10-27T08:00:00Z', 'event' => 'published', 'release' => '4', 'origin' => 'panel']]]]),
                $path === '/api/apps/shop/logs' => Http::response(['data' => ['available_types' => ['deploy'], 'files' => [['path' => '/home/shop/logs/deploy.log', 'lines' => ['deploy OK']]], 'warnings' => []]]),
                $path === '/api/dbs' => Http::response(['data' => [['engine' => 'mariadb', 'name' => 'shop', 'user' => 'shop', 'size' => '1 MB']]]),
                $path === '/api/dbs/engines' => Http::response(['data' => ['default' => 'mariadb', 'engines' => [['engine' => 'mariadb', 'status' => 'running', 'default' => true]]]]),
                $path === '/api/php' => Http::response(['data' => ['default' => '8.5', 'installable' => ['8.3', '8.4', '8.5'], 'versions' => [['version' => '8.5']]]]),
                default => Http::response(['data' => []]),
            };
        });

        $this->server = $this->server();
        $this->actingAs($this->admin());
        session(['cipi_gui_server_id' => $this->server->id]);
    }

    private function reads(string $path): int
    {
        return Http::recorded(fn (Request $r) => $r->method() === 'GET' && parse_url($r->url(), PHP_URL_PATH) === $path)->count();
    }

    #[Test]
    public function a_job_closed_with_run_in_background_is_polled_on_and_the_list_reloads_when_it_finishes(): void
    {
        $component = Livewire::test(Apps::class)->call('loadApps')->call('deploy', 'shop', $this->server->id)
            ->assertSet('showJobOverlay', true)->assertSet('jobRunning', true)
            ->call('dismissJob')
            ->assertSet('showJobOverlay', false)
            ->assertSet('jobRunning', true)
            ->assertSet('activeJobId', 'job-1')
            ->assertSeeHtml('wire:poll.1500ms="pollJob"');

        $reads = $this->reads('/api/apps');

        $component->call('pollJob')
            ->assertSet('jobRunning', false)
            ->assertSet('activeJobStatus', 'completed')
            ->assertSet('showJobOverlay', false) // a plain success: the toast is enough
            ->assertDispatched('notify')
            ->assertDontSeeHtml('wire:poll');

        $this->assertSame($reads + 1, $this->reads('/api/apps'), 'the list reloads when the job finishes');

        // Closing a finished job forgets it.
        $component->call('dismissJob')->assertSet('activeJobId', null);
    }

    #[Test]
    public function a_job_that_fails_in_the_background_comes_back_to_the_front(): void
    {
        $this->job = ['status' => 'failed', 'exit_code' => 1, 'output' => '[ERROR] Permission denied (publickey).', 'result' => ['error' => 'Permission denied (publickey).']];

        Livewire::test(Apps::class)->call('loadApps')->call('deploy', 'shop', $this->server->id)->call('dismissJob')
            ->assertSet('showJobOverlay', false)
            ->call('pollJob')
            ->assertSet('activeJobStatus', 'failed')
            ->assertSet('showJobOverlay', true)
            ->assertSee('The job failed');
    }

    #[Test]
    public function one_time_credentials_bring_a_background_job_back_to_the_front(): void
    {
        $this->job = ['status' => 'completed', 'exit_code' => 0, 'output' => 'ok', 'result' => ['name' => 'reports', 'user' => 'reports', 'password' => 's3cret-pass']];

        Livewire::test(Databases::class)->call('loadDatabases')->call('openCreate')->set('dbName', 'reports')->call('createDatabase')->assertHasNoErrors()
            ->call('dismissJob')->assertSet('showJobOverlay', false)
            ->call('pollJob')
            ->assertSet('showJobOverlay', true)
            ->assertSee('Save these credentials now');
    }

    #[Test]
    public function a_failed_job_reloads_the_apps_list(): void
    {
        $this->job = ['status' => 'failed', 'exit_code' => 1, 'output' => '[ERROR] rm: cannot remove', 'result' => ['error' => 'rm: cannot remove']];

        $component = Livewire::test(Apps::class)->call('loadApps')->call('deploy', 'shop', $this->server->id);
        $reads = $this->reads('/api/apps');

        $component->call('pollJob')->assertSet('activeJobStatus', 'failed');

        $this->assertSame($reads + 1, $this->reads('/api/apps'));
    }

    #[Test]
    public function a_failed_job_reloads_the_databases_list_and_the_server_tab(): void
    {
        $this->job = ['status' => 'failed', 'exit_code' => 1, 'output' => '[ERROR] failed', 'result' => ['error' => 'failed']];

        $databases = Livewire::test(Databases::class)->call('loadDatabases')->call('backup', 'shop', 'mariadb', $this->server->id);
        $reads = $this->reads('/api/dbs');
        $databases->call('pollJob')->assertSet('activeJobStatus', 'failed')->assertSet('pendingBackup', null);
        $this->assertSame($reads + 1, $this->reads('/api/dbs'));

        $server = Livewire::withQueryParams(['tab' => 'php'])->test(ServerManage::class, ['serverId' => $this->server->id])->assertSet('phpInstallVersion', '8.3')->call('installPhp');
        $reads = $this->reads('/api/php');
        $server->call('pollJob')->assertSet('activeJobStatus', 'failed');
        $this->assertSame($reads + 1, $this->reads('/api/php'));
    }

    #[Test]
    public function a_deploy_from_another_tab_invalidates_the_deploy_ledger(): void
    {
        $component = Livewire::test(AppDetail::class, ['name' => 'shop'])
            ->call('setTab', 'deploy')->assertSet('auditLoaded', true)
            ->call('setTab', 'overview');
        $reads = $this->reads('/api/apps/shop/deploy/audit');

        $component->call('deploy')->call('pollJob')
            ->assertSet('activeJobStatus', 'completed')
            ->assertSet('auditLoaded', false)
            ->assertDispatched('app-job-finished');
        $this->assertSame($reads, $this->reads('/api/apps/shop/deploy/audit'), 'not read while another tab is open');

        $component->call('setTab', 'deploy')->assertSet('auditLoaded', true);
        $this->assertSame($reads + 1, $this->reads('/api/apps/shop/deploy/audit'), 'read again when the tab is opened');
    }

    #[Test]
    public function a_failed_deploy_on_the_deploy_tab_reloads_the_ledger(): void
    {
        $this->job = ['status' => 'failed', 'exit_code' => 1, 'output' => '[ERROR] Permission denied (publickey).', 'result' => ['error' => 'Permission denied (publickey).']];

        $component = Livewire::test(AppDetail::class, ['name' => 'shop'])->call('setTab', 'deploy');
        $reads = $this->reads('/api/apps/shop/deploy/audit');

        $component->call('deploy')->call('pollJob')->assertSet('activeJobStatus', 'failed')->assertDispatched('app-job-finished');

        $this->assertSame($reads + 1, $this->reads('/api/apps/shop/deploy/audit'));
    }

    #[Test]
    public function the_log_viewer_reloads_when_a_job_of_the_app_finishes(): void
    {
        $viewer = Livewire::test(LogViewer::class, ['app' => 'shop', 'serverId' => $this->server->id])->assertSee('deploy OK');
        $reads = $this->reads('/api/apps/shop/logs');

        $viewer->dispatch('app-job-finished');

        $this->assertSame($reads + 1, $this->reads('/api/apps/shop/logs'));
    }
}
