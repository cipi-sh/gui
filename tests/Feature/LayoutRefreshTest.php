<?php

namespace CipiGui\Tests\Feature;

use CipiGui\Livewire\Header;
use CipiGui\Livewire\Nav;
use CipiGui\Livewire\Servers;
use CipiGui\Livewire\Settings;
use CipiGui\Support\Panel;
use CipiGui\Tests\TestCase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;

/**
 * The header (server switcher, user menu) and the sidebar are components of
 * the layout: they re-render on the events the pages dispatch after a change,
 * instead of waiting for a page reload.
 */
class LayoutRefreshTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Http::fake([
            '*/api/status' => Http::response(['data' => ['system' => ['hostname' => 'fra1', 'cipi' => '5.8.2']]]),
            '*' => Http::response(['data' => []]),
        ]);
    }

    #[Test]
    public function adding_a_connection_tells_the_layout_and_the_switcher_lists_the_new_server(): void
    {
        Panel::set(Panel::FLEET_VIEW, '0');
        $prod = $this->server();
        $this->actingAs($this->admin());
        session(['cipi_gui_server_id' => $prod->id]);

        $header = Livewire::test(Header::class)
            ->assertSeeHtml('class="server-switch"')
            ->assertSee('production')->assertDontSee('staging');

        Livewire::test(Servers::class)
            ->call('openAdd')
            ->set('name', 'staging')->set('url', 'https://staging.example.com')->set('ip', '203.0.113.5')->set('token', 'demo-token-1234567890')
            ->call('save')
            ->assertHasNoErrors()
            ->assertDispatched('servers-changed');

        $header->dispatch('servers-changed')->assertSee('production')->assertSee('staging');
    }

    #[Test]
    public function editing_disabling_and_removing_a_connection_tell_the_layout(): void
    {
        $this->server();
        $staging = $this->server(['name' => 'staging', 'url' => 'https://staging.example.com']);
        $this->actingAs($this->admin());

        Livewire::test(Servers::class)->call('openEdit', $staging->id)->set('name', 'stg')->call('save')->assertHasNoErrors()->assertDispatched('servers-changed');
        $this->assertSame('stg', $staging->fresh()->name);

        Livewire::test(Servers::class)->call('toggleActive', $staging->id)->assertDispatched('servers-changed');
        $this->assertFalse($staging->fresh()->is_active);

        Livewire::test(Servers::class)->call('confirmDelete', $staging->id)->call('deleteServer')->assertDispatched('servers-changed');
        $this->assertNull($staging->fresh());
    }

    #[Test]
    public function a_connection_test_tells_the_layout_only_when_the_servers_state_changed(): void
    {
        $server = $this->server(['last_error' => 'Connection failed: timeout']);
        $this->actingAs($this->admin());

        // Red dot → green dot.
        Livewire::test(Servers::class)->call('testConnection', $server->id)->assertDispatched('servers-changed');
        $this->assertNull($server->fresh()->last_error);

        // Green dot stays green: nothing to tell.
        Livewire::test(Servers::class)->call('testConnection', $server->id)->assertNotDispatched('servers-changed');
    }

    #[Test]
    public function the_sidebar_follows_the_servers_in_fleet_view(): void
    {
        $prod = $this->server();
        $this->actingAs($this->admin());

        $nav = Livewire::test(Nav::class)
            ->assertSee('Fleet')->assertSee('production')
            ->assertSeeHtml('href="'.route('cipi-gui.server-manage', ['serverId' => $prod->id]).'"');

        $staging = $this->server(['name' => 'staging', 'url' => 'https://staging.example.com']);
        $nav->dispatch('servers-changed')
            ->assertSee('staging')
            ->assertSeeHtml('href="'.route('cipi-gui.server-manage', ['serverId' => $staging->id]).'"');

        $staging->delete();
        $nav->dispatch('servers-changed')->assertDontSee('staging');
    }

    #[Test]
    public function the_active_link_is_the_page_the_nav_was_rendered_on_even_after_an_update(): void
    {
        $prod = $this->server();
        $this->actingAs($this->admin());

        // In a Livewire update request() is /livewire/update: the page is kept from mount().
        Livewire::test(Nav::class)->assertDontSeeHtml('aria-current="page"')
            ->set('routeName', 'cipi-gui.apps.show')
            ->dispatch('servers-changed')
            ->assertSeeHtml('aria-current="page"');

        $this->get('/server/'.$prod->id)->assertOk()->assertSeeHtml('aria-current="page"');

        Livewire::test(Nav::class, ['mobile' => true])->assertSeeHtml('x-on:click="mobileNavOpen = false"');
        Livewire::test(Nav::class)->assertDontSeeHtml('x-on:click="mobileNavOpen = false"');
    }

    #[Test]
    public function saving_the_profile_tells_the_header_which_shows_the_new_name(): void
    {
        $this->server();
        $this->actingAs($this->admin());

        $header = Livewire::test(Header::class)->assertSee('Admin')->assertSeeHtml('<span class="avatar">A</span>');

        Livewire::test(Settings::class)->set('name', 'Andrea Pollastri')->call('saveProfile')->assertHasNoErrors()->assertDispatched('profile-changed');

        $header->dispatch('profile-changed')->assertSee('Andrea Pollastri')->assertSeeHtml('<span class="avatar">AP</span>');
    }
}
