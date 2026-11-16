<?php

namespace CipiGui\Tests\Feature;

use CipiGui\Livewire\AppDetail;
use CipiGui\Livewire\LogViewer;
use CipiGui\Livewire\ServerManage;
use CipiGui\Livewire\Servers;
use CipiGui\Livewire\Team;
use CipiGui\Models\CipiServer;
use CipiGui\Services\Access;
use CipiGui\Support\Theme;
use CipiGui\Tests\Fixtures\User;
use CipiGui\Tests\TestCase;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;

/**
 * 4.2.0 — what a user may do is decided by the grants at the moment of the
 * request, never by the page.
 *
 * Livewire applies whatever the browser sends to every public property that is
 * not locked. App detail kept the abilities it computed in mount() in a public
 * `$can` and every action read it; `$appName`, `$serverId` and the id of the
 * job being polled were public too. A user with nothing but `view` on one app
 * could send its own page back with `can` set to everything and `appName` set
 * to another app, and the panel did the rest with its own API token.
 */
class LockedStateTest extends TestCase
{
    private const DENIED = 'Your access does not include this action. Ask an admin of the panel.';

    private CipiServer $prod;

    private CipiServer $staging;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake(function (Request $request) {
            $path = parse_url($request->url(), PHP_URL_PATH);

            return match (true) {
                $path === '/api/apps/shop' => Http::response(['data' => ['app' => 'shop', 'domain' => 'shop.example.com', 'php' => '8.5', 'engine' => 'mariadb', 'node' => false, 'aliases' => [], 'repository' => 'git@github.com:acme/shop.git', 'branch' => 'main']]),
                $path === '/api/apps/blog' => Http::response(['data' => ['app' => 'blog', 'domain' => 'blog.example.com', 'php' => '8.4', 'engine' => 'mariadb', 'node' => false, 'aliases' => []]]),
                $path === '/api/apps/shop/logs' => Http::response(['data' => ['available_types' => ['deploy'], 'files' => [['path' => '/home/shop/logs/deploy.log', 'lines' => ['deploy OK']]], 'warnings' => []]]),
                $path === '/api/apps/blog/logs' => Http::response(['data' => ['available_types' => ['deploy'], 'files' => [['path' => '/home/blog/logs/deploy.log', 'lines' => ['blog secret line']]], 'warnings' => []]]),
                $path === '/api/status' => Http::response(['data' => ['system' => ['hostname' => 'fra1'], 'resources' => [], 'services' => ['nginx' => 'running'], 'apps' => 2]]),
                str_starts_with((string) $path, '/api/jobs/') => Http::response(['data' => ['id' => basename((string) $path), 'type' => 'app-deploy', 'status' => 'completed', 'exit_code' => 0, 'output' => 'one-time password: hunter2', 'result' => []]]),
                $request->method() !== 'GET' => Http::response(['job_id' => 'job-1', 'status' => 'pending'], 202),
                default => Http::response(['data' => []]),
            };
        });

