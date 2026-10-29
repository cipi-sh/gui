<?php

namespace CipiGui\Tests\Feature;

use CipiGui\Livewire\AppDetail;
use CipiGui\Models\CipiServer;
use CipiGui\Tests\TestCase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;

/**
 * A deploy the panel did not start — a Git push through the webhook, `cipi
 * deploy` on the server, a rollback — is followed while it runs through
 * `GET /api/apps/{name}/deploy/status` (cipi/api 1.37+, Cipi 5.8.4+), reported
 * when it ends, and the page reloads as after one of its own jobs. Each deploy
 * of the audit table opens its own lines of the deploy log.
 */
class DeployStatusTest extends TestCase
{
    private CipiServer $server;

    /** @var array<string, mixed> body of GET …/deploy/status */
    private array $status = [];

    /** @var (\Closure(): mixed)|null answers …/deploy/status instead of $status */
    private ?\Closure $statusResponse = null;

    /** @var array<string, mixed> answer of GET /api/jobs/job-1 next to id and type */
    private array $job = ['status' => 'running', 'exit_code' => null, 'output' => null, 'result' => null];

    /** @var list<array<string, mixed>> ledger records, oldest first */
    private array $audit = [];

    /** @var list<string> page 1 of the deploy log (its last 1000 lines) */
    private array $deployLog = [];

    /** @var list<string> page 2 (the 1000 lines before those) */
    private array $olderDeployLog = [];

    private int $logTotalPages = 1;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake(function (Request $request) {
            $path = parse_url($request->url(), PHP_URL_PATH);
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

            return match (true) {
                $path === '/api/apps/shop/deploy/status' => $this->statusResponse ? ($this->statusResponse)() : Http::response(['data' => $this->status]),
                $path === '/api/apps/shop/deploy' && $request->method() === 'POST' => Http::response(['job_id' => 'job-1', 'status' => 'pending'], 202),
                $path === '/api/apps/shop/deploy/unlock' && $request->method() === 'POST' => Http::response(['job_id' => 'job-2', 'status' => 'pending'], 202),
                str_starts_with($path, '/api/jobs/') => Http::response(['data' => ['id' => 'job-1', 'type' => 'app-deploy'] + $this->job]),
                $path === '/api/apps/shop' => Http::response(['data' => [
                    'app' => 'shop', 'domain' => 'shop.example.com', 'php' => '8.5', 'engine' => 'mariadb', 'node' => false, 'aliases' => [],
                    'repository' => 'git@github.com:acme/shop.git', 'branch' => 'main',
                ]]),
                $path === '/api/apps/shop/deploy/audit' => Http::response(['data' => ['app' => 'shop', 'records' => $this->audit]]),
                $path === '/api/apps/shop/logs' && ($query['type'] ?? '') === 'deploy' => Http::response(['data' => [
                    'available_types' => ['deploy'], 'warnings' => [],
                    'files' => [['path' => '/home/shop/logs/deploy.log', 'page' => (int) ($query['page'] ?? 1), 'per_page' => 1000, 'total_pages' => $this->logTotalPages,
                        'lines' => ((int) ($query['page'] ?? 1)) === 2 ? $this->olderDeployLog : $this->deployLog]],
                ]]),
                default => Http::response(['data' => []]),
            };
        });

