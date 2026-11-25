<?php

namespace CipiGui\Tests\Feature;

use CipiGui\Livewire\Notifications;
use CipiGui\Livewire\ServerManage;
use CipiGui\Models\CipiServer;
use CipiGui\Services\Access;
use CipiGui\Tests\TestCase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;

/**
 * The Notifications tab (4.4.0, API 1.42 / Cipi 5.12.0): every channel and
 * trigger; the SMTP form of before on an older server.
 */
class NotificationsTest extends TestCase
{
    private CipiServer $server;

    /** @var array<string, array{0: array<string, mixed>, 1: int}> "METHOD path" => [body, status] */
    private array $answers = [];

    protected function setUp(): void
    {
        parent::setUp();

        $status = ['data' => [
            'channels' => [
                ['id' => 'smtp', 'type' => 'smtp', 'enabled' => true, 'host' => 'smtp.example.com', 'port' => '587', 'from' => 'cipi@example.com', 'to' => ['ops@example.com'], 'tls' => true],
                ['id' => 'ops', 'type' => 'slack', 'enabled' => true, 'url' => 'https://hooks.slack.com/services/***'],
                ['id' => 'oncall', 'type' => 'telegram', 'enabled' => false, 'token_set' => true, 'chat_id' => '-1001'],
            ],
            'triggers' => ['enabled' => 3, 'total' => 4],
            'types' => ['smtp', 'resend', 'ses', 'slack', 'discord', 'mattermost', 'googlechat', 'telegram', 'ntfy', 'pushover', 'webhook'],
        ]];
        $triggers = ['data' => ['triggers' => [
            ['id' => 'deploy_success', 'category' => 'Deploy', 'label' => 'Deploy completed', 'enabled' => false],
            ['id' => 'deploy_fail', 'category' => 'Deploy', 'label' => 'Deploy failed', 'enabled' => true],
            ['id' => 'ssh_login', 'category' => 'Security', 'label' => 'SSH login as root or a sudoer', 'enabled' => true],
            ['id' => 'sudo', 'category' => 'Security', 'label' => 'sudo used', 'enabled' => true],
        ], 'enabled' => 3, 'total' => 4]];
        $this->answers = [
            'GET /api/notifications' => [$status, 200],
            'GET /api/notifications/triggers' => [$triggers, 200],
            'POST /api/notifications/channels' => [['data' => ['channel' => ['id' => 'mm', 'type' => 'mattermost', 'enabled' => true], 'test' => ['ok' => true, 'message' => 'Test sent']]], 201],
            'PUT /api/notifications/channels/oncall' => [['data' => ['channel' => ['id' => 'oncall', 'type' => 'telegram'], 'test' => null]], 200],
            'POST /api/notifications/channels/ops/test' => [['error' => "Test to 'ops' failed — the service refused the request", 'data' => ['ok' => false]], 422],
            'POST /api/notifications/channels/oncall/enable' => [['data' => ['id' => 'oncall', 'enabled' => true]], 200],
            'DELETE /api/notifications/channels/ops' => [$status, 200],
            'PUT /api/notifications/triggers' => [$triggers, 200],
            'GET /api/smtp' => [['data' => ['configured' => true, 'enabled' => true, 'host' => 'smtp.example.com', 'port' => '587', 'user' => 'u', 'from' => 'a@example.com', 'to' => 'b@example.com', 'tls' => true]], 200],
        ];

        Http::fake(function (Request $request) {
            $key = $request->method().' '.parse_url($request->url(), PHP_URL_PATH);
            [$body, $status] = $this->answers[$key] ?? [['data' => []], 200];

            return Http::response($body, $status);
        });

        $this->server = $this->server();
        $this->actingAs($this->admin());
        session(['cipi_gui_server_id' => $this->server->id]);
    }

    private function sent(string $method, string $path): ?Request
    {
        $sent = Http::recorded(fn (Request $r) => $r->method() === $method && parse_url($r->url(), PHP_URL_PATH) === $path);

        return $sent->isEmpty() ? null : $sent->last()[0];
    }

    #[Test]
    public function the_tab_lists_every_channel_and_the_triggers_by_category(): void
    {
        Livewire::test(Notifications::class, ['serverId' => $this->server->id])
            ->call('init')
            ->assertSet('loaded', true)
            ->assertSet('unsupported', false)
            ->assertSee('smtp.example.com')
            ->assertSee('https://hooks.slack.com/services/***')
            ->assertSee('chat -1001')
            ->assertSee('paused')
            ->assertSee('3 / 4 on')
            ->assertSee('Deploy completed')
            ->assertSee('SSH login as root or a sudoer')
            ->assertSet('triggerState.deploy_success', false);
    }

