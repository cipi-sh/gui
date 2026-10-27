<?php

namespace CipiGui\Tests\Feature;

use CipiGui\Livewire\Apps;
use CipiGui\Models\CipiServer;
use CipiGui\Tests\TestCase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;

class AppsFleetTest extends TestCase
{
    private CipiServer $production;

    private CipiServer $staging;

    /** When true, the staging host answers nothing. */
    private bool $stagingDown = false;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake(function (Request $request) {
            $host = parse_url($request->url(), PHP_URL_HOST);
            $path = parse_url($request->url(), PHP_URL_PATH);

            if ($host === 'staging.example.com' && $this->stagingDown) {
                throw new ConnectionException('Connection refused');
            }

            return match (true) {
                $path === '/api/apps' && $request->method() === 'GET' => Http::response(['data' => $host === 'staging.example.com'
                    ? [['app' => 'blog', 'domain' => 'blog.example.com', 'php' => '8.4', 'engine' => null, 'node' => false, 'aliases' => []]]
                    : [['app' => 'shop', 'domain' => 'shop.example.com', 'php' => '8.5', 'engine' => 'mariadb', 'node' => false, 'aliases' => [], 'repository' => 'git@github.com:acme/shop.git', 'branch' => 'main']],
                ]),
                $path === '/api/dbs/engines' => Http::response(['data' => ['default' => 'mariadb', 'engines' => [
                    ['engine' => $host === 'staging.example.com' ? 'pgsql' : 'mariadb', 'status' => 'running', 'default' => true],
                ]]]),
                $path === '/api/php' => Http::response(['data' => ['default' => '8.5', 'versions' => [['version' => '8.5']]]]),
                str_ends_with($path, '/deploy') && $request->method() === 'POST' => Http::response(['job_id' => 'job-1', 'status' => 'pending'], 202),
                default => Http::response(['data' => []]),
            };
        });

        $this->production = $this->server();
        $this->staging = $this->server(['name' => 'staging', 'url' => 'https://staging.example.com']);
        $this->actingAs($this->admin());
        session(['cipi_gui_server_id' => $this->production->id]);
    }

    #[Test]
    public function apps_of_every_server_are_listed_with_their_server(): void
    {
        Livewire::test(Apps::class)
            ->assertSet('loading', true)
            ->call('loadApps')
            ->assertSet('loading', false)
            ->assertSee('shop.example.com')->assertSee('blog.example.com')
            ->assertSeeInOrder(['All servers', 'production', 'staging'])
            ->assertSeeHtml('href="'.route('cipi-gui.apps.show', ['name' => 'blog', 'server' => $this->staging->id]).'"')
            ->assertSee('2 of 2 apps')->assertSee('on 2 servers');
    }

    #[Test]
    public function the_list_can_be_narrowed_to_one_server(): void
    {
        Livewire::withQueryParams(['server' => $this->staging->id])
            ->test(Apps::class)
            ->call('loadApps')
            ->assertSet('scopeServerId', $this->staging->id)
            ->assertSee('blog.example.com')->assertDontSee('shop.example.com')
            ->assertSee('1 of 1 apps')->assertSee('Show every server')
            ->call('setScope', 0)
            ->assertSee('shop.example.com')->assertSee('blog.example.com');

        $this->assertSame($this->staging->id, session('cipi_gui_server_id'), '?server= also selects the server for the rest of the panel');
    }

    #[Test]
    public function a_server_that_does_not_answer_does_not_hide_the_others(): void
    {
        $this->stagingDown = true;

        Livewire::test(Apps::class)
            ->call('loadApps')
            ->assertSee('shop.example.com')
            ->assertSee('One server did not answer')
            ->assertSee('Unable to connect to server')
            ->assertSee('1 of 1 apps');
    }

    #[Test]
    public function row_actions_go_to_the_rows_server(): void
    {
        Livewire::test(Apps::class)
            ->call('loadApps')
            ->call('deploy', 'blog', $this->staging->id)
            ->assertSet('showJobOverlay', true)
            ->assertSet('jobLabel', 'Deploy blog on staging');

        Http::assertSent(fn (Request $r) => $r->method() === 'POST' && $r->url() === 'https://staging.example.com/api/apps/blog/deploy');
        Http::assertNotSent(fn (Request $r) => $r->method() === 'POST' && str_starts_with($r->url(), 'https://vps.example.com/'));
    }

    #[Test]
    public function the_create_form_targets_the_chosen_server_and_keeps_what_was_typed(): void
    {
        $form = Livewire::test(Apps::class)
            ->call('openCreate')
            ->assertSet('createServerId', $this->production->id)
            ->assertSet('engine', 'mariadb')
            ->assertSee('Server')
            ->set('user', 'shop')
            ->set('createServerId', $this->staging->id)
            ->assertSet('engine', 'pgsql')
            ->assertSet('user', 'shop')
            ->call('closeCreate')
            ->assertSet('showJobOverlay', false)
            ->assertSet('showCreateModal', false)
            ->assertSet('createDraft', true);

        $form->call('openCreate')
            ->assertSet('user', 'shop')
            ->assertSet('createServerId', $this->staging->id)
            ->assertSee('Picked up where you left off')
            ->call('clearCreateForm')
            ->assertSet('user', '')
            ->assertSet('createDraft', false);
    }

    #[Test]
    public function the_create_dialog_ignores_clicks_outside_it(): void
    {
        Livewire::test(Apps::class)->call('openCreate')
            ->assertSeeHtml('class="modal-overlay"  x-data')
            ->assertDontSeeHtml('wire:click.self="closeCreate"');
    }
}
