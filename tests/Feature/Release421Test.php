<?php

namespace CipiGui\Tests\Feature;

use CipiGui\Livewire\ServerManage;
use CipiGui\Livewire\Settings;
use CipiGui\Models\CipiServer;
use CipiGui\Services\TwoFactorService;
use CipiGui\Support\Theme;
use CipiGui\Tests\TestCase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;

/**
 * 4.2.1 — three things the panel said that were not true: "Backups are not
 * configured" for any answer it could not read, "Cipi API 1.31 ready" in
 * Settings, and "Laravel" in the browser tab.
 */
class Release421Test extends TestCase
{
    private CipiServer $server;

    /** @var array<string, array{0: mixed, 1: int}> path => [body, status], what the fake API answers */
    private array $answers = [];

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake(function (Request $request) {
            $path = (string) parse_url($request->url(), PHP_URL_PATH);
            if (isset($this->answers[$path])) {
                [$body, $status] = $this->answers[$path];

                return Http::response($body, $status);
            }

            return match ($path) {
                '/api/backups' => Http::response(['data' => $this->configured()]),
                '/api/status' => Http::response(['data' => ['system' => ['hostname' => 'fra1', 'cipi' => '5.9.0'], 'resources' => [], 'services' => ['nginx' => 'running'], 'apps' => 1]]),
                '/api/dbs/engines' => Http::response(['data' => ['default' => 'mariadb', 'engines' => [['engine' => 'mariadb', 'status' => 'running', 'port' => 3306, 'default' => true]]]]),
                default => Http::response(['data' => []]),
            };
        });

