<?php

namespace CipiGui\Tests\Feature;

use CipiGui\Infrastructure\Connectors;
use CipiGui\Infrastructure\DnsPublisher;
use CipiGui\Infrastructure\ServerTargets;
use CipiGui\Livewire\ConnectorAccounts;
use CipiGui\Livewire\Infrastructure;
use CipiGui\Livewire\Nav;
use CipiGui\Models\CipiConnectorAccount;
use CipiGui\Tests\TestCase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;

/**
 * Connectors (4.8.0): Settings → Connectors keeps the accounts, the sidebar
 * shows a connector once it has one, its page lists and acts at the
 * provider. Cloudflare is the one driven here; the others share the code
 * (tests/standalone-infrastructure.php runs each one's lists and forms).
 */
class InfrastructureTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        ServerTargets::flush();
        DnsPublisher::flush();
    }

    /** Cloudflare's API, as far as these tests ask it. A later Http::fake() would come after these stubs: tests that need other answers do not call this. */
    private function fakeCloudflare(): void
    {
        Http::fake([
            'api.cloudflare.com/client/v4/zones/z1/dns_records*' => Http::response(['success' => true, 'result' => [
                ['id' => 'r1', 'type' => 'A', 'name' => 'shop.example.com', 'content' => '203.0.113.10', 'proxied' => true, 'ttl' => 1],
            ], 'result_info' => ['total_pages' => 1]]),
            'api.cloudflare.com/client/v4/zones*' => Http::response(['success' => true, 'result' => [
                ['id' => 'z1', 'name' => 'example.com', 'status' => 'active', 'paused' => false, 'plan' => ['name' => 'Free'], 'name_servers' => ['ada.ns.cloudflare.com'], 'account' => ['name' => 'Acme']],
            ], 'result_info' => ['total_pages' => 1, 'total_count' => 1]]),
            'api.cloudflare.com/client/v4/accounts*' => Http::response(['success' => true, 'result' => [['id' => 'a1', 'name' => 'Acme']], 'result_info' => ['total_pages' => 1]]),
        ]);
    }

    private function cloudflare(): CipiConnectorAccount
    {
        return CipiConnectorAccount::create(['connector' => 'cloudflare', 'name' => 'Acme', 'credentials' => ['token' => 'cf-secret-token'], 'status' => 'ok · Acme · 1 zone', 'checked_at' => now()]);
    }

    #[Test]
    public function settings_lists_every_connector_for_an_admin(): void
    {
        $this->fakeCloudflare();
        $this->actingAs($this->admin());

        $this->get(route('cipi-gui.settings'))->assertOk()
            ->assertSee('data-connectors', false)
            ->assertSee('Cloudflare')->assertSee('DigitalOcean')->assertSee('Google Cloud')->assertSee('Microsoft Azure')->assertSee('OVHcloud');
    }

    #[Test]
    public function an_account_is_checked_with_the_provider_and_kept_encrypted(): void
    {
        $this->fakeCloudflare();
        $this->actingAs($this->admin());

        Livewire::test(ConnectorAccounts::class)
            ->call('open', 'cloudflare')
            ->assertSee('API Tokens')
            ->set('accountName', 'Acme')
            ->set('credentials.token', 'cf-secret-token')
            ->call('save')
            ->assertHasNoErrors()
            ->assertDispatched('connectors-changed');

        $account = CipiConnectorAccount::query()->where('connector', 'cloudflare')->firstOrFail();
        $this->assertSame('cf-secret-token', $account->credential('token'));
        $this->assertStringStartsWith('ok', (string) $account->status);
        $this->assertStringNotContainsString('cf-secret-token', (string) DB::table('cipi_connector_accounts')->value('credentials'));
        Http::assertSent(fn (Request $r) => $r->hasHeader('Authorization', 'Bearer cf-secret-token'));
    }

    #[Test]
    public function a_secret_left_empty_keeps_the_stored_one(): void
    {
        $this->fakeCloudflare();
        $this->actingAs($this->admin());
        $account = $this->cloudflare();

        Livewire::test(ConnectorAccounts::class)
            ->call('open', 'cloudflare')
            ->call('edit', $account->uuid)
            ->assertSet('credentials.token', '')
            ->set('accountName', 'Acme EU')
            ->call('save')
            ->assertHasNoErrors();

        $account->refresh();
        $this->assertSame('Acme EU', $account->name);
        $this->assertSame('cf-secret-token', $account->credential('token'));
    }

    #[Test]
    public function refused_credentials_are_not_saved(): void
    {
        Http::fake(['api.cloudflare.com/*' => Http::response(['success' => false, 'errors' => [['code' => 1000, 'message' => 'Invalid API Token']]], 401)]);
        $this->actingAs($this->admin());

        Livewire::test(ConnectorAccounts::class)
            ->call('open', 'cloudflare')
            ->set('accountName', 'Acme')
            ->set('credentials.token', 'wrong')
            ->call('save')
            ->assertHasErrors('credentials');

        $this->assertSame(0, CipiConnectorAccount::query()->count());
    }

    #[Test]
    public function the_sidebar_shows_a_connector_once_it_has_an_account(): void
    {
        $this->fakeCloudflare();
        $this->actingAs($this->admin());

        Livewire::test(Nav::class)->assertDontSee('Infrastructure');
        $this->assertSame([], Connectors::connected());

        $this->cloudflare();

        Livewire::test(Nav::class)->assertSee('Infrastructure')->assertSee(route('cipi-gui.infrastructure', ['connector' => 'cloudflare']), false);
        $this->assertSame(['cloudflare'], array_map(fn ($c) => $c->key(), Connectors::connected()));
    }

    #[Test]
    public function the_page_lists_zones_and_their_records_naming_the_server_an_address_is(): void
    {
        $this->fakeCloudflare();
        $this->actingAs($this->admin());
        $this->server(['name' => 'mozart', 'ip' => '203.0.113.10']);
        $this->cloudflare();

        Livewire::test(Infrastructure::class, ['connector' => 'cloudflare'])
            ->assertSee('example.com')
            ->assertSee('ada.ns.cloudflare.com')
            ->call('start', 'records', 'z1')
            ->assertSet('tab', 'records')
            ->assertSee('shop.example.com')
            ->assertSee('203.0.113.10 → mozart');
    }

    #[Test]
    public function a_record_to_a_server_by_name_is_saved_as_its_address(): void
    {
        $this->fakeCloudflare();
        $this->actingAs($this->admin());
        $this->server(['name' => 'mozart', 'ip' => '203.0.113.10']);
        $this->cloudflare();

        Livewire::test(Infrastructure::class, ['connector' => 'cloudflare'])
            ->call('start', 'records', 'z1')
            ->call('start', 'create')
            ->assertSet('formAction', 'create')
            ->set('form.type', 'A')
            ->set('form.name', 'api')
            ->set('form.content', 'mozart')
            ->set('form.proxied', true)
            ->call('submit')
            ->assertHasNoErrors()
            ->assertSet('formAction', null);

        Http::assertSent(fn (Request $r) => $r->method() === 'POST'
            && str_ends_with(parse_url($r->url(), PHP_URL_PATH), '/zones/z1/dns_records')
            && $r['content'] === '203.0.113.10' && $r['proxied'] === true && $r['name'] === 'api');
    }

    #[Test]
    public function a_server_is_no_target_for_a_cname(): void
    {
        $this->fakeCloudflare();
        $this->actingAs($this->admin());
        $this->server(['name' => 'mozart', 'ip' => '203.0.113.10']);
        $this->cloudflare();

        Livewire::test(Infrastructure::class, ['connector' => 'cloudflare'])
            ->call('start', 'records', 'z1')
            ->call('start', 'create')
            ->set('form.type', 'CNAME')
            ->set('form.name', 'www')
            ->set('form.content', 'mozart')
            ->call('submit')
            ->assertDispatched('notify', type: 'error')
            ->assertSet('formAction', 'create');

        Http::assertNotSent(fn (Request $r) => $r->method() === 'POST');
    }

    #[Test]
    public function settings_groups_the_connectors_by_what_they_do(): void
    {
        $this->fakeCloudflare();
        $this->actingAs($this->admin());

        $this->get(route('cipi-gui.settings'))->assertOk()
            ->assertSeeInOrder(['Clouds and servers', 'Domains and DNS', 'Object storage', 'Email', 'Monitoring and errors', 'Security'])
            ->assertSee('Johnny')->assertSee('Backblaze B2')->assertSee('Postmark')->assertSee('Oh Dear')->assertSee('Aikido')->assertSee('Hackly')->assertSee('Porkbun')
            ->assertSee('R2: buckets');
    }

    #[Test]
    public function a_mail_domain_publishes_its_records_on_the_cloudflare_zone(): void
    {
        $this->actingAs($this->admin());
        $this->cloudflare();
        CipiConnectorAccount::create(['connector' => 'mailgun', 'name' => 'Mail', 'credentials' => ['api_key' => 'key-1', 'region' => 'eu'], 'status' => 'ok', 'checked_at' => now()]);

        Http::fake([
            'api.eu.mailgun.net/v4/domains/mg.example.com' => Http::response(['domain' => ['name' => 'mg.example.com', 'state' => 'unverified'],
                'sending_dns_records' => [['record_type' => 'TXT', 'name' => 'mg.example.com', 'value' => 'v=spf1 include:mailgun.org ~all']],
                'receiving_dns_records' => [['record_type' => 'MX', 'value' => 'mxa.eu.mailgun.org', 'priority' => '10']]]),
            'api.eu.mailgun.net/v4/domains*' => Http::response(['total_count' => 1, 'items' => [['name' => 'mg.example.com', 'state' => 'unverified', 'type' => 'custom']]]),
            'api.cloudflare.com/client/v4/zones/z1/dns_records*' => fn (Request $r) => Http::response(['success' => true, 'result' => $r->method() === 'GET' ? [] : ['id' => 'n1']]),
            'api.cloudflare.com/client/v4/zones*' => function (Request $r) {
                parse_str((string) parse_url($r->url(), PHP_URL_QUERY), $query);

                return Http::response(['success' => true, 'result' => ($query['name'] ?? '') === 'example.com' ? [['id' => 'z1', 'name' => 'example.com']] : []]);
            },
        ]);

        Livewire::test(Infrastructure::class, ['connector' => 'mailgun'])
            ->call('start', 'publish-dns', 'mg.example.com')
            ->assertDispatched('notify', type: 'success')
            ->assertSet('resultTitle', 'DNS of mg.example.com on Cloudflare');

        Http::assertSent(fn (Request $r) => $r->method() === 'POST' && str_ends_with(parse_url($r->url(), PHP_URL_PATH), '/zones/z1/dns_records')
            && $r['type'] === 'MX' && $r['content'] === 'mxa.eu.mailgun.org' && $r['priority'] === 10);
        Http::assertSent(fn (Request $r) => $r->method() === 'POST' && $r['type'] === 'TXT' && $r['name'] === 'mg.example.com');
    }

    #[Test]
    public function connectors_are_for_admins_only(): void
    {
        $this->fakeCloudflare();
        $this->cloudflare();
        $this->actingAs($this->member());

        $this->get(route('cipi-gui.infrastructure', ['connector' => 'cloudflare']))->assertForbidden();
        $this->get(route('cipi-gui.settings'))->assertOk()->assertDontSee('data-connectors', false);
        Livewire::test(Nav::class)->assertDontSee('Infrastructure');
    }

    #[Test]
    public function an_unknown_connector_is_not_found(): void
    {
        $this->fakeCloudflare();
        $this->actingAs($this->admin());

        $this->get('/infrastructure/nope')->assertNotFound();
    }
}
