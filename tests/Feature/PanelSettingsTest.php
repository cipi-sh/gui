<?php

namespace CipiGui\Tests\Feature;

use CipiGui\Livewire\Apps;
use CipiGui\Livewire\Settings;
use CipiGui\Support\Panel;
use CipiGui\Tests\TestCase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;

class PanelSettingsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Http::fake(function ($request) {
            $host = parse_url($request->url(), PHP_URL_HOST);

            return match (true) {
                str_ends_with($request->url(), '/api/apps') => Http::response(['data' => [
                    ['app' => $host === 'staging.example.com' ? 'blog' : 'shop', 'domain' => $host.'-app', 'php' => '8.5', 'engine' => 'mariadb', 'node' => false, 'aliases' => []],
                ]]),
                default => Http::response(['data' => []]),
            };
        });
    }

    #[Test]
    public function fleet_view_is_the_default_with_one_sidebar_entry_per_server(): void
    {
        $prod = $this->server();
        $staging = $this->server(['name' => 'staging', 'url' => 'https://staging.example.com']);
        $this->actingAs($this->admin());

        $this->get('/apps')->assertOk()
            ->assertSee('Fleet')->assertDontSee('Switch server')
            ->assertSeeHtml('href="'.route('cipi-gui.server-manage', ['serverId' => $prod->id]).'"')
            ->assertSeeHtml('href="'.route('cipi-gui.server-manage', ['serverId' => $staging->id]).'"');

        Livewire::test(Apps::class)->call('loadApps')->assertSee('All servers')->assertSee('shop')->assertSee('blog');
    }

    #[Test]
    public function the_picker_mode_shows_one_server_entry_and_a_picker_on_the_page(): void
    {
        $prod = $this->server();
        $this->server(['name' => 'staging', 'url' => 'https://staging.example.com']);
        Panel::set(Panel::SERVER_NAV, 'picker');
        $this->actingAs($this->admin());

        $this->get('/server/'.$prod->id)->assertOk()
            ->assertSeeHtml('wire:change="switchServer($event.target.value)"')
            ->assertSee('staging');
    }

    #[Test]
    public function per_server_mode_brings_back_the_header_switcher_and_scoped_lists(): void
    {
        $prod = $this->server();
        $staging = $this->server(['name' => 'staging', 'url' => 'https://staging.example.com']);
        Panel::set(Panel::FLEET_VIEW, '0');
        $this->actingAs($this->admin());
        session(['cipi_gui_server_id' => $prod->id]);

        $this->get('/apps')->assertOk()->assertSee('Switch server')->assertSee('Current server')->assertDontSee('Fleet');

        Livewire::test(Apps::class)->call('loadApps')
            ->assertSet('fleet', false)
            ->assertSet('scopeServerId', $prod->id)
            ->assertSee('shop')->assertDontSee('blog')->assertDontSee('All servers');

        $this->from('/apps')->post('/servers/switch', ['server_id' => $staging->id])->assertRedirect('/apps');
    }

    #[Test]
    public function admins_save_the_panel_settings_and_users_cannot(): void
    {
        $this->server();

        $this->actingAs($this->admin());
        Livewire::test(Settings::class)->set('fleetView', false)->set('serverNav', 'picker')->call('savePanel')->assertRedirect(route('cipi-gui.settings'));
        Panel::flush();
        $this->assertFalse(Panel::fleetView());
        $this->assertSame('picker', Panel::serverNav());

        $this->actingAs($this->member());
        Livewire::test(Settings::class)->assertDontSee('Fleet view')->set('fleetView', true)->call('savePanel');
        Panel::flush();
        $this->assertFalse(Panel::fleetView(), 'a user cannot change panel settings');
    }
}