        $this->server = $this->server();
        $this->actingAs($this->admin());
        session(['cipi_gui_server_id' => $this->server->id]);
    }

    /** @return array<string, mixed> a configured server with one profile */
    private function configured(): array
    {
        return [
            'configured' => true, 'local_dir' => '/var/backups/cipi', 'encryption_key' => false,
            'destinations' => [['name' => 'local', 'type' => 'local', 'path' => '/var/backups/cipi', 'default' => false, 'profiles' => ['nightly']]],
            'profiles' => [[
                'name' => 'nightly', 'scope' => 'all', 'enabled' => true, 'cron' => '0 2 * * *', 'interval_seconds' => 86400,
                'apps' => ['*'], 'databases' => ['*'], 'exclude_databases' => [], 'exclude_tables' => [],
                'destinations' => ['local'], 'retention' => ['keep' => 0, 'days' => 28, 'weeks' => 0], 'encrypt' => false, 'created_at' => time() - 900000,
                'covers' => ['apps' => ['shop'], 'databases' => [['engine' => 'mariadb', 'name' => 'shop']]],
                'last' => null, 'overdue' => false, 'running' => false,
            ]],
            'apps' => ['shop'], 'databases' => [['engine' => 'mariadb', 'name' => 'shop']],
            'uncovered' => ['apps' => [], 'databases' => []],
        ];
    }

    private function backupsTab()
    {
        return Livewire::withQueryParams(['tab' => 'backups'])->test(ServerManage::class, ['serverId' => $this->server->id])->call('init');
    }

    // ── Backups: "not configured" is the server's to say ──────────────

    #[Test]
    public function a_configured_server_shows_its_profiles(): void
    {
        $this->backupsTab()
            ->assertSee('nightly')
            ->assertDontSee('Backups are not configured on this server')
            ->assertDontSee('could not be read')
            ->assertSet('tabError', []);
    }

    #[Test]
    public function not_configured_is_shown_when_the_server_says_so_with_where_it_looked(): void
    {
        $this->answers['/api/backups'] = [['data' => ['configured' => false, 'host' => 'fra1.example.com', 'config_file' => '/etc/cipi/backup.json',
            'destinations' => [], 'profiles' => [], 'apps' => [], 'databases' => [], 'uncovered' => ['apps' => [], 'databases' => []]]], 200];

        $this->backupsTab()
            ->assertSee('Backups are not configured on this server')
            ->assertSee('fra1.example.com')
            ->assertSee('ls -l /etc/cipi/backup.json')
            ->assertSee('Check again')
            ->assertDontSee('could not be read');
    }

    #[Test]
    public function not_configured_from_an_api_that_does_not_say_where_it_looked_still_names_the_file(): void
    {
        // API 1.39.0: the flag alone.
        $this->answers['/api/backups'] = [['data' => ['configured' => false]], 200];

        $this->backupsTab()
            ->assertSee('Backups are not configured on this server')
            ->assertSee('/etc/cipi/backup.json');
    }

    #[Test]
    public function an_answer_without_a_status_is_a_failed_read_not_an_unconfigured_server(): void
    {
        // 200 with nothing the page can use: an empty "data", no "data", a page that is not JSON.
        foreach ([[['data' => []], 200], [[], 200], ['<html><body>It works</body></html>', 200]] as $answer) {
            $this->answers['/api/backups'] = $answer;

            $this->backupsTab()
                ->assertDontSee('Backups are not configured on this server')
                ->assertSee('The backup status could not be read')
                ->assertSee('answered without a backup status')
                ->assertSee('Try again');
        }
    }

    #[Test]
    public function a_read_that_fails_is_shown_as_one_with_what_the_server_answered(): void
    {
        foreach ([500 => 'Server Error', 502 => 'Bad Gateway', 429 => 'Too Many Attempts.', 422 => 'cipi backup status failed'] as $status => $message) {
            $this->answers['/api/backups'] = [['message' => $message], $status];

            $this->backupsTab()
                ->assertDontSee('Backups are not configured on this server')
                ->assertSee('The backup status could not be read')
                ->assertSee('HTTP '.$status)
                ->assertDontSee('New profile');
        }
    }

    #[Test]
    public function trying_again_reads_the_server_again(): void
    {
        $this->answers['/api/backups'] = [['message' => 'Server Error'], 500];
        $page = $this->backupsTab()->assertSee('The backup status could not be read');

        unset($this->answers['/api/backups']);
        $page->call('refresh')
            ->assertDontSee('The backup status could not be read')
            ->assertSee('nightly')
            ->assertSet('tabError', []);
    }

    #[Test]
    public function a_poll_that_fails_keeps_what_is_shown(): void
    {
        $page = $this->backupsTab()->assertSee('nightly');

        $this->answers['/api/backups'] = [['data' => []], 200];
        $page->call('refreshBackups')
            ->assertSee('nightly')
            ->assertDontSee('could not be read');
    }

    #[Test]
    public function a_section_the_server_refuses_says_what_the_server_answered(): void
    {
        $reason = 'Backup management from the panel needs Cipi 5.9.0+ — this server runs Cipi 5.8.5. Run: cipi self-update';
        $this->answers['/api/backups'] = [['error' => $reason], 503];

        $this->backupsTab()
            ->assertSee('Not available on this server')
            ->assertSee($reason)
            ->assertSee('Check again')
            ->assertDontSee('Backups are not configured on this server')
            ->assertDontSee('could not be read');

        // The sudoers rule the panel runs cipi through is older than the command.
        $this->answers['/api/backups'] = [['error' => 'sudo: a password is required'], 503];
        $this->backupsTab()->assertSee('Not available on this server')->assertSee('sudo: a password is required');
    }

    #[Test]
    public function another_section_whose_read_fails_says_so_above_what_it_shows(): void
    {
        $this->answers['/api/dbs/engines'] = [['message' => 'Server Error'], 500];

        Livewire::withQueryParams(['tab' => 'engines'])->test(ServerManage::class, ['serverId' => $this->server->id])->call('init')
            ->assertSee('This section could not be read')
            ->assertSee('HTTP 500');

        unset($this->answers['/api/dbs/engines']);
        Livewire::withQueryParams(['tab' => 'engines'])->test(ServerManage::class, ['serverId' => $this->server->id])->call('init')
            ->assertDontSee('This section could not be read')
            ->assertSee('MariaDB')->assertSee('port 3306');
    }

    // ── Versions: what a server runs, not a number typed into a view ──

    #[Test]
    public function the_server_page_shows_the_api_version_the_server_reports(): void
    {
        Livewire::test(ServerManage::class, ['serverId' => $this->server->id])->call('init')
            ->assertSee('Cipi 5.9.0')
            ->assertDontSee('· API');   // API 1.39.0 and older do not report theirs

        $this->answers['/api/status'] = [['data' => ['system' => ['hostname' => 'fra1', 'cipi' => '5.9.0', 'api' => '1.39.1'], 'resources' => [], 'services' => [], 'apps' => 1]], 200];
        Livewire::test(ServerManage::class, ['serverId' => $this->server->id])->call('init')
            ->assertSee('Cipi 5.9.0')
            ->assertSee('API 1.39.1');
    }

    #[Test]
    public function settings_no_longer_claim_an_api_version_nobody_checked(): void
    {
        Livewire::test(Settings::class)
            ->assertDontSee('1.31 ready')
            // 4.7.0: no API version claimed at all — the panel is version-agnostic.
            ->assertDontSee('Built for Cipi API')
            ->assertDontSee(Theme::API.'+')
            ->assertSee('Each server shows the Cipi and API versions it runs');

        $this->assertMatchesRegularExpression('/^\d+\.\d+$/', Theme::API);
        $this->assertStringNotContainsString('1.31 ready', (string) file_get_contents(Theme::packageRoot().'/resources/views/livewire/settings.blade.php'));
    }

    // ── The name: Cipi, whatever APP_NAME holds ───────────────────────

    #[Test]
    public function the_browser_tab_says_cipi_not_the_host_apps_name(): void
    {
        config(['app.name' => 'Laravel']);

        $this->get(route('cipi-gui.settings'))->assertOk()
            ->assertSee('<title>Settings — Cipi</title>', false)
            ->assertDontSee('Laravel</title>', false);

        auth()->logout();
        $this->get(route('cipi-gui.login'))->assertOk()
            ->assertSee('<title>Sign in — Cipi</title>', false)
            ->assertDontSee('Laravel</title>', false);
    }

    #[Test]
    public function no_layout_takes_its_title_from_app_name(): void
    {
        foreach (['app', 'guest'] as $layout) {
            $source = (string) file_get_contents(Theme::packageRoot().'/resources/views/layouts/'.$layout.'.blade.php');
            $this->assertSame(1, preg_match('/<title>.*<\/title>/', $source, $m), $layout);
            $this->assertStringNotContainsString('app.name', $m[0], $layout);
            $this->assertStringContainsString('Theme::NAME', $m[0], $layout);
        }
        $this->assertSame('Cipi', Theme::NAME);
    }

    #[Test]
    public function an_authenticator_entry_is_filed_under_cipi_unless_the_panel_was_given_a_name(): void
    {
        $user = auth()->user();
        $service = app(TwoFactorService::class);

        config(['app.name' => 'Laravel']);
        $url = $service->getQrCodeUrl($user, 'JBSWY3DPEHPK3PXP');
        $this->assertStringContainsString('issuer=Cipi', $url);
        $this->assertStringNotContainsString('Laravel', $url);

        config(['app.name' => '']);
        $this->assertStringContainsString('issuer=Cipi', $service->getQrCodeUrl($user, 'JBSWY3DPEHPK3PXP'));

        config(['app.name' => 'Acme']);
        $this->assertStringContainsString('issuer=Acme', $service->getQrCodeUrl($user, 'JBSWY3DPEHPK3PXP'));
    }

    #[Test]
    public function the_version_is_4_2_1_or_later(): void
    {
        $this->assertTrue(version_compare(Theme::VERSION, '4.2.1', '>='));
        $this->assertStringContainsString('## [4.2.1]', (string) file_get_contents(Theme::packageRoot().'/CHANGELOG.md'));
    }
}