    #[Test]
    public function a_manager_adds_a_channel_with_only_the_fields_of_its_type(): void
    {
        Livewire::test(Notifications::class, ['serverId' => $this->server->id])
            ->call('init')
            ->call('openAdd', 'mattermost')
            ->assertSet('formId', 'mattermost')
            ->set('formId', 'mm')
            ->set('form.url', 'https://chat.example.com/hooks/abc')
            ->call('saveChannel')
            ->assertHasNoErrors()
            ->assertSet('formOpen', false);

        $this->assertSame(['type' => 'mattermost', 'id' => 'mm', 'url' => 'https://chat.example.com/hooks/abc', 'enabled' => true, 'test' => true], $this->sent('POST', '/api/notifications/channels')?->data());
    }

    #[Test]
    public function a_chat_webhook_must_be_https_and_an_id_cannot_be_taken(): void
    {
        Livewire::test(Notifications::class, ['serverId' => $this->server->id])
            ->call('init')
            ->call('openAdd', 'slack')
            ->set('formId', 'ops')
            ->set('form.url', 'http://hooks.slack.com/x')
            ->call('saveChannel')
            ->assertHasErrors(['formId', 'form.url']);

        $this->assertNull($this->sent('POST', '/api/notifications/channels'));
    }

    #[Test]
    public function editing_sends_only_what_changed_and_keeps_secrets_left_empty(): void
    {
        Livewire::test(Notifications::class, ['serverId' => $this->server->id])
            ->call('init')
            ->call('openEdit', 'oncall')
            ->assertSet('form.token', '')
            ->assertSet('form.chat_id', '-1001')
            ->set('form.chat_id', '-2002')
            ->call('saveChannel')
            ->assertHasNoErrors();

        $this->assertSame(['chat_id' => '-2002'], $this->sent('PUT', '/api/notifications/channels/oncall')?->data());
    }

    #[Test]
    public function a_refused_test_shows_the_reason_on_the_row(): void
    {
        Livewire::test(Notifications::class, ['serverId' => $this->server->id])
            ->call('init')
            ->call('testChannel', 'ops')
            ->assertSet('tests.ops.ok', false)
            ->assertSee("Test to 'ops' failed");
    }

    #[Test]
    public function triggers_are_saved_together_and_only_the_changed_ones(): void
    {
        Livewire::test(Notifications::class, ['serverId' => $this->server->id])
            ->call('init')
            ->set('triggerState.deploy_success', true)
            ->call('setCategory', 'Security', false)
            ->assertSee('3 unsaved changes')
            ->call('saveTriggers');

        $this->assertSame(['triggers' => ['deploy_success' => true, 'ssh_login' => false, 'sudo' => false]], $this->sent('PUT', '/api/notifications/triggers')?->data());
    }

    #[Test]
    public function a_viewer_reads_but_does_not_change_anything(): void
    {
        $viewer = $this->member();
        Access::replaceGrants($viewer->id, [$this->server->id => 'view'], []);
        $this->actingAs($viewer);

        Livewire::test(Notifications::class, ['serverId' => $this->server->id])
            ->call('init')
            ->assertSee('smtp.example.com')
            ->assertDontSee('Add channel')
            ->call('toggleChannel', 'oncall')
            ->call('removeChannel', 'ops')
            ->set('triggerState.deploy_success', true)
            ->call('saveTriggers');

        $this->assertNull($this->sent('POST', '/api/notifications/channels/oncall/enable'));
        $this->assertNull($this->sent('DELETE', '/api/notifications/channels/ops'));
        $this->assertNull($this->sent('PUT', '/api/notifications/triggers'));
    }

    #[Test]
    public function an_older_server_keeps_the_smtp_form(): void
    {
        $this->answers['GET /api/notifications'] = [['message' => 'Not Found'], 404];

        Livewire::withQueryParams(['tab' => 'smtp'])->test(ServerManage::class, ['serverId' => $this->server->id])
            ->call('init')
            ->assertSet('notificationsLegacy', true)
            ->assertSet('smtpHost', 'smtp.example.com')
            ->assertSee('With an up-to-date Cipi')
            ->assertDontSee('5.12');
    }

    #[Test]
    public function the_release_is_in_the_changelog(): void
    {
        // 4.4.0 was written for API 1.42; later releases move both on.
        $this->assertTrue(version_compare(\CipiGui\Support\Theme::VERSION, '4.4.0', '>='));
        $this->assertTrue(version_compare(\CipiGui\Support\Theme::API, '1.42', '>='));
        $this->assertStringContainsString('## [4.4.0]', (string) file_get_contents(\CipiGui\Support\Theme::packageRoot().'/CHANGELOG.md'));
    }
}