        $this->prod = $this->server();
        $this->staging = $this->server(['name' => 'staging', 'url' => 'https://staging.example.com']);
        $this->user = $this->member();
        $this->actingAs($this->user);
    }

    /** The app page of `shop` on production, as the signed-in user. */
    private function shop(): Testable
    {
        return Livewire::withQueryParams(['server' => $this->prod->id])->test(AppDetail::class, ['name' => 'shop']);
    }

    /**
     * What the browser does when it sends a property back changed: refused, and
     * the page stays as it was. Livewire's test helper swaps the exception
     * handler and the middleware switch for the length of a request and, on
     * Livewire 3, does not put them back when the request throws — so this does.
     */
    private function assertLocked(Testable $component, string $property, mixed $value): void
    {
        $handler = $this->app->make(ExceptionHandler::class);
        $skipsMiddleware = $this->app->shouldSkipMiddleware();

        try {
            $component->set($property, $value);
        } catch (CannotUpdateLockedPropertyException $e) {
            $this->assertStringContainsString('['.explode('.', $property)[0].']', $e->getMessage());

            return;
        } finally {
            $this->app->instance(ExceptionHandler::class, $handler);
            if (! $skipsMiddleware) {
                unset($this->app['middleware.disable']);
            }
        }

        $this->fail("[{$property}] was written from the browser.");
    }

    private function assertNothingSentFor(string $app): void
    {
        Http::assertNotSent(fn (Request $r) => str_contains((string) parse_url($r->url(), PHP_URL_PATH), '/api/apps/'.$app));
    }

    #[Test]
    public function a_view_grant_cannot_be_widened_from_the_browser(): void
    {
        // The report: `view` on shop, nothing on blog.
        Access::replaceGrants($this->user->id, [], [$this->prod->id => ['shop' => ['view']]]);

        $page = $this->shop()->assertSet('can.view', true)->assertSet('can.delete', false);

        $everything = array_fill_keys(Access::APP_ABILITIES, true);
        $this->assertLocked($page, 'can', $everything);
        $this->assertLocked($page, 'can.delete', true);
        $this->assertLocked($page, 'can.env', true);
        $this->assertLocked($page, 'appName', 'blog');
        $this->assertLocked($page, 'serverId', $this->staging->id);

        // The page is what it was, and the actions of the report are refused.
        $page->assertSet('appName', 'shop')->assertSet('serverId', $this->prod->id)->assertSet('can.delete', false);

        $page->call('loadEnv')->assertSet('error', self::DENIED)->assertSet('envLoaded', false);
        $page->set('deleteConfirmation', 'shop')->call('deleteApp')->assertSet('error', self::DENIED);
        $page->set('artisanCommand', 'about')->call('runArtisan')->assertSet('error', self::DENIED)->assertSet('showJobOverlay', false);
        $page->call('saveApp')->assertSet('error', self::DENIED);
        $page->call('recreateWebhook')->assertSet('error', self::DENIED);

        Http::assertNotSent(fn (Request $r) => $r->method() !== 'GET');
        Http::assertNotSent(fn (Request $r) => str_ends_with((string) parse_url($r->url(), PHP_URL_PATH), '/env'));
        $this->assertNothingSentFor('blog');
    }

    #[Test]
    public function actions_follow_the_grants_of_the_moment_not_the_page(): void
    {
        Access::replaceGrants($this->user->id, [], [$this->prod->id => ['shop' => ['deploy']]]);

        $page = $this->shop()->assertSet('can.deploy', true);

        // An admin takes Deploy away while the page is open.
        Access::replaceGrants($this->user->id, [], [$this->prod->id => ['shop' => ['view']]]);

        $page->call('deploy')
            ->assertSet('error', self::DENIED)
            ->assertSet('can.deploy', false)
            ->assertSet('showJobOverlay', false);
        Http::assertNotSent(fn (Request $r) => $r->method() === 'POST');

        // Given back: the same page deploys again, with no reload.
        Access::replaceGrants($this->user->id, [], [$this->prod->id => ['shop' => ['deploy']]]);
        $page->call('deploy')->assertSet('can.deploy', true)->assertSet('showJobOverlay', true);
        Http::assertSent(fn (Request $r) => $r->method() === 'POST' && str_ends_with($r->url(), '/api/apps/shop/deploy'));
    }

    #[Test]
    public function a_user_who_lost_the_app_is_out_of_the_page_they_had_open(): void
    {
        Access::replaceGrants($this->user->id, [], [$this->prod->id => ['shop' => ['view', 'deploy']]]);

        $page = $this->shop();
        $reads = Http::recorded()->count();

        Access::replaceGrants($this->user->id, [], []);

        $page->call('loadApp')->assertForbidden();
        $this->assertSame($reads, Http::recorded()->count(), 'nothing is asked of the server for an app the user may not view');
    }

    #[Test]
    public function a_user_with_every_ability_works_as_before(): void
    {
        // The control: a managed server gives every ability on every app, and the page works as before.
        Access::replaceGrants($this->user->id, [$this->prod->id => 'manage'], []);

        $page = $this->shop()->assertSet('can.delete', true)->assertSet('can.env', true);

        $page->call('setTab', 'deploy')->call('deploy')->assertSet('showJobOverlay', true)->assertSet('activeJobId', 'job-1');
        $page->call('pollJob')->assertSet('jobRunning', false)->assertSet('activeJobStatus', 'completed');
        $page->call('dismissJob')->assertSet('activeJobId', null);

        Http::assertSent(fn (Request $r) => $r->method() === 'POST' && str_ends_with($r->url(), '/api/apps/shop/deploy'));
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/api/jobs/job-1'));
    }

    #[Test]
    public function the_job_being_polled_cannot_be_chosen_from_the_browser(): void
    {
        Access::replaceGrants($this->user->id, [], [$this->prod->id => ['shop' => ['deploy']]]);

        $page = $this->shop();

        // Nothing started: no job is read, whatever the page is told.
        $this->assertLocked($page, 'activeJobId', 'job-of-someone-else');
        $this->assertLocked($page, 'jobRunning', true);
        $page->call('pollJob')->assertSet('activeJobId', null)->assertSet('activeJobOutput', null);

        // A job of its own: still the only one it reads.
        $page->call('deploy')->assertSet('activeJobId', 'job-1')->assertSet('jobRunning', true);
        $this->assertLocked($page, 'activeJobId', 'job-of-someone-else');
        $page->call('pollJob')->assertSet('activeJobId', 'job-1');

        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'job-of-someone-else'));
    }

    #[Test]
    public function the_log_viewer_reads_only_the_logs_the_user_may_read(): void
    {
        Access::replaceGrants($this->user->id, [], [$this->prod->id => ['shop' => ['logs'], 'blog' => ['view']]]);

        // The viewer reads after it is on the page (4.2.0): loadLogs() is what wire:init calls.
        $viewer = Livewire::test(LogViewer::class, ['app' => 'shop', 'serverId' => $this->prod->id])->call('loadLogs')->assertSee('deploy OK');

        $this->assertLocked($viewer, 'appName', 'blog');
        $this->assertLocked($viewer, 'serverId', $this->staging->id);
        $viewer->call('refresh')->assertSee('deploy OK')->assertDontSee('blog secret line');

        // `view` on blog is not `logs` on blog: a viewer on it reads nothing.
        Livewire::test(LogViewer::class, ['app' => 'blog', 'serverId' => $this->prod->id])
            ->call('loadLogs')
            ->assertSet('error', self::DENIED)
            ->assertSet('files', [])
            ->assertDontSee('blog secret line');
        $this->assertNothingSentFor('blog');

        // And `logs` taken away stops the viewer that is already open.
        Access::replaceGrants($this->user->id, [], [$this->prod->id => ['shop' => ['view']]]);
        $reads = Http::recorded()->count();
        $viewer->call('refresh')->assertSet('error', self::DENIED)->assertSet('files', [])->assertSet('autoRefresh', false);
        $this->assertSame($reads, Http::recorded()->count());
    }

    #[Test]
    public function an_app_grant_alone_does_not_open_the_server_page_without_an_id(): void
    {
        Access::replaceGrants($this->user->id, [], [$this->prod->id => ['blog' => ['view']]]);

        // The app page leaves its server in the session; /server used to pick it up from there.
        $this->withSession(['cipi_gui_server_id' => $this->prod->id])->get('/server')
            ->assertOk()->assertSee('No server selected')->assertDontSee('fra1');
        // The page loads its data after it is sent (wire:init → init()): that call asks nothing either.
        session(['cipi_gui_server_id' => $this->prod->id]);
        Livewire::test(ServerManage::class)->call('init')->assertSee('No server selected')->assertDontSee('fra1');
        Http::assertNotSent(fn (Request $r) => str_ends_with((string) parse_url($r->url(), PHP_URL_PATH), '/api/status'));

        // A level on the server still opens it, with or without the id in the URL.
        Access::replaceGrants($this->user->id, [$this->prod->id => 'view'], []);
        // The page is sent before the server is asked (4.2.0): its data arrives with init().
        $this->withSession(['cipi_gui_server_id' => $this->prod->id])->get('/server')->assertOk()->assertSeeHtml('wire:init="init"');
        $page = Livewire::test(ServerManage::class, ['serverId' => $this->prod->id])->call('init')->assertSee('fra1');
        $this->assertLocked($page, 'serverId', $this->staging->id);
    }

    #[Test]
    public function team_and_connections_ask_for_an_admin_on_every_request(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin);

        $team = Livewire::test(Team::class)->call('openInvite')->assertSet('showInvite', true);
        $connections = Livewire::test(Servers::class)->call('openAdd')->assertSet('showFormModal', true);

        // No longer an admin, with both pages still open.
        $admin->update(['role' => 'user']);
        Access::flush();

        $team->call('closeInvite')->assertForbidden();
        $connections->call('closeForm')->assertForbidden();
    }

    #[Test]
    public function the_release_that_closed_this_is_4_2_0(): void
    {
        $this->assertTrue(version_compare(Theme::VERSION, '4.2.0', '>='));
        $changelog = (string) file_get_contents(Theme::packageRoot().'/CHANGELOG.md');
        $this->assertStringContainsString('## [4.2.0]', $changelog);
        $this->assertStringContainsString('asked of the grants at every request', $changelog);
    }
}
