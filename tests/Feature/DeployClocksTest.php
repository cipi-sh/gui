<?php

namespace CipiGui\Tests\Feature;

use CipiGui\Livewire\AppDetail;
use CipiGui\Tests\TestCase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;

/**
 * The two clocks of a deploy in the Deploy pipeline card: how long the
 * frontend build may run, and every other command. For Laravel apps and — a
 * card of their own — for Node apps, whose recipe has no artisan hooks.
 */
class DeployClocksTest extends TestCase
{
    /** @var array<string, array<string, mixed>> GET /api/apps/{name}/deploy-config, by app */
    private array $config = [
        'shop' => ['app' => 'shop', 'keep_releases' => 5, 'migrate' => true, 'optimize' => true, 'storage_link' => true, 'queue_restart' => true,
            'horizon_terminate' => true, 'predeploy_snapshot' => false, 'node_build' => 'npm ci && npm run build', 'extra_artisan' => [],
            'build_timeout' => 1800, 'step_timeout' => 300],
        'front' => ['app' => 'front', 'runtime' => 'node', 'keep_releases' => 5, 'predeploy_snapshot' => false, 'build_timeout' => 1800, 'step_timeout' => 300],
    ];

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake(function (Request $request) {
            $path = (string) parse_url($request->url(), PHP_URL_PATH);
            $apps = [
                'shop' => ['app' => 'shop', 'domain' => 'shop.example.com', 'php' => '8.5', 'engine' => 'mariadb', 'node' => false, 'aliases' => [], 'repository' => 'git@github.com:acme/shop.git', 'branch' => 'main', 'horizon' => null],
                'front' => ['app' => 'front', 'domain' => 'front.example.com', 'php' => '8.5', 'node' => true, 'custom' => true, 'type' => 'node', 'aliases' => [], 'repository' => 'git@github.com:acme/front.git', 'branch' => 'main'],
            ];
            foreach ($apps as $name => $app) {
                if ($path === "/api/apps/{$name}" && $request->method() === 'GET') {
                    return Http::response(['data' => $app]);
                }
                if ($path === "/api/apps/{$name}/deploy-config") {
                    if ($request->method() === 'PUT') {
                        $this->config[$name] = array_merge($this->config[$name], $request->data());
                    }

                    return Http::response(['data' => $this->config[$name]]);
                }
            }

            return Http::response(['data' => []]);
        });

        $server = $this->server();
        $this->actingAs($this->admin());
        session(['cipi_gui_server_id' => $server->id]);
    }

    private function deployTab(string $app): \Livewire\Features\SupportTesting\Testable
    {
        return Livewire::withQueryParams(['tab' => 'deploy'])->test(AppDetail::class, ['name' => $app]);
    }

    /** @return array<string, mixed>|null the body of the last PUT …/deploy-config */
    private function saved(string $app): ?array
    {
        $sent = Http::recorded(fn (Request $r) => $r->method() === 'PUT' && parse_url($r->url(), PHP_URL_PATH) === "/api/apps/{$app}/deploy-config");

        return $sent->isEmpty() ? null : $sent->last()[0]->data();
    }

    #[Test]
    public function a_laravel_app_shows_the_two_clocks_and_saves_them_in_seconds(): void
    {
        $page = $this->deployTab('shop')
            ->assertSet('deployConfigLoaded', true)
            ->assertSet('dcTimeouts', true)
            ->assertSet('dcBuildTimeout', '1800')
            ->assertSet('dcStepTimeout', '300')
            ->assertSeeHtml('data-dc-build-timeout')
            ->assertSeeHtml('data-dc-step-timeout')
            ->assertSee('Asset build time limit')
            ->assertSee('30 min')
            ->set('dcBuildTimeout', '2700')
            ->set('dcStepTimeout', '600')
            ->call('saveDeployConfig')
            ->assertHasNoErrors();

        $saved = $this->saved('shop');
        $this->assertSame(2700, $saved['build_timeout'] ?? null);
        $this->assertSame(600, $saved['step_timeout'] ?? null);
        $this->assertSame(5, $saved['keep_releases'] ?? null);
        $this->assertArrayHasKey('migrate', $saved);
        $page->assertSet('dcBuildTimeout', '2700')->assertSee('45 min');
    }

    #[Test]
    public function a_number_out_of_bounds_is_refused_before_the_server_is_asked(): void
    {
        $this->deployTab('shop')
            ->set('dcBuildTimeout', '30')
            ->call('saveDeployConfig')
            ->assertHasErrors(['dcBuildTimeout'])
            ->set('dcBuildTimeout', '1800')
            ->set('dcStepTimeout', '4000')
            ->call('saveDeployConfig')
            ->assertHasErrors(['dcStepTimeout']);

        $this->assertNull($this->saved('shop'));
    }

    #[Test]
    public function a_server_that_does_not_know_the_clocks_is_not_shown_them_nor_sent_them(): void
    {
        unset($this->config['shop']['build_timeout'], $this->config['shop']['step_timeout']);

        $this->deployTab('shop')
            ->assertSet('deployConfigLoaded', true)
            ->assertSet('dcTimeouts', false)
            ->assertDontSeeHtml('data-dc-build-timeout')
            ->call('saveDeployConfig')
            ->assertHasNoErrors();

        $saved = $this->saved('shop');
        $this->assertIsArray($saved);
        $this->assertArrayNotHasKey('build_timeout', $saved);
        $this->assertArrayNotHasKey('step_timeout', $saved);
        $this->assertSame(5, $saved['keep_releases'] ?? null);
    }

    #[Test]
    public function a_node_app_has_a_pipeline_card_of_its_own_and_saves_only_what_it_takes(): void
    {
        $this->deployTab('front')
            ->assertSet('deployConfigLoaded', true)
            ->assertSeeHtml('data-deploy-pipeline="node"')
            ->assertSeeHtml('data-deploy-pipeline-form="node"')
            ->assertSee('Install and build time limit')
            ->assertDontSee('Extra Artisan commands')
            ->assertDontSee('Run migrations')
            ->set('dcKeepReleases', '3')
            ->set('dcBuildTimeout', '900')
            ->call('saveDeployConfig')
            ->assertHasNoErrors();

        $saved = $this->saved('front');
        $this->assertSame(['keep_releases' => 3, 'predeploy_snapshot' => false, 'build_timeout' => 900, 'step_timeout' => 300], $saved);
    }
}
