<?php

namespace CipiGui\Tests\Feature;

use CipiGui\Livewire\AppDetail;
use CipiGui\Livewire\Apps;
use CipiGui\Livewire\ServerManage;
use CipiGui\Models\CipiServer;
use CipiGui\Support\PhpVersions;
use CipiGui\Tests\TestCase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;

/**
 * Legacy PHP (7.4–8.2) on a server installed before Cipi 4.5.4: still there,
 * with apps on it. The panel shows it where it is, lets an app that runs on it
 * keep it, and offers it to no other — the API refuses that.
 */
class LegacyPhpTest extends TestCase
{
    /** @var array<string, mixed> Answer of GET /api/php: the `php` command of this server is still 8.2. */
    private array $php = [
        'default' => '8.2',
        'installable' => ['8.3', '8.4', '8.5', '8.6'],
        'versions' => [
            ['version' => '8.1', 'status' => 'running', 'apps' => 1, 'default' => false],
            ['version' => '8.2', 'status' => 'running', 'apps' => 0, 'default' => true],
            ['version' => '8.4', 'status' => 'running', 'apps' => 0, 'default' => false],
            ['version' => '8.5', 'status' => 'running', 'apps' => 2, 'default' => false],
        ],
    ];

    /** @var array<string, mixed> An app that was never moved off 8.1. */
    private array $old = ['app' => 'old', 'domain' => 'old.example.com', 'php' => '8.1', 'engine' => 'mariadb', 'node' => false, 'aliases' => [], 'repository' => 'git@github.com:acme/old.git', 'branch' => 'main'];

    private CipiServer $server;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake(function (Request $request) {
            $path = parse_url($request->url(), PHP_URL_PATH);

            return match (true) {
                $request->method() !== 'GET' => Http::response(['job_id' => 'job-1', 'status' => 'pending'], 202),
                $path === '/api/php' => Http::response(['data' => $this->php]),
                $path === '/api/apps/old' => Http::response(['data' => $this->old]),
                $path === '/api/dbs/engines' => Http::response(['data' => ['default' => 'mariadb', 'engines' => [['engine' => 'mariadb', 'status' => 'running', 'default' => true]]]]),
                default => Http::response(['data' => []]),
            };
        });

