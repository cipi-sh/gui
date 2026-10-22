<?php

namespace CipiGui\Tests\Feature;

use CipiGui\Livewire\AppDetail;
use CipiGui\Tests\TestCase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;

class AppYmlTest extends TestCase
{
    /** Answer of GET /api/apps/shop/yml — an HTTP response to fail it. */
    private mixed $plan;

    /** Plan the server reports once an apply has run. */
    private ?array $planAfterApply = null;

    /** @var array<string, mixed> */
    private array $job = ['status' => 'completed', 'result' => []];

    /** @var array<string, mixed>|null HTTP status → body for the POST endpoints */
    private ?array $manageFailure = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->plan = $this->pendingPlan();

        Http::fake(function (Request $request) {
            $path = parse_url($request->url(), PHP_URL_PATH);
            $post = $request->method() === 'POST';

            if ($post && $this->manageFailure !== null && str_starts_with($path, '/api/apps/shop/yml/')) {
                return Http::response($this->manageFailure['body'], $this->manageFailure['status']);
            }

            return match (true) {
                $path === '/api/apps/shop' => Http::response(['data' => [
                    'app' => 'shop', 'domain' => 'shop.example.com', 'php' => '8.5', 'engine' => 'mariadb', 'node' => false, 'aliases' => [],
                    'repository' => 'git@github.com:acme/shop.git', 'branch' => 'main',
                ]]),
                $path === '/api/apps/shop/yml' => $this->plan instanceof \GuzzleHttp\Promise\PromiseInterface ? $this->plan : Http::response(['data' => $this->plan]),
                $path === '/api/apps/shop/yml/apply' && $post => $this->applied(),
                $path === '/api/apps/shop/yml/auto/enable' && $post => Http::response(['data' => ['app' => 'shop', 'auto' => true, 'sudoers' => true, 'file' => '/home/shop/current/cipi.yml']]),
                $path === '/api/apps/shop/yml/auto/disable' && $post => Http::response(['data' => ['app' => 'shop', 'auto' => false, 'sudoers' => false, 'file' => '/home/shop/current/cipi.yml']]),
                str_starts_with($path, '/api/jobs/') => Http::response(['data' => [
                    'id' => 'job-1', 'type' => 'app-yml-apply', 'output' => '✓ Applied 3 change(s) from cipi.yml',
                ] + $this->job]),
                default => Http::response(['data' => []]),
            };
        });

