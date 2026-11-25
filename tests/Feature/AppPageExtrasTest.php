<?php

namespace CipiGui\Tests\Feature;

use CipiGui\Livewire\AppDetail;
use CipiGui\Livewire\ServerManage;
use CipiGui\Models\CipiServer;
use CipiGui\Tests\TestCase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;

/**
 * 4.6.0, what was added to the app and server pages after the firewall:
 * the .env as text, a running job's output in its overlay, the title of a
 * deploy's commit, which of queue:restart and horizon:terminate applies, and
 * the server's IPv6 address.
 */
class AppPageExtrasTest extends TestCase
{
    private CipiServer $server;

    /** @var array<string, mixed> GET /api/apps/shop */
    private array $shop = ['app' => 'shop', 'domain' => 'shop.example.com', 'php' => '8.5', 'engine' => 'mariadb', 'node' => false, 'aliases' => [], 'repository' => 'git@github.com:acme/shop.git', 'branch' => 'main', 'horizon' => null];

    /** @var array<string, string> the .env on the server */
    private array $env = ['APP_NAME' => 'Shop', 'APP_ENV' => 'production', 'DB_PASSWORD' => 's3cret'];

    /** @var array<string, mixed> GET /api/jobs/job-1, next to id and type */
    private array $job = ['status' => 'running', 'exit_code' => null, 'output' => null, 'result' => null];

    /** @var array<string, mixed> GET …/deploy/status */
    private array $deployStatus = [];

    /** @var list<array<string, mixed>> */
    private array $audit = [];

    /** @var array<string, mixed> system of GET /api/status */
    private array $system = ['hostname' => 'fra1', 'cipi' => '5.14.0', 'ip' => '203.0.113.24', 'ipv6' => '2001:db8:24::1'];

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake(function (Request $request) {
            $path = parse_url($request->url(), PHP_URL_PATH);

            return match (true) {
                $path === '/api/apps/shop' && $request->method() === 'GET' => Http::response(['data' => $this->shop]),
                $path === '/api/apps/shop/env' && $request->method() === 'GET' => Http::response(['data' => ['app' => 'shop', 'vars' => $this->env]]),
                $path === '/api/apps/shop/env' => Http::response(['data' => ['app' => 'shop', 'vars' => $this->env = $this->applied($request->data())]]),
                $path === '/api/apps/shop/deploy' && $request->method() === 'POST' => Http::response(['job_id' => 'job-1', 'status' => 'pending'], 202),
                $path === '/api/apps/shop/deploy/status' => Http::response(['data' => $this->deployStatus]),
                $path === '/api/apps/shop/deploy/audit' => Http::response(['data' => ['app' => 'shop', 'records' => $this->audit]]),
                $path === '/api/apps/shop/deploy-config' => Http::response(['data' => ['migrate' => true, 'optimize' => true, 'storage_link' => true, 'queue_restart' => true, 'horizon_terminate' => true, 'keep_releases' => 5]]),
                str_starts_with((string) $path, '/api/jobs/') => Http::response(['data' => ['id' => 'job-1', 'type' => 'app-deploy'] + $this->job]),
                $path === '/api/status' => Http::response(['data' => ['system' => $this->system, 'resources' => [], 'services' => ['nginx' => 'running'], 'apps' => 1]]),
                $path === '/api/php' => Http::response(['data' => ['default' => '8.5', 'versions' => [['version' => '8.5']]]]),
                default => Http::response(['data' => []]),
            };
        });

