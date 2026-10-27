<?php

namespace CipiGui\Tests\Feature;

use CipiGui\Livewire\AppDetail;
use CipiGui\Livewire\Apps;
use CipiGui\Models\CipiServer;
use CipiGui\Tests\TestCase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;

/**
 * The environment label of apps (`env`, cipi/api 1.36+): badges in the lists and on
 * the app page, the edit form, and the cipi.yml tab saying which file is read first.
 */
class AppEnvTest extends TestCase
{
    private CipiServer $production;

    private CipiServer $staging;

    /** The app shopstg answers with — `env` left out to play a server before 1.36. */
    private array $shopstg = ['app' => 'shopstg', 'domain' => 'shop.staging.example.com', 'php' => '8.5', 'engine' => 'mariadb', 'node' => false, 'aliases' => [], 'repository' => 'git@github.com:acme/shop.git', 'branch' => 'develop', 'env' => 'staging'];

    /** Answer of GET /api/apps/shopstg/yml. */
    private array $plan = ['app' => 'shopstg', 'env' => 'staging', 'file' => '/home/shopstg/current/cipi-staging.yml', 'auto' => false, 'valid' => true, 'in_sync' => true, 'changes' => [], 'errors' => [], 'warnings' => [], 'blockers' => [], 'notes' => []];

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake(function (Request $request) {
            $host = parse_url($request->url(), PHP_URL_HOST);
            $path = parse_url($request->url(), PHP_URL_PATH);

            return match (true) {
                $path === '/api/apps' && $request->method() === 'GET' => Http::response(['data' => $host === 'staging.example.com'
                    ? [$this->shopstg, ['app' => 'legacy', 'domain' => 'legacy.example.com', 'php' => '8.4', 'engine' => null, 'node' => false, 'aliases' => []]]
                    : [['app' => 'shop', 'domain' => 'shop.example.com', 'php' => '8.5', 'engine' => 'mariadb', 'node' => false, 'aliases' => [], 'repository' => 'git@github.com:acme/shop.git', 'branch' => 'main', 'env' => 'production']],
                ]),
                $path === '/api/apps/shopstg' && $request->method() === 'GET' => Http::response(['data' => $this->shopstg]),
                $path === '/api/apps/shopstg' && $request->method() === 'PUT' => Http::response(['job_id' => 'job-1', 'status' => 'pending'], 202),
                $path === '/api/apps/shopstg/yml' => Http::response(['data' => $this->plan]),
                $path === '/api/php' => Http::response(['data' => ['default' => '8.5', 'versions' => [['version' => '8.5']]]]),
                default => Http::response(['data' => []]),
            };
        });

        $this->production = $this->server();
        $this->staging = $this->server(['name' => 'staging', 'url' => 'https://staging.example.com']);
        $this->actingAs($this->admin());
        session(['cipi_gui_server_id' => $this->staging->id]);
    }

    /** @return array<string, mixed>|null */
    private function editPayload(): ?array
    {
        $sent = Http::recorded(fn (Request $r) => $r->method() === 'PUT' && parse_url($r->url(), PHP_URL_PATH) === '/api/apps/shopstg');

        return $sent->isEmpty() ? null : $sent->first()[0]->data();
    }

    #[Test]
    public function the_fleet_list_shows_every_app_with_its_environment_and_only_production_is_muted(): void
    {
        $list = Livewire::test(Apps::class)->call('loadApps')
            ->assertSeeHtml('class="badge badge-gray" title="Environment: production" data-app-env="production">production</span>')
            ->assertSeeHtml('class="badge badge-env" title="Environment: staging" data-app-env="staging">staging</span>')
            // An app a pre-1.36 server lists without `env` is production.
            ->assertSee('legacy')
            ->assertDontSeeHtml('data-app-env=""');

        // The label is searchable (a word no name, domain or server carries).
        $this->shopstg['env'] = 'qa';
        $list->call('loadApps')->set('search', 'qa')
            ->assertSee('shopstg')
            ->assertDontSee('legacy.example.com')
            ->assertDontSee('shop.example.com');
    }

    #[Test]
    public function the_app_page_shows_the_environment_and_pre_fills_the_edit_form(): void
    {
        Livewire::test(AppDetail::class, ['name' => 'shopstg'])
            ->assertSet('app.env', 'staging')
            ->assertSet('editEnv', 'staging')
            ->assertSeeHtml('class="badge badge-env" title="Environment" data-app-env="staging">staging</span>')
            ->assertSeeHtml('deploys read <code>cipi-staging.yml</code>, then <code>cipi.yml</code>')
            ->assertSeeHtml('wire:model="editEnv" list="edit-env-options"')
            ->assertSeeHtml('<option value="development"></option>');
    }

    #[Test]
    public function only_a_changed_environment_is_sent_and_it_is_validated(): void
    {
        $page = Livewire::test(AppDetail::class, ['name' => 'shopstg']);

        $page->call('saveApp')->assertHasNoErrors()->assertSet('showJobOverlay', false);
        $this->assertNull($this->editPayload());

        $page->set('editEnv', 'Staging')->call('saveApp')->assertHasErrors(['editEnv']);
        $page->set('editEnv', 'pre prod')->call('saveApp')->assertHasErrors(['editEnv']);
        $this->assertNull($this->editPayload());

        $page->set('editEnv', ' testing ')->call('saveApp')
            ->assertHasNoErrors()
            ->assertSet('showJobOverlay', true)
            ->assertSet('jobLabel', 'Update shopstg');

        $this->assertSame(['env' => 'testing'], $this->editPayload());
    }

    #[Test]
    public function an_older_server_without_the_label_is_shown_as_production(): void
    {
        unset($this->shopstg['env']);
        unset($this->plan['env']);

        Livewire::test(AppDetail::class, ['name' => 'shopstg'])
            ->assertSet('app.env', 'production')
            ->assertSet('editEnv', 'production')
            ->assertSeeHtml('data-app-env="production">production</span>')
            ->call('setTab', 'yml')
            ->assertSet('yml.env', null)
            ->assertSeeHtml('data-yml-env="production"')
            ->assertSeeHtml('reads <code>cipi-production.yml</code> first, then <code>cipi.yml</code>');
    }

    #[Test]
    public function the_yml_tab_names_the_environment_and_the_file_read_first(): void
    {
        Livewire::test(AppDetail::class, ['name' => 'shopstg'])->call('setTab', 'yml')
            ->assertSet('yml.env', 'staging')
            ->assertSeeHtml('data-yml-env="staging"')
            ->assertSeeHtml('reads <code>cipi-staging.yml</code> first, then <code>cipi.yml</code>')
            ->assertSee('/home/shopstg/current/cipi-staging.yml')
            ->assertSee('In sync — the server already matches cipi.yml.')
            ->assertSeeHtml('the deploy branch (<code>deploy.branch</code>) and recipe');

        // Without a file, the hint offers the environment's own file too.
        $this->plan = ['app' => 'shopstg', 'env' => 'staging', 'file' => null, 'auto' => false, 'valid' => null, 'in_sync' => null, 'changes' => [], 'errors' => [], 'warnings' => [], 'blockers' => [], 'notes' => []];

        Livewire::test(AppDetail::class, ['name' => 'shopstg'])->call('setTab', 'yml')
            ->assertSee('No cipi.yml in the current release')
            ->assertSeeHtml('or a <code>cipi-staging.yml</code> for this environment only');
    }
}