        $this->server = $this->server();
        $this->actingAs($this->admin());
        session(['cipi_gui_server_id' => $this->server->id]);
    }

    /** @return array<string, mixed> */
    private function running(string $trigger = 'webhook', string $phase = 'deploy'): array
    {
        return [
            'app' => 'shop', 'state' => 'running', 'running' => true, 'phase' => $phase, 'queued' => false, 'locked' => true,
            'current' => ['trigger' => $trigger, 'branch' => 'main', 'from_release' => '62', 'started_at' => '2026-10-28T18:32:49+0100', 'elapsed' => 45, 'pids' => [4242]],
            'last' => $this->last('62', 'ok', 70, 'cli'),
            'log' => '/home/shop/logs/deploy.log',
            'tail' => ['[2026-10-28 18:32:50] task deploy:update_code', '[2026-10-28 18:33:10] task deploy:vendors'],
        ];
    }

    /** @return array<string, mixed> */
    private function idle(?array $last): array
    {
        return [
            'app' => 'shop', 'state' => 'idle', 'running' => false, 'phase' => null, 'queued' => false, 'locked' => false,
            'current' => null, 'last' => $last, 'log' => '/home/shop/logs/deploy.log',
            'tail' => ['[2026-10-28 18:34:24] ===== deploy OK  app=shop release=63 duration=95s exit=0 ====='],
        ];
    }

    /** @return array<string, mixed> */
    private function last(string $release, string $result, int $duration, string $trigger, string $kind = 'deploy'): array
    {
        return ['result' => $result, 'kind' => $kind, 'release' => $release, 'duration' => $duration, 'exit' => $result === 'ok' ? 0 : 1,
            'trigger' => $trigger, 'branch' => 'main', 'from_release' => '62', 'started_at' => '2026-10-28T18:32:49+0100', 'finished_at' => '2026-10-28T18:34:24+0100'];
    }

    private function reads(string $path): int
    {
        return Http::recorded(fn (Request $r) => $r->method() === 'GET' && parse_url($r->url(), PHP_URL_PATH) === $path)->count();
    }

    #[Test]
    public function the_header_links_to_the_server_the_app_runs_on(): void
    {
        Livewire::test(AppDetail::class, ['name' => 'shop'])
            ->assertSeeHtml('data-server-link')
            ->assertSeeHtml(route('cipi-gui.server-manage', ['serverId' => $this->server->id]))
            ->assertSee('Server: production');
    }

    #[Test]
    public function a_deploy_started_by_a_git_push_is_shown_while_it_runs(): void
    {
        $this->status = $this->running();

        $component = Livewire::test(AppDetail::class, ['name' => 'shop'])
            ->assertSeeHtml('data-deploy-status="running"')
            ->assertSee('Deploying — started by a Git push')
            ->assertSee('Release 62 stays live until the new one is ready')
            ->assertSee('Started 18:32:49')
            ->assertSee('task deploy:vendors')
            ->assertSet('deployTail', ['[2026-10-28 18:32:50] task deploy:update_code', '[2026-10-28 18:33:10] task deploy:vendors'])
            ->assertSeeHtml('wire:poll.3s="pollDeployStatus"')
            ->assertSeeHtml('wire:click="deploy" disabled');

        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/api/apps/shop/deploy/status?tail=15'));

        $component->call('setTab', 'deploy')
            ->assertSeeHtml('data-deploy-live="running"')
            ->assertSee('in progress')
            ->assertSeeHtml('data-deploy-busy')
            ->assertSeeHtml('wire:click="rollback" disabled');

        $this->status = $this->running('cli', 'post-deploy');
        $component->call('pollDeployStatus')
            ->assertSee('Deploying — started by the command line')
            ->assertSee('The new release is live. The post-deploy steps, cipi.yml and the healthcheck are running.');
    }

    #[Test]
    public function a_queued_push_is_announced_before_the_cron_starts_it(): void
    {
        $this->status = ['app' => 'shop', 'state' => 'queued', 'running' => false, 'phase' => null, 'queued' => true, 'locked' => false, 'current' => null, 'last' => null, 'log' => '/home/shop/logs/deploy.log'];

        Livewire::test(AppDetail::class, ['name' => 'shop'])
            ->assertSeeHtml('data-deploy-status="queued"')
            ->assertSee('Deploy queued — a push was received')
            ->assertSeeHtml('wire:poll.3s="pollDeployStatus"')
            ->assertSeeHtml('wire:click="deploy" disabled');
    }

    #[Test]
    public function when_the_run_ends_the_page_says_how_it_went_and_reloads(): void
    {
        $this->status = $this->running();
        $component = Livewire::test(AppDetail::class, ['name' => 'shop'])->call('setTab', 'deploy');
        $appReads = $this->reads('/api/apps/shop');
        $auditReads = $this->reads('/api/apps/shop/deploy/audit');

        $this->status = $this->idle($this->last('63', 'ok', 95, 'webhook'));

        $component->call('pollDeployStatus')
            ->assertDispatched('notify', type: 'success', message: 'Deploy by a Git push finished in 1m35s: release 63 is live.')
            ->assertDispatched('app-job-finished')
            ->assertDontSeeHtml('data-deploy-status')
            ->assertDontSeeHtml('data-deploy-live')
            ->assertSeeHtml('wire:poll.10s="pollDeployStatus"')
            ->assertSet('deployTail', []);

        $this->assertSame($appReads + 1, $this->reads('/api/apps/shop'), 'the app is read again: the live release changed');
        $this->assertSame($auditReads + 1, $this->reads('/api/apps/shop/deploy/audit'), 'the ledger is read again on the Deploy tab');

        // Idle to idle: nothing to say.
        $component->call('pollDeployStatus')->assertNotDispatched('notify');
    }

    #[Test]
    public function a_run_that_failed_is_an_error_and_a_rollback_is_named(): void
    {
        $this->status = $this->running();
        $component = Livewire::test(AppDetail::class, ['name' => 'shop']);

        $this->status = $this->idle($this->last('62', 'failed', 40, 'webhook'));
        $component->call('pollDeployStatus')
            ->assertDispatched('notify', type: 'error', message: 'Deploy by a Git push failed in 40s — release 62 is still live.');

        $this->status = $this->running('rollback');
        $component->call('pollDeployStatus')->assertSee('Rolling back — started by a rollback');

        $this->status = $this->idle($this->last('61', 'ok', 3, 'rollback', 'rollback'));
        $component->call('pollDeployStatus')
            ->assertDispatched('notify', type: 'success', message: 'Rollback by a rollback finished in 3s: release 61 is live.');
    }

    #[Test]
    public function an_interrupted_run_is_a_warning_with_the_unlock_action(): void
    {
        $this->status = $this->running();
        $component = Livewire::test(AppDetail::class, ['name' => 'shop']);

        $this->status = [
            'app' => 'shop', 'state' => 'interrupted', 'running' => false, 'phase' => null, 'queued' => false, 'locked' => true,
            'current' => ['trigger' => 'webhook', 'branch' => 'main', 'from_release' => '62', 'started_at' => '2026-10-28T18:32:49+0100', 'elapsed' => 7200, 'pids' => []],
            'last' => $this->last('62', 'ok', 70, 'cli'), 'log' => '/home/shop/logs/deploy.log',
            'tail' => ['[2026-10-28 18:33:10] task deploy:vendors'],
        ];

        $component->call('pollDeployStatus')
            ->assertDispatched('notify', type: 'error')
            ->assertSeeHtml('data-deploy-status="interrupted"')
            ->assertSee('The last deploy did not finish — started by a Git push')
            ->assertSee('Started 18:32:49 on 2026-10-28')
            ->assertSeeHtml('wire:click="unlockDeploy"')
            ->assertDontSeeHtml('wire:click="deploy" disabled') // nothing holds the lock but a stale file: deploying stays possible
            ->call('setTab', 'deploy')
            ->assertSeeHtml('data-deploy-live="interrupted"')
            ->assertSee('interrupted');
    }

    #[Test]
    public function the_panels_own_deploy_is_reported_once_by_its_job(): void
    {
        $this->status = $this->idle($this->last('62', 'ok', 70, 'cli'));
        $component = Livewire::test(AppDetail::class, ['name' => 'shop'])->call('deploy')->assertSet('jobRunning', true);

        $this->status = $this->running('panel');
        $component->call('pollDeployStatus')->assertSee('Deploying — started by the panel');

        $this->status = $this->idle($this->last('63', 'ok', 50, 'panel'));
        $component->call('pollDeployStatus')->assertNotDispatched('notify');

        $this->job = ['status' => 'completed', 'exit_code' => 0, 'output' => 'Successfully deployed!', 'result' => []];
        $component->call('pollJob')->assertDispatched('notify', type: 'success', message: 'Deploy shop completed.');
    }

    #[Test]
    public function a_deploy_started_by_someone_elses_panel_is_still_reported_here(): void
    {
        $this->status = $this->running('panel');
        $component = Livewire::test(AppDetail::class, ['name' => 'shop']);

        $this->status = $this->idle($this->last('63', 'ok', 50, 'panel'));
        $component->call('pollDeployStatus')
            ->assertDispatched('notify', type: 'success', message: 'Deploy by the panel finished in 50s: release 63 is live.');
    }

    #[Test]
    public function an_older_api_or_cipi_turns_the_feature_off_quietly(): void
    {
        $this->statusResponse = fn () => Http::response(['error' => 'Not found'], 404);
        Livewire::test(AppDetail::class, ['name' => 'shop'])
            ->assertSet('deployStatusUnsupported', true)
            ->assertDontSeeHtml('pollDeployStatus')
            ->assertDontSeeHtml('data-deploy-status')
            ->assertDontSeeHtml('wire:click="deploy" disabled');

        $this->statusResponse = fn () => Http::response(['error' => 'cipi deploy --status needs Cipi 5.8.4 or newer'], 503);
        Livewire::test(AppDetail::class, ['name' => 'shop'])
            ->assertSet('deployStatusUnsupported', true)
            ->assertDontSeeHtml('pollDeployStatus');

        // A connection hiccup is not an older server: keep polling, keep what was shown.
        $this->statusResponse = fn () => throw new ConnectionException('cURL error 28: timed out');
        Livewire::test(AppDetail::class, ['name' => 'shop'])
            ->assertSet('deployStatusUnsupported', false)
            ->assertSeeHtml('wire:poll.10s="pollDeployStatus"');
    }

    #[Test]
    public function each_deploy_of_the_audit_table_opens_its_own_lines_of_the_log(): void
    {
        $this->audit = [
            ['seq' => 1, 'ts' => '2026-10-20T08:00:00Z', 'event' => 'published', 'release' => '27', 'origin' => 'webhook'],
            ['seq' => 2, 'ts' => '2026-10-22T08:00:00Z', 'event' => 'published', 'release' => '28', 'origin' => 'webhook'],
            ['seq' => 3, 'ts' => '2026-10-24T08:55:55Z', 'event' => 'published', 'release' => '29', 'origin' => 'webhook'],
            ['seq' => 4, 'ts' => '2026-10-25T10:00:40Z', 'event' => 'failed', 'release' => '30', 'origin' => 'panel'],
        ];
        $this->deployLog = [
            '[2026-10-24 08:54:00] ===== deploy start  app=shop trigger=webhook branch=main from-release=28 =====',
            '[2026-10-24 08:54:01] task deploy:info',
            '[2026-10-24 08:55:55] ===== deploy OK  app=shop release=29 duration=54s exit=0 =====',
            '',
            '[2026-10-24 08:55:58]  ✓ Healthcheck passed — HTTP 200',
            '[2026-10-25 10:00:00] ===== deploy start  app=shop trigger=panel branch=main from-release=29 =====',
            '[2026-10-25 10:00:40] ===== deploy FAILED  app=shop release=29 duration=40s exit=1 =====',
        ];
        $this->olderDeployLog = [
            '[2026-10-22 07:59:00] ===== deploy start  app=shop trigger=webhook branch=main from-release=27 =====',
            '[2026-10-22 08:00:00] ===== deploy OK  app=shop release=28 duration=60s exit=0 =====',
        ];
        $this->logTotalPages = 2;

        $component = Livewire::test(AppDetail::class, ['name' => 'shop'])->call('setTab', 'deploy')
            ->assertSeeHtml('wire:click="showAuditLog(1)"');

        // Newest first: 0 = failed 30, 1 = published 29, 2 = published 28, 3 = published 27.
        $component->call('showAuditLog', 1)
            ->assertSet('auditLogIndex', 1)
            ->assertSet('auditLogMissing', false)
            ->assertSet('auditLogLines', array_slice($this->deployLog, 0, 5))
            ->assertSeeHtml('data-audit-log')
            ->assertSee('Deploy log — release 29')
            ->assertSee('Published · 2026-10-24 08:55 · webhook')
            ->assertSee('Healthcheck passed');

        $component->call('showAuditLog', 0)
            ->assertSet('auditLogLines', array_slice($this->deployLog, 5, 2))
            ->assertSee('Deploy log — release 30');

        // Release 28 is on the page before: it is read too.
        $component->call('showAuditLog', 2)
            ->assertSet('auditLogMissing', false)
            ->assertSet('auditLogLines', $this->olderDeployLog);
        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/api/apps/shop/logs?') && str_contains($r->url(), 'page=2'));

        // Release 27 is older than both pages.
        $component->call('showAuditLog', 3)
            ->assertSet('auditLogMissing', true)
            ->assertSet('auditLogLines', [])
            ->assertSee('older than the last 2000 lines')
            ->assertSee('cipi deploy shop --log=5000');

        $component->call('closeAuditLog')
            ->assertSet('auditLogIndex', null)
            ->assertDontSeeHtml('data-audit-log');
    }

    #[Test]
    public function the_healthcheck_card_says_the_check_runs_after_every_deploy_too(): void
    {
        Livewire::test(AppDetail::class, ['name' => 'shop'])
            ->assertSee('right after every deploy')
            ->assertSee('only with the health route on');
    }
}