        $this->server = $this->server(['ip' => '203.0.113.24']);
        $this->actingAs($this->admin());
        session(['cipi_gui_server_id' => $this->server->id]);
    }

    /**
     * What the server's .env holds after a PUT with set, unset and order.
     *
     * @param  array<string, mixed>  $body
     * @return array<string, string>
     */
    private function applied(array $body): array
    {
        $vars = array_diff_key(array_replace($this->env, (array) ($body['set'] ?? [])), array_flip((array) ($body['unset'] ?? [])));
        if (is_array($body['order'] ?? null)) {
            $vars = array_replace(array_intersect_key(array_flip($body['order']), $vars), $vars);
        }

        return array_map('strval', $vars);
    }

    /** @return array<string, mixed>|null the body of the PUT that saved the .env */
    private function envSaved(): ?array
    {
        $sent = Http::recorded(fn (Request $r) => $r->method() !== 'GET' && parse_url($r->url(), PHP_URL_PATH) === '/api/apps/shop/env');

        return $sent->isEmpty() ? null : $sent->last()[0]->data();
    }

    private function envTab(): \Livewire\Features\SupportTesting\Testable
    {
        return Livewire::withQueryParams(['tab' => 'env'])->test(AppDetail::class, ['name' => 'shop'])->assertSet('envLoaded', true);
    }

    #[Test]
    public function a_pasted_block_is_split_into_the_list_and_saved_with_it(): void
    {
        $page = $this->envTab()
            ->assertSeeHtml('wire:click="openEnvText(\'paste\')"')
            ->call('openEnvText', 'paste')
            ->assertSet('envTextMode', 'paste')
            ->assertSet('envText', '')
            ->assertSeeHtml('data-env-text="paste"')
            ->set('envText', "# from the README\nexport APP_NAME=\"My shop\"\n\nMAIL_MAILER=smtp   # the relay\nMAIL_PASSWORD='p@ss #1'\n")
            ->call('applyEnvText')
            ->assertHasNoErrors()
            ->assertSet('envTextMode', null);

        // The rows: known keys in place with the new value, new ones at the end, nothing removed.
        $this->assertSame([
            ['key' => 'APP_NAME', 'value' => 'My shop'],
            ['key' => 'APP_ENV', 'value' => 'production'],
            ['key' => 'DB_PASSWORD', 'value' => 's3cret'],
            ['key' => 'MAIL_MAILER', 'value' => 'smtp'],
            ['key' => 'MAIL_PASSWORD', 'value' => 'p@ss #1'],
        ], $page->get('envRows'));
        $this->assertNull($this->envSaved(), 'nothing reaches the server before Save');

        $page->call('saveEnv');
        $saved = $this->envSaved();
        $this->assertSame(['APP_NAME' => 'My shop', 'MAIL_MAILER' => 'smtp', 'MAIL_PASSWORD' => 'p@ss #1'], $saved['set'] ?? null);
        $this->assertSame([], $saved['unset'] ?? []);
    }

    #[Test]
    public function the_list_edited_as_text_replaces_it_a_line_taken_out_removes_the_key(): void
    {
        $page = $this->envTab()
            ->call('openEnvText', 'edit')
            ->assertSet('envTextMode', 'edit')
            ->assertSet('envText', "APP_NAME=Shop\nAPP_ENV=production\nDB_PASSWORD=s3cret")
            ->assertSeeHtml('data-env-text="edit"')
            // The rows are hidden while the text is what is being edited.
            ->assertDontSeeHtml('wire:model="envNewKey"')
            ->set('envText', "APP_ENV=staging\nAPP_NAME=Shop\nQUEUE_CONNECTION=redis\n")
            ->call('applyEnvText')
            ->assertHasNoErrors()
            ->assertSet('envTextMode', null)
            ->assertSeeHtml('wire:model="envNewKey"');

        $this->assertSame(['APP_ENV', 'APP_NAME', 'QUEUE_CONNECTION'], array_column($page->get('envRows'), 'key'));

        $page->call('saveEnv');
        $saved = $this->envSaved();
        $this->assertSame(['APP_ENV' => 'staging', 'QUEUE_CONNECTION' => 'redis'], $saved['set'] ?? null);
        $this->assertSame(['DB_PASSWORD'], $saved['unset'] ?? null);
    }

    #[Test]
    public function text_that_is_not_an_env_is_refused_and_nothing_changes(): void
    {
        $page = $this->envTab()
            ->call('openEnvText', 'paste')
            ->set('envText', "APP_NAME=ok\nthis is not a variable\nBAD=\"never closed\n")
            ->call('applyEnvText')
            ->assertHasErrors(['envText'])
            ->assertSet('envTextMode', 'paste')
            ->assertSee('Line 2: expected KEY=value.');

        $this->assertSame(['APP_NAME', 'APP_ENV', 'DB_PASSWORD'], array_column($page->get('envRows'), 'key'));

        $page->set('envText', '')->call('applyEnvText')->assertHasErrors(['envText']);
        $page->call('closeEnvText')->assertSet('envTextMode', null)->assertSet('envText', '');
        // A mode the page does not have opens nothing.
        $page->call('openEnvText', 'raw')->assertSet('envTextMode', null);
    }

    #[Test]
    public function the_overlay_of_a_running_job_follows_its_output(): void
    {
        $page = Livewire::test(AppDetail::class, ['name' => 'shop'])
            ->call('deploy')
            ->assertSet('showJobOverlay', true)
            ->assertDontSeeHtml('data-job-live')
            ->assertSee('its output shows here as the command prints it');

        // cipi/api 1.44: a running job says what its command has printed so far.
        $this->job['output'] = "\e[0;36m→\e[0m Deploying shop…\ntask deploy:vendors\n";
        $page->call('pollJob')
            ->assertSet('jobRunning', true)
            ->assertSet('activeJobOutput', "→ Deploying shop…\ntask deploy:vendors")
            ->assertSeeHtml('data-job-live')
            ->assertSee('task deploy:vendors')
            ->assertDontSee('its output shows here as the command prints it');

        // Finished: the whole output, no longer "live".
        $this->job = ['status' => 'completed', 'exit_code' => 0, 'output' => "→ Deploying shop…\ntask deploy:vendors\ntask deploy:symlink\n✓ Deployed", 'result' => null];
        $page->call('pollJob')
            ->assertSet('jobRunning', false)
            ->assertDontSeeHtml('data-job-live')
            ->assertSee('task deploy:symlink');
    }

    #[Test]
    public function a_deploy_on_an_older_api_is_followed_through_its_log(): void
    {
        // Before 1.44 a running job has no output; the page already reads the last lines of deploy.log.
        $this->deployStatus = [
            'app' => 'shop', 'state' => 'running', 'running' => true, 'phase' => 'deploy', 'queued' => false, 'locked' => true,
            'current' => ['trigger' => 'cli', 'branch' => 'main', 'from_release' => '62', 'started_at' => '2026-11-25T18:32:49+0100', 'elapsed' => 20, 'pids' => [4242]],
            'last' => null,
            'log' => '/home/shop/logs/deploy.log',
            'tail' => ['[2026-11-25 18:32:50] task deploy:update_code', '[2026-11-25 18:33:10] task deploy:vendors'],
        ];

        Livewire::test(AppDetail::class, ['name' => 'shop'])
            ->call('deploy')
            ->call('pollJob')
            ->call('pollDeployStatus')
            ->assertSet('jobRunning', true)
            ->assertSet('activeJobOutput', null)
            ->assertSeeHtml('data-job-live')
            ->assertSee('task deploy:vendors');
    }

    #[Test]
    public function the_deploys_show_the_title_of_their_commit(): void
    {
        $this->audit = [
            ['seq' => 1, 'ts' => '2026-11-20T08:00:00Z', 'event' => 'published', 'release' => '28', 'commit' => '9d1f3a5c7e2b4d6f8a0c1e3b5d7f9a2c4e6b8d0f', 'subject' => 'Fix checkout <b>tax</b> rounding', 'origin' => 'webhook', 'trigger' => 'push', 'operator' => '', 'ip' => '', 'claimed' => []],
            // A server before Cipi 5.14.0, or a commit its clone no longer has: no subject.
            ['seq' => 2, 'ts' => '2026-11-21T08:00:00Z', 'event' => 'published', 'release' => '29', 'commit' => 'a71d0c94e2f35b8c61d7e0a9b3f4c2d1e8a7b6c5', 'origin' => 'webhook', 'trigger' => 'push', 'operator' => '', 'ip' => '', 'claimed' => []],
        ];

        $html = Livewire::test(AppDetail::class, ['name' => 'shop'])->call('setTab', 'deploy')
            ->assertSee('9d1f3a5')
            ->assertSeeHtml('data-commit-subject')
            ->assertSee('Fix checkout <b>tax</b> rounding')
            ->assertDontSeeHtml('<b>tax</b>')
            ->html();

        $this->assertSame(1, substr_count($html, 'data-commit-subject'));
    }

    #[Test]
    public function the_deploy_pipeline_says_which_worker_hook_applies(): void
    {
        Livewire::test(AppDetail::class, ['name' => 'shop'])->call('setTab', 'deploy')
            ->assertSet('app.horizon', false)
            ->assertSeeHtml('data-worker-hooks="workers"')
            ->assertSee('This app does not run Horizon')
            ->assertSee('Skipped: no Horizon here');

        $this->shop['horizon'] = 'true';
        Livewire::test(AppDetail::class, ['name' => 'shop'])->call('setTab', 'deploy')
            ->assertSet('app.horizon', true)
            ->assertSeeHtml('data-worker-hooks="horizon"')
            ->assertSee('Restart Horizon')
            ->assertDontSee('Skipped: no Horizon here');

        // A server that does not say: neither claim.
        unset($this->shop['horizon']);
        Livewire::test(AppDetail::class, ['name' => 'shop'])->call('setTab', 'deploy')
            ->assertSeeHtml('data-worker-hooks="unknown"')
            ->assertSee('can both stay on');
    }

    #[Test]
    public function the_server_page_shows_its_ipv6_address_when_it_has_one(): void
    {
        Livewire::test(ServerManage::class, ['serverId' => $this->server->id])->call('init')
            ->assertSee('Public IPv6')
            ->assertSeeHtml('data-server-ipv6')
            ->assertSee('2001:db8:24::1');

        // An API before 1.44, a server without IPv6, or something that is not an address: no row.
        foreach ([null, '', 'fe80::1%eth0; id', '203.0.113.24'] as $value) {
            $this->system['ipv6'] = $value;
            Livewire::test(ServerManage::class, ['serverId' => $this->server->id])->call('init')
                ->assertDontSee('Public IPv6')
                ->assertDontSeeHtml('data-server-ipv6');
        }
    }

    #[Test]
    public function fields_side_by_side_line_up_on_their_labels(): void
    {
        // The healthcheck form: a hint under the URL must not push "Expected status" down.
        Livewire::test(AppDetail::class, ['name' => 'shop'])
            ->assertSeeHtml('<form wire:submit="saveHealth" class="grid grid-cols-1 sm:grid-cols-4 gap-3 items-start">')
            ->assertDontSeeHtml('items-end');
    }
}
