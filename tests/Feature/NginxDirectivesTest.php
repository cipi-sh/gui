<?php

namespace CipiGui\Tests\Feature;

use CipiGui\Livewire\AppDetail;
use CipiGui\Models\CipiServer;
use CipiGui\Services\Access;
use CipiGui\Tests\TestCase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;

/**
 * Routing tab → Nginx directives (4.6.0, cipi/api 1.44, Cipi 5.14.0): the text
 * an app adds to its own server block. For whoever manages the server; a
 * refused text is shown with its reasons and changes nothing.
 */
class NginxDirectivesTest extends TestCase
{
    private CipiServer $server;

    /** The directives the server holds for shop. */
    private string $config = '';

    /** @var (\Closure(Request): mixed)|null answers a write instead of taking it */
    private ?\Closure $refuse = null;

    /** @var (\Closure(): mixed)|null answers the read */
    private ?\Closure $read = null;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake(function (Request $request) {
            $path = parse_url($request->url(), PHP_URL_PATH);

            if ($path === '/api/apps/shop/nginx') {
                if ($request->method() === 'GET') {
                    return $this->read ? ($this->read)() : Http::response(['data' => $this->directives()]);
                }
                if ($this->refuse) {
                    return ($this->refuse)($request);
                }
                $this->config = $request->method() === 'DELETE' ? '' : (string) ($request->data()['config'] ?? '');

                return Http::response(['data' => $this->directives()]);
            }

            return match (true) {
                $path === '/api/apps/shop' => Http::response(['data' => ['app' => 'shop', 'domain' => 'shop.example.com', 'php' => '8.5', 'engine' => 'mariadb', 'node' => false, 'aliases' => [], 'repository' => 'git@github.com:acme/shop.git', 'branch' => 'main']]),
                $path === '/api/apps/shop/redirects' => Http::response(['data' => ['app' => 'shop', 'redirect' => null, 'redirects' => []]]),
                $path === '/api/apps/shop/proxies' => Http::response(['data' => ['app' => 'shop', 'proxies' => []]]),
                $path === '/api/php' => Http::response(['data' => ['default' => '8.5', 'versions' => [['version' => '8.5']]]]),
                default => Http::response(['data' => []]),
            };
        });