        $this->server = $this->server();
        $this->actingAs($this->admin());
        session(['cipi_gui_server_id' => $this->server->id]);
    }

    /** @return array<string, mixed>|null */
    private function sent(string $method, string $path): ?array
    {
        $sent = Http::recorded(fn (Request $r) => $r->method() === $method && parse_url($r->url(), PHP_URL_PATH) === $path);

        return $sent->isEmpty() ? null : $sent->last()[0]->data();
    }

    #[Test]
    public function legacy_is_what_is_installed_and_older_than_everything_installable(): void
    {
        $this->assertSame(['8.1', '8.2'], PhpVersions::legacy($this->php));
        $this->assertSame(['8.4', '8.5'], PhpVersions::assignable($this->php));

        // A version newer than the list (a core that knows more than this API) is not legacy.
        $newer = ['installable' => ['8.3', '8.4', '8.5'], 'versions' => [['version' => '8.2'], ['version' => '8.6']]];
        $this->assertSame(['8.2'], PhpVersions::legacy($newer));
        $this->assertSame(['8.6'], PhpVersions::assignable($newer));

        // No list from the server: nothing is called legacy, everything installed is offered as before.
        $this->assertSame([], PhpVersions::legacy(['versions' => [['version' => '8.1'], ['version' => '8.5']]]));
        $this->assertSame(['8.1', '8.5'], PhpVersions::assignable(['versions' => [['version' => '8.1'], ['version' => '8.5']]]));
    }

    #[Test]
    public function the_server_page_lists_a_legacy_version_marks_it_and_does_not_offer_to_install_one(): void
    {
        Livewire::withQueryParams(['tab' => 'php'])->test(ServerManage::class, ['serverId' => $this->server->id])->call('init')
            ->assertSeeHtml('data-php-legacy="8.1">legacy</span>')
            ->assertSeeHtml('data-php-legacy="8.2">legacy</span>')
            ->assertDontSeeHtml('data-php-legacy="8.4"')
            ->assertDontSeeHtml('data-php-legacy="8.5"')
            ->assertSee('PHP 8.1, 8.2: legacy, installed before Cipi 4.5.4')
            ->assertSeeHtml('<option value="8.3">PHP 8.3</option>')
            ->assertSeeHtml('<option value="8.6">PHP 8.6</option>')
            ->assertDontSeeHtml('<option value="8.1">')
            ->assertDontSeeHtml('<option value="8.2">');
    }

    #[Test]
    public function a_server_without_legacy_versions_says_nothing_about_them(): void
    {
        $this->php['versions'] = [['version' => '8.5', 'status' => 'running', 'apps' => 2, 'default' => true]];

        Livewire::withQueryParams(['tab' => 'php'])->test(ServerManage::class, ['serverId' => $this->server->id])->call('init')
            ->assertDontSeeHtml('data-php-legacy')
            ->assertDontSee('legacy');
    }

    #[Test]
    public function a_new_app_is_not_offered_a_legacy_version_even_when_it_is_the_servers_default(): void
    {
        $form = Livewire::test(Apps::class)->call('openCreate')
            ->assertSet('php', '8.5')
            ->assertSeeHtml('<option value="8.4">PHP 8.4</option>')
            ->assertSeeHtml('<option value="8.5">PHP 8.5</option>')
            ->assertDontSeeHtml('<option value="8.1">')
            ->assertDontSeeHtml('<option value="8.2">')
            ->assertSeeHtml('data-php-legacy')
            ->assertSee('PHP 8.1, 8.2 are installed too, but legacy');

        $form->set('user', 'shop')->set('domain', 'shop.example.com')->set('repository', 'git@github.com:acme/shop.git')
            ->call('createApp')
            ->assertHasNoErrors();

        $this->assertSame('8.5', $this->sent('POST', '/api/apps')['php']);
    }

    #[Test]
    public function an_app_on_a_legacy_version_keeps_it_while_something_else_is_edited(): void
    {
        $page = Livewire::test(AppDetail::class, ['name' => 'old'])
            ->assertSet('editPhp', '8.1')
            ->assertSeeHtml('<option value="8.1">PHP 8.1 (legacy)</option>')
            ->assertSeeHtml('<option value="8.5">PHP 8.5</option>')
            ->assertDontSeeHtml('<option value="8.2">')
            ->assertSeeHtml('data-php-legacy')
            ->assertSee('PHP 8.1 is a legacy version');

        $page->set('editBranch', 'release')->call('saveApp')->assertHasNoErrors()->assertSet('showJobOverlay', true);

        $this->assertSame(['branch' => 'release'], $this->sent('PUT', '/api/apps/old'));
    }

    #[Test]
    public function it_can_be_moved_to_a_current_version_and_to_no_other_legacy_one(): void
    {
        $page = Livewire::test(AppDetail::class, ['name' => 'old']);

        $page->set('editPhp', '8.2')->call('saveApp')
            ->assertHasErrors(['editPhp'])
            ->assertSee('PHP 8.2 is a legacy version: an app cannot be moved to it. Pick: 8.4, 8.5.');
        $page->set('editPhp', '8.3')->call('saveApp')->assertHasErrors(['editPhp'])->assertSee('PHP 8.3 is not installed on this server');
        $this->assertNull($this->sent('PUT', '/api/apps/old'));

        $page->set('editPhp', '8.5')->call('saveApp')->assertHasNoErrors()->assertSet('showJobOverlay', true);
        $this->assertSame(['php' => '8.5'], $this->sent('PUT', '/api/apps/old'));
    }

    #[Test]
    public function an_app_on_a_current_version_is_not_offered_the_legacy_ones(): void
    {
        $this->old['php'] = '8.5';

        Livewire::test(AppDetail::class, ['name' => 'old'])
            ->assertSet('editPhp', '8.5')
            ->assertSeeHtml('<option value="8.4">PHP 8.4</option>')
            ->assertDontSeeHtml('<option value="8.1">')
            ->assertDontSeeHtml('<option value="8.2">')
            ->assertDontSeeHtml('data-php-legacy');
    }

    #[Test]
    public function a_server_that_sends_no_installable_list_is_shown_as_before(): void
    {
        unset($this->php['installable']);

        Livewire::test(AppDetail::class, ['name' => 'old'])
            ->assertSeeHtml('<option value="8.1">PHP 8.1</option>')
            ->assertSeeHtml('<option value="8.2">PHP 8.2</option>')
            ->assertDontSeeHtml('data-php-legacy');
    }
}
