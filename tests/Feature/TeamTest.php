<?php

namespace CipiGui\Tests\Feature;

use CipiGui\Livewire\Team;
use CipiGui\Models\CipiInvite;
use CipiGui\Services\Access;
use CipiGui\Tests\Fixtures\User;
use CipiGui\Tests\TestCase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;

class TeamTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Http::fake(['*/api/apps' => Http::response(['data' => [
            ['app' => 'shop', 'domain' => 'shop.example.com', 'php' => '8.5', 'engine' => 'mariadb', 'node' => false, 'aliases' => []],
        ]]), '*' => Http::response(['data' => []])]);
    }

    #[Test]
    public function only_admins_open_team_and_connections(): void
    {
        $this->server();
        $this->actingAs($this->member());

        $this->get('/team')->assertForbidden();
        $this->get('/servers')->assertForbidden();
        $this->get('/')->assertOk()->assertSee('No access yet')->assertDontSee('Connections');
    }

    #[Test]
    public function an_invite_link_creates_an_account_with_its_grants(): void
    {
        $server = $this->server();
        $admin = $this->admin();
        $this->actingAs($admin);

        $component = Livewire::test(Team::class)
            ->call('openInvite')
            ->assertSet('appsLoaded', true)
            ->set('inviteLabel', 'Mario — Acme')
            ->set('editorRole', 'user')
            ->set("serverLevels.{$server->id}", '')
            ->set("appGrants.{$server->id}.shop", ['deploy', 'logs'])
            ->call('createInvite')
            ->assertHasNoErrors()
            ->assertSee('Invite link ready');

        $invite = CipiInvite::query()->first();
        $link = $component->get('inviteLink');
        $this->assertStringContainsString('/invite/'.$invite->token, $link);
        $this->assertSame('user', $invite->role);
        $this->assertSame([$server->id => ['shop' => ['deploy', 'logs']]], $invite->app_grants);

        auth()->logout();

        $this->get('/invite/'.$invite->token)->assertOk()->assertSee('Join this Cipi panel')->assertSee('Mario — Acme');

        $this->post('/invite/'.$invite->token, [
            'name' => 'Mario', 'email' => 'mario@example.com', 'password' => 'Str0ng-Passw0rd!', 'password_confirmation' => 'Str0ng-Passw0rd!',
        ])->assertRedirect('/');

        $mario = User::where('email', 'mario@example.com')->first();
        $this->assertSame('user', $mario->role);
        $this->assertSame(['view', 'deploy', 'logs'], Access::appAbilities($mario, $server->id, 'shop'));
        $this->assertNotNull($invite->fresh()->accepted_at);

        auth()->logout();
        $this->get('/invite/'.$invite->token)->assertOk()->assertSee('already used');
    }

    #[Test]
    public function expired_and_unknown_invites_are_refused(): void
    {
        $invite = CipiInvite::create(['token' => str_repeat('x', 48), 'role' => 'user', 'expires_at' => now()->subDay()]);

        $this->get('/invite/'.$invite->token)->assertOk()->assertSee('expired');
        $this->post('/invite/'.$invite->token, ['name' => 'A', 'email' => 'a@example.com', 'password' => 'Str0ng-Passw0rd!', 'password_confirmation' => 'Str0ng-Passw0rd!'])
            ->assertRedirect('/invite/'.$invite->token);
        $this->assertNull(User::where('email', 'a@example.com')->first());

        $this->get('/invite/nope')->assertOk()->assertSee('does not match any invite');
    }

    #[Test]
    public function an_admin_cannot_demote_or_remove_themselves(): void
    {
        $admin = $this->admin();
        $other = $this->member();
        $this->actingAs($admin);

        Livewire::test(Team::class)
            ->call('openEdit', $admin->id)
            ->set('editorRole', 'user')
            ->call('saveAccess')
            ->assertHasErrors(['editorRole']);
        $this->assertSame('admin', $admin->fresh()->role);

        Livewire::test(Team::class)->call('confirmDelete', $admin->id)->assertSet('deleteUserId', null);
        $this->assertNotNull($admin->fresh());

        // Another user can be promoted, and removed.
        Livewire::test(Team::class)->call('openEdit', $other->id)->set('editorRole', 'admin')->call('saveAccess')->assertHasNoErrors();
        $this->assertSame('admin', $other->fresh()->role);

        Livewire::test(Team::class)->call('confirmDelete', $other->id)->call('deleteUser');
        $this->assertNull(User::find($other->id));
    }
}