        $this->server = $this->server();
        $this->actingAs($this->admin());
        session(['cipi_gui_server_id' => $this->server->id]);
    }

    /** @return array<string, mixed> */
    private function directives(): array
    {
        return ['app' => 'shop', 'config' => $this->config, 'bytes' => strlen($this->config), 'max_bytes' => 32768, 'active' => $this->config !== '', 'suspended' => false];
    }

    /** @return list<Request> the writes that reached the server */
    private function writes(): array
    {
        return Http::recorded(fn (Request $r) => $r->method() !== 'GET' && parse_url($r->url(), PHP_URL_PATH) === '/api/apps/shop/nginx')
            ->map(fn (array $pair) => $pair[0])->values()->all();
    }

    private function routing(): \Livewire\Features\SupportTesting\Testable
    {
        return Livewire::test(AppDetail::class, ['name' => 'shop'])->call('setTab', 'routing');
    }

    #[Test]
    public function whoever_manages_the_server_reads_and_saves_the_directives(): void
    {
        $this->config = "expires 1d;\n";

        $page = $this->routing()
            ->assertSet('nginxLoaded', true)
            ->assertSet('nginxConfig', "expires 1d;\n")
            ->assertSeeHtml('data-nginx-directives')
            ->assertSeeHtml('wire:model="nginxConfig"')
            ->assertSeeHtml('<span class="badge badge-green">Live</span>')
            ->assertSee('Save and reload nginx');

        $page->set('nginxConfig', "client_max_body_size 512M;\r\nlocation ^~ /downloads/ {\r\n    expires 1y;\r\n}\r\n")
            ->call('saveNginx')
            ->assertSet('nginxReasons', [])
            ->assertSet('nginxSaved', "client_max_body_size 512M;\nlocation ^~ /downloads/ {\n    expires 1y;\n}\n")
            ->assertDispatched('notify');

        $writes = $this->writes();
        $this->assertCount(1, $writes);
        $this->assertSame('PUT', $writes[0]->method());
        // Line ends are sent as LF.
        $this->assertSame(['config' => "client_max_body_size 512M;\nlocation ^~ /downloads/ {\n    expires 1y;\n}\n"], $writes[0]->data());

        // Remove, and an emptied text, both take the directives away.
        $page->call('resetNginx')->assertSet('nginxSaved', '')->assertDontSeeHtml('<span class="badge badge-green">Live</span>');
        $this->assertSame('DELETE', $this->writes()[1]->method());
        $this->config = "expires 1d;\n";
        $this->routing()->set('nginxConfig', "  \n")->call('saveNginx')->assertSet('nginxSaved', '');
        $this->assertSame('DELETE', $this->writes()[2]->method());
    }

    #[Test]
    public function a_refused_text_is_shown_with_its_reasons_and_stays_in_the_form(): void
    {
        $this->config = "expires 1d;\n";
        $this->refuse = fn () => Http::response([
            'error' => 'nginx refused the directives — nothing changed',
            'reasons' => ['nginx refused the directives — nothing changed', 'line 2: unknown directive "boom"'],
        ], 422);

        $this->routing()
            ->set('nginxConfig', "expires 7d;\nboom;\n")
            ->call('saveNginx')
            ->assertSet('nginxReasons', ['nginx refused the directives — nothing changed', 'line 2: unknown directive "boom"'])
            ->assertSeeHtml('data-nginx-refused')
            ->assertSee('line 2: unknown directive "boom"')
            // What was typed is still there to fix; what the server has did not change.
            ->assertSet('nginxConfig', "expires 7d;\nboom;\n")
            ->assertSet('nginxSaved', "expires 1d;\n")
            ->assertNotDispatched('notify');

        // A 422 without reasons (validation): its message.
        $this->refuse = fn () => Http::response(['error' => 'The directives are too long (32 KB at most).'], 422);
        $this->routing()->set('nginxConfig', 'expires 7d;')->call('saveNginx')
            ->assertSet('nginxReasons', ['The directives are too long (32 KB at most).']);
    }

    #[Test]
    public function too_long_or_with_a_control_character_never_leaves_the_panel(): void
    {
        $this->routing()->set('nginxConfig', str_repeat('#', 32769))->call('saveNginx')
            ->assertSet('nginxReasons', ['The directives are too long (32 KB at most).']);
        $this->routing()->set('nginxConfig', "expires 1d;\x01")->call('saveNginx')
            ->assertSet('nginxReasons', ['The directives hold a control character.']);

        $this->assertSame([], $this->writes());
    }

    #[Test]
    public function an_app_ability_is_not_enough_it_takes_manage_on_the_server(): void
    {
        $this->config = "expires 1d;\n";
        $member = $this->member();

        // Every ability on the app, View on the server: no card, and the actions refuse.
        Access::replaceGrants($member->id, [$this->server->id => 'view'], [$this->server->id => ['shop' => ['view', 'deploy', 'edit', 'env', 'logs', 'run', 'delete']]]);
        Access::flush();
        $this->actingAs($member);

        $this->routing()
            ->assertSet('nginxLoaded', false)
            ->assertDontSeeHtml('data-nginx-directives')
            ->assertDontSee('expires 1d;')
            ->set('nginxConfig', 'listen 81;')
            ->call('saveNginx')
            ->call('resetNginx');

        Http::assertNotSent(fn (Request $r) => parse_url($r->url(), PHP_URL_PATH) === '/api/apps/shop/nginx');

        // Manage on the server: the card.
        Access::replaceGrants($member->id, [$this->server->id => 'manage'], []);
        Access::flush();
        $this->routing()->assertSeeHtml('data-nginx-directives')->assertSet('nginxConfig', "expires 1d;\n");
    }

    #[Test]
    public function a_server_that_cannot_do_it_says_what_it_needs(): void
    {
        // cipi/api before 1.44: no route.
        $this->read = fn () => Http::response(['message' => 'The route api/apps/shop/nginx could not be found.'], 404);
        $this->routing()
            ->assertSet('nginxLoaded', false)
            ->assertSeeHtml('data-nginx-unavailable')
            ->assertSee('needs an up-to-date Cipi on this server')
            ->assertDontSeeHtml('wire:model="nginxConfig"');

        // A token created before the abilities existed.
        $this->read = fn () => Http::response(['message' => 'Invalid ability provided.'], 403);
        $this->routing()->assertSee('lacks the nginx-view and nginx-manage abilities');

        // cipi/api 1.44 on a Cipi before 5.14.0: the API's own words.
        $this->read = fn () => Http::response(['error' => 'This server cannot edit nginx directives yet: Cipi 5.14.0+ is required (cipi self-update, then cipi api update).'], 503);
        $this->routing()->assertSee('an update of Cipi is required')->assertDontSee('5.14.0');

        // The rest of the tab is there all the same.
        $this->routing()->assertSee('Prefix reverse proxies');
    }
}