        $server = $this->server();
        $this->actingAs($this->admin());
        session(['cipi_gui_server_id' => $server->id]);
    }

    private function applied()
    {
        if ($this->planAfterApply !== null) {
            $this->plan = $this->planAfterApply;
        }

        return Http::response(['job_id' => 'job-1', 'status' => 'pending'], 202);
    }

    /** @return array<string, mixed> */
    private function pendingPlan(array $overrides = []): array
    {
        return $overrides + [
            'app' => 'shop',
            'file' => '/home/shop/current/cipi.yml',
            'auto' => false,
            'valid' => true,
            'in_sync' => false,
            'errors' => [],
            'warnings' => [],
            'changes' => [
                ['kind' => 'php', 'summary' => 'PHP 8.3 → 8.4'],
                ['kind' => 'alias-add', 'summary' => 'add domain alias www.shop.example.com'],
            ],
            'blockers' => [],
            'notes' => ['node settings change on the next deploy, before the build:'],
        ];
    }

    private function ymlTab(): Testable
    {
        return Livewire::test(AppDetail::class, ['name' => 'shop'])->call('setTab', 'yml');
    }

    private function sent(string $path): bool
    {
        return Http::recorded(fn (Request $r) => $r->method() === 'POST' && parse_url($r->url(), PHP_URL_PATH) === $path)->isNotEmpty();
    }

    #[Test]
    public function the_tab_shows_the_file_the_plan_and_the_auto_apply_flag(): void
    {
        $this->ymlTab()
            ->assertSet('activeTab', 'yml')
            ->assertSee('/home/shop/current/cipi.yml')
            ->assertSee('2 changes to apply')
            ->assertSee('PHP 8.3 → 8.4')
            ->assertSee('add domain alias www.shop.example.com')
            ->assertSee('alias-add')
            ->assertSee('node settings change on the next deploy, before the build:')
            ->assertSeeHtml('data-yml-auto>Off</span>')
            ->assertSeeHtml('Runs <code>cipi yml apply shop</code> on the server.')
            ->assertDontSeeHtml('data-yml-blockers')
            ->assertDontSeeHtml('data-yml-in-sync');
    }

    #[Test]
    public function apply_is_disabled_without_a_file_with_an_invalid_file_and_when_in_sync(): void
    {
        $this->plan = $this->pendingPlan(['file' => null, 'valid' => null, 'in_sync' => null, 'changes' => [], 'notes' => []]);
        $this->ymlTab()
            ->assertSee('No cipi.yml in the current release')
            ->assertSee('cipi yml generate shop > cipi.yml')
            ->assertSee('Nothing to apply without a cipi.yml.')
            ->call('applyYml');

        $this->plan = $this->pendingPlan(['valid' => false, 'in_sync' => null, 'changes' => [], 'errors' => ['line 4: limits.memory: expected a size like 512M or 1G']]);
        $this->ymlTab()
            ->assertSee('Invalid')
            ->assertSeeHtml('data-yml-errors')
            ->assertSee('line 4: limits.memory: expected a size like 512M or 1G')
            ->assertSee('Fix the errors before applying.')
            ->call('applyYml');

        $this->plan = $this->pendingPlan(['in_sync' => true, 'changes' => []]);
        $this->ymlTab()
            ->assertSeeHtml('data-yml-in-sync')
            ->assertSee('In sync — the server already matches cipi.yml.')
            ->call('applyYml');

        $this->assertFalse($this->sent('/api/apps/shop/yml/apply'));
    }

    #[Test]
    public function blockers_are_shown_and_stop_apply(): void
    {
        $this->plan = $this->pendingPlan(['blockers' => ['PHP 8.5 is not installed — run: cipi php install 8.5']]);

        $this->ymlTab()
            ->assertSee('Blocked')
            ->assertSeeHtml('data-yml-blockers')
            ->assertSee('Apply is blocked until these are resolved')
            ->assertSee('PHP 8.5 is not installed — run: cipi php install 8.5')
            ->assertSee('Resolve the blockers before applying.')
            ->call('applyYml')
            ->assertSet('showJobOverlay', false);

        $this->assertFalse($this->sent('/api/apps/shop/yml/apply'));
    }

    #[Test]
    public function apply_follows_the_job_shows_the_result_and_refreshes_the_plan(): void
    {
        $this->planAfterApply = $this->pendingPlan(['in_sync' => true, 'changes' => [], 'notes' => []]);
        $this->job = ['status' => 'completed', 'result' => ['app' => 'shop', 'outcome' => 'applied', 'applied' => 2, 'failed' => 0, 'failures' => [], 'blockers' => [], 'errors' => []]];

        $this->ymlTab()
            ->call('applyYml')
            ->assertSet('showJobOverlay', true)
            ->assertSet('jobLabel', 'Apply cipi.yml')
            ->call('pollJob')
            ->assertSet('activeJobStatus', 'completed')
            ->assertSeeHtml('data-yml-outcome="applied"')
            ->assertSee('cipi.yml applied — 2 changes.')
            ->assertSeeHtml('data-yml-last-apply')
            ->assertSet('yml.in_sync', true)
            ->assertSee('In sync — the server already matches cipi.yml.');

        $this->assertTrue($this->sent('/api/apps/shop/yml/apply'));
    }

    #[Test]
    public function a_partial_or_blocked_apply_fails_the_job_but_shows_its_result(): void
    {
        $this->job = ['status' => 'failed', 'result' => [
            'app' => 'shop', 'outcome' => 'partial', 'applied' => 1, 'failed' => 1,
            'failures' => ['add domain alias www.shop.example.com'], 'blockers' => [], 'errors' => [],
        ]];

        $this->ymlTab()->call('applyYml')->call('pollJob')
            ->assertSet('activeJobStatus', 'failed')
            ->assertSet('activeJobError', 'cipi.yml applied in part: 1 change failed, 1 applied.')
            ->assertSeeHtml('data-yml-outcome="partial"')
            ->assertSee('add domain alias www.shop.example.com')
            ->assertSet('ymlLastApply.outcome', 'partial');

        $this->job = ['status' => 'failed', 'result' => [
            'app' => 'shop', 'outcome' => 'blocked', 'applied' => 0, 'failed' => 0, 'failures' => [],
            'blockers' => ['database \'reports\' needs pgsql, which is not installed — run: cipi db install pgsql'], 'errors' => [],
        ]];

        $this->ymlTab()->call('applyYml')->call('pollJob')
            ->assertSet('activeJobError', 'cipi.yml not applied: 1 blocker to resolve first.')
            ->assertSeeHtml('data-yml-outcome="blocked"')
            ->assertSee('run: cipi db install pgsql');
    }

    #[Test]
    public function turning_auto_apply_on_asks_for_confirmation_with_the_warning(): void
    {
        $component = $this->ymlTab()
            ->assertSeeHtml('wire:click="enableYmlAuto" wire:confirm="Turn on auto-apply for shop?')
            ->assertSee('anyone who can commit to the repository can then change this app')
            ->assertSee('deploy recipe, scheduled commands, databases and backup schedule');

        $this->plan = $this->pendingPlan(['auto' => true]);
        $component->call('enableYmlAuto')
            ->assertSet('ymlSudoers', true)
            ->assertSet('yml.auto', true)
            ->assertSeeHtml('data-yml-auto>On</span>')
            ->assertSeeHtml('wire:click="disableYmlAuto"');

        $this->assertTrue($this->sent('/api/apps/shop/yml/auto/enable'));

        $this->plan = $this->pendingPlan(['auto' => false]);
        $component->call('disableYmlAuto')->assertSet('yml.auto', false);

        $this->assertTrue($this->sent('/api/apps/shop/yml/auto/disable'));
    }

    #[Test]
    public function a_token_without_yml_view_explains_the_missing_ability(): void
    {
        $this->plan = Http::response(['message' => 'Invalid ability provided.'], 403);

        $this->ymlTab()
            ->assertSet('ymlUnsupported', 'ability')
            ->assertSee('This token cannot read cipi.yml')
            ->assertSee('yml-view')
            ->assertSee('yml-manage')
            ->assertDontSee('Apply now');
    }

    #[Test]
    public function older_servers_say_which_versions_are_needed(): void
    {
        $this->plan = Http::response(['message' => 'The route api/apps/shop/yml could not be found.'], 404);

        $this->ymlTab()
            ->assertSet('ymlUnsupported', 'server')
            ->assertSee('Requires Cipi 5.6.4+ and cipi/api 1.35+')
            ->assertSee('cipi yml plan shop')
            ->assertDontSee('Apply now');

        $this->plan = Http::response(['error' => "Unknown option '--json'"], 503);

        $this->ymlTab()
            ->assertSet('ymlUnsupported', 'server')
            ->assertSee('Requires Cipi 5.6.4+ and cipi/api 1.35+')
            ->assertSee('The server said:')
            ->assertSee("Unknown option '--json'");
    }

    #[Test]
    public function a_token_without_yml_manage_explains_the_missing_ability_on_apply_and_toggle(): void
    {
        $this->manageFailure = ['status' => 403, 'body' => ['message' => 'Invalid ability provided.']];

        $this->ymlTab()
            ->call('applyYml')
            ->assertSet('showJobOverlay', false)
            ->assertSet('error', 'This token lacks the yml-manage ability (403). Recreate it from Connections with yml-manage to apply cipi.yml and turn auto-apply on or off.')
            ->call('enableYmlAuto')
            ->assertSee('This token lacks the yml-manage ability')
            ->assertSet('yml.auto', false);

        $this->manageFailure = ['status' => 503, 'body' => ['error' => 'cipi yml auto failed']];

        $this->ymlTab()
            ->call('enableYmlAuto')
            ->assertSet('error', 'cipi yml auto failed — cipi.yml from the panel requires Cipi 5.6.4+ and cipi/api 1.35+ (cipi self-update && cipi api update).');
    }

    #[Test]
    public function the_token_command_lists_the_yml_abilities(): void
    {
        $this->get('/servers')->assertOk()->assertSee('disk-view,yml-view,yml-manage');
    }
}
