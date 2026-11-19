<?php

namespace CipiGui\Tests\Feature;

use CipiGui\Livewire\Apps;
use CipiGui\Livewire\ServerManage;
use CipiGui\Models\CipiServer;
use CipiGui\Tests\TestCase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;

/**
 * PHP 8.6 (Cipi 5.9.1, API 1.39.2). The panel keeps no list of its own: what
 * can be installed and what an app can run on is what `GET /api/php` answers.
 */
class Php86Test extends TestCase
{
    /** @var array<string, mixed> Answer of GET /api/php */
    private array $php = ['default' => '8.5', 'installable' => ['8.3', '8.4', '8.5', '8.6'], 'versions' => [['version' => '8.5', 'status' => 'running', 'apps' => 1, 'default' => true]]];

    /** @var array{0: array<string, mixed>, 1: int} Answer of POST /api/php/install */
    private array $install = [['job_id' => 'job-1', 'status' => 'pending'], 202];

    private CipiServer $server;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake(function (Request $request) {
            $path = parse_url($request->url(), PHP_URL_PATH);

            return match (true) {
                $path === '/api/php/install' => Http::response(...$this->install),
                $request->method() === 'POST' => Http::response(['job_id' => 'job-1', 'status' => 'pending'], 202),
                $path === '/api/php' => Http::response(['data' => $this->php]),
                $path === '/api/dbs/engines' => Http::response(['data' => ['default' => 'mariadb', 'engines' => [['engine' => 'mariadb', 'status' => 'running', 'default' => true]]]]),
                default => Http::response(['data' => []]),
            };
        });

        $this->server = $this->server();
        $this->actingAs($this->admin());
        session(['cipi_gui_server_id' => $this->server->id]);
    }

    /** @return array<string, mixed>|null */
    private function posted(string $path): ?array
    {
        $sent = Http::recorded(fn (Request $r) => $r->method() === 'POST' && parse_url($r->url(), PHP_URL_PATH) === $path);

        return $sent->isEmpty() ? null : $sent->last()[0]->data();
    }

    private function phpTab(): \Livewire\Features\SupportTesting\Testable
    {
        return Livewire::withQueryParams(['tab' => 'php'])->test(ServerManage::class, ['serverId' => $this->server->id])->call('init');
    }

    #[Test]
    public function the_server_page_offers_and_installs_what_the_server_lists(): void
    {
        $this->phpTab()
            ->assertSeeHtml('<option value="8.6">PHP 8.6</option>')
            ->assertDontSeeHtml('<option value="8.5">')
            ->set('phpInstallVersion', '8.6')
            ->call('installPhp')
            ->assertHasNoErrors()
            ->assertSet('showJobOverlay', true)
            ->assertSet('jobLabel', 'Install PHP 8.6');

        $this->assertSame(['version' => '8.6'], $this->posted('/api/php/install'));
    }

    #[Test]
    public function a_server_that_does_not_list_it_does_not_offer_it(): void
    {
        // A core older than 5.9.1 answers its own list; an API that sends none gets the panel's.
        foreach ([['8.3', '8.4', '8.5'], []] as $installable) {
            $this->php['installable'] = $installable;

            $this->phpTab()->assertSeeHtml('<option value="8.4">PHP 8.4</option>')->assertDontSeeHtml('<option value="8.6">');
        }
    }

    #[Test]
    public function the_reason_an_older_core_gives_is_shown(): void
    {
        $this->install = [['error' => 'PHP 8.6 needs Cipi 5.9.1+ — this server runs Cipi 5.9.0. Run: cipi self-update'], 422];

        $this->phpTab()->set('phpInstallVersion', '8.6')->call('installPhp')
            ->assertSet('showJobOverlay', false)
            ->assertSet('error', fn ($error) => str_contains((string) $error, 'PHP 8.6 needs Cipi 5.9.1+'));
    }

    #[Test]
    public function a_new_app_keeps_the_servers_default_and_can_be_put_on_8_6(): void
    {
        $this->php['versions'][] = ['version' => '8.6', 'status' => 'running', 'apps' => 0, 'default' => false];

        $form = Livewire::test(Apps::class)->call('openCreate')
            ->assertSet('php', '8.5')
            ->assertSeeHtml('<option value="8.5">PHP 8.5 · default</option>')
            ->assertSeeHtml('<option value="8.6">PHP 8.6</option>')
            ->set('user', 'shop')->set('domain', 'shop.example.com')->set('repository', 'git@github.com:acme/shop.git')
            ->set('php', '8.6')
            ->call('createApp')
            ->assertHasNoErrors();

        $form->assertSet('showJobOverlay', true);
        $this->assertSame('8.6', $this->posted('/api/apps')['php']);
    }
}
