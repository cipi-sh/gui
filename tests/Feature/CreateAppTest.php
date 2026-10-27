<?php

namespace CipiGui\Tests\Feature;

use CipiGui\Livewire\Apps;
use CipiGui\Tests\TestCase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;

class CreateAppTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $jobResult = [];

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake(function (Request $request) {
            $path = parse_url($request->url(), PHP_URL_PATH);

            return match (true) {
                $path === '/api/apps' && $request->method() === 'POST' => Http::response(['job_id' => 'job-1', 'status' => 'pending'], 202),
                $path === '/api/apps' => Http::response(['data' => []]),
                $path === '/api/dbs/engines' => Http::response(['data' => ['default' => 'mariadb', 'engines' => [['engine' => 'mariadb', 'status' => 'running', 'default' => true]]]]),
                $path === '/api/php' => Http::response(['data' => ['default' => '8.5', 'versions' => [['version' => '8.5']]]]),
                str_starts_with($path, '/api/jobs/') => Http::response(['data' => [
                    'id' => 'job-1', 'type' => 'app-create', 'status' => 'completed', 'output' => '✓ App created', 'result' => $this->jobResult,
                ]]),
                default => Http::response(['data' => []]),
            };
        });

        $server = $this->server();
        $this->actingAs($this->admin());
        session(['cipi_gui_server_id' => $server->id]);
    }

    private function createForm(): \Livewire\Features\SupportTesting\Testable
    {
        return Livewire::test(Apps::class)
            ->call('openCreate')
            ->set('user', 'shop')
            ->set('domain', 'shop.example.com')
            ->set('repository', 'git@github.com:acme/shop.git');
    }

    /** @return array<string, mixed>|null */
    private function createPayload(): ?array
    {
        $sent = Http::recorded(fn (Request $r) => $r->method() === 'POST' && parse_url($r->url(), PHP_URL_PATH) === '/api/apps');

        return $sent->isEmpty() ? null : $sent->first()[0]->data();
    }

    #[Test]
    public function a_laravel_app_sends_the_build_command_as_node_build(): void
    {
        $this->createForm()
            ->assertSee('Build command')
            ->assertSeeHtml('placeholder="npm ci &amp;&amp; npm run build"')
            ->set('assetBuild', '  npm ci && npm run build  ')
            ->call('createApp')
            ->assertHasNoErrors()
            ->assertSet('showJobOverlay', true);

        $payload = $this->createPayload();
        $this->assertSame('npm ci && npm run build', $payload['node_build']);
        $this->assertFalse($payload['custom']);
    }

    #[Test]
    public function an_empty_build_command_is_not_sent(): void
    {
        $this->createForm()->call('createApp')->assertHasNoErrors();

        $this->assertArrayNotHasKey('node_build', $this->createPayload());
    }

    #[Test]
    public function the_environment_defaults_to_production_and_offers_the_usual_labels(): void
    {
        $this->createForm()
            ->assertSet('env', 'production')
            ->assertSee('Environment')
            ->assertSeeHtml('list="app-env-options"')
            ->assertSeeHtml('<option value="staging"></option>')
            ->assertSeeHtml('<option value="development"></option>')
            ->assertSeeHtml('<option value="testing"></option>')
            ->call('createApp')
            ->assertHasNoErrors();

        $this->assertSame('production', $this->createPayload()['env']);
    }

    #[Test]
    public function any_label_matching_the_rule_is_sent_as_env_and_kept_in_the_draft(): void
    {
        $form = $this->createForm()->set('env', ' qa-2 ');

        // A non-default environment counts as something typed: closing keeps the draft.
        $form->call('closeCreate')->assertSet('createDraft', true)->call('openCreate')->assertSet('env', ' qa-2 ');

        $form->call('createApp')->assertHasNoErrors();

        $this->assertSame('qa-2', $this->createPayload()['env']);

        // A Node app sends it too.
        $this->createForm()->call('setAppKind', 'node')->set('env', 'staging')->call('createApp')->assertHasNoErrors();

        $sent = Http::recorded(fn (Request $r) => $r->method() === 'POST' && parse_url($r->url(), PHP_URL_PATH) === '/api/apps');
        $this->assertSame('staging', $sent->last()[0]->data()['env']);
    }

    #[Test]
    public function the_environment_is_validated_like_the_api(): void
    {
        foreach (['Staging', 'pre prod', '-dev', 'a.b', str_repeat('a', 33), ''] as $bad) {
            $this->createForm()->set('env', $bad)->call('createApp')->assertHasErrors(['env']);
        }

        $this->createForm()->set('env', 'Staging')->call('createApp')
            ->assertSee('Use up to 32 lowercase letters, digits, - or _');

        $this->assertNull($this->createPayload());
    }

    #[Test]
    public function the_build_command_is_validated_like_the_cli(): void
    {
        $this->createForm()
            ->set('assetBuild', 'npm run build | tee build.log')
            ->call('createApp')
            ->assertHasErrors(['assetBuild'])
            ->assertSee('Pipes, redirects, backticks and $( ) are not allowed');

        $this->createForm()
            ->set('assetBuild', 'make assets')
            ->call('createApp')
            ->assertHasErrors(['assetBuild']);

        $this->assertNull($this->createPayload());
    }

    #[Test]
    public function only_laravel_apps_show_and_send_the_build_command(): void
    {
        $form = $this->createForm()->set('assetBuild', 'npm run build');

        $form->call('setAppKind', 'custom')->assertDontSee('app-asset-build');
        $form->call('setAppKind', 'node')->assertDontSee('app-asset-build');
        $form->call('setAppKind', 'laravel')->set('octane', true)->assertSee('app-asset-build');

        $form->call('setAppKind', 'custom')->call('createApp')->assertHasNoErrors();

        $payload = $this->createPayload();
        $this->assertTrue($payload['custom']);
        $this->assertArrayNotHasKey('node_build', $payload);
    }

    #[Test]
    public function a_refused_repository_is_shown_with_the_fix_to_copy(): void
    {
        $this->jobResult = [
            'app' => 'shop',
            'ssh' => ['user' => 'shop', 'password' => 'ssh-pass'],
            'build' => 'npm ci && npm run build',
            'repository_access' => ['status' => 'denied', 'error' => 'ERROR: Repository not found.', 'hint' => 'cipi git github-token <token> && cipi git refresh shop'],
        ];

        $this->createForm()->call('createApp')->call('pollJob')
            ->assertSet('activeJobStatus', 'completed')
            ->assertSee('The deploy key cannot read the repository')
            ->assertSee('The first deploy will fail until this is fixed')
            ->assertSee('ERROR: Repository not found.')
            ->assertSee('cipi git github-token <token> && cipi git refresh shop')
            ->assertSeeHtml('data-repository-access="denied"')
            ->assertSee('Run on the server')
            ->assertSee('Save these credentials now')
            ->assertSeeHtml('data-job-build')
            ->assertSee('runs on every deploy, before the release goes live');
    }

    #[Test]
    public function a_reachable_repository_and_a_missing_branch_are_shown(): void
    {
        $this->jobResult = ['app' => 'shop', 'repository_access' => ['status' => 'ok', 'branch' => 'main']];

        $this->createForm()->call('createApp')->call('pollJob')
            ->assertSee('Repository reachable')
            ->assertSeeHtml('branch <code>main</code>')
            ->assertSeeHtml('data-repository-access="ok"');

        $this->jobResult = ['app' => 'shop', 'repository_access' => ['status' => 'nobranch', 'branch' => 'develop', 'hint' => 'cipi app edit shop --branch=<name>']];

        $this->createForm()->call('createApp')->call('pollJob')
            ->assertSee('Repository reachable, but it has no branch “develop”')
            ->assertSee('cipi app edit shop --branch=<name>')
            ->assertSeeHtml('data-repository-access="nobranch"');
    }

    #[Test]
    public function older_servers_without_the_check_show_nothing_extra(): void
    {
        $this->jobResult = ['app' => 'shop', 'ssh' => ['user' => 'shop', 'password' => 'ssh-pass']];

        $this->createForm()->call('createApp')->call('pollJob')
            ->assertSet('activeJobStatus', 'completed')
            ->assertSee('Save these credentials now')
            ->assertDontSeeHtml('data-repository-access')
            ->assertDontSeeHtml('data-job-build');
    }
}
