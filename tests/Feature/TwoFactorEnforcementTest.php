<?php

namespace CipiGui\Tests\Feature;

use CipiGui\Http\Middleware\EnsureTwoFactorVerified;
use CipiGui\Livewire\Settings;
use CipiGui\Livewire\Team;
use CipiGui\Models\CipiInvite;
use CipiGui\Services\TwoFactorService;
use CipiGui\Support\Panel;
use CipiGui\Tests\TestCase;
use Illuminate\Support\Facades\Crypt;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use PragmaRX\Google2FA\Google2FA;

/**
 * 2FA required for every account (4.6.0, issue "enforce_2fa"): off by default;
 * on, whoever signs in with a password and has no 2FA sets it up before
 * anything else, and cannot turn it off. SSO sessions are left to the
 * identity provider's MFA.
 */
class TwoFactorEnforcementTest extends TestCase
{
    private function requireTwoFactor(): void
    {
        Panel::set(Panel::ENFORCE_2FA, '1');
    }

    /** A code the authenticator would show now — and one it would not. */
    private function codes(string $secret): array
    {
        $valid = (new Google2FA)->getCurrentOtp($secret);

        return [$valid, str_pad((string) (((int) $valid + 500000) % 1000000), 6, '0', STR_PAD_LEFT)];
    }

    #[Test]
    public function it_is_off_by_default(): void
    {
        $this->assertFalse(Panel::enforceTwoFactor());
        $this->actingAs($this->member());

        $this->get('/')->assertOk();
        $this->get('/settings')->assertOk()->assertSee('Set up 2FA');
        // Not required: 2FA is set up from Settings, as before.
        $this->get('/2fa/setup')->assertRedirect(route('cipi-gui.settings'));
    }

    #[Test]
    public function a_password_sign_in_without_2fa_sets_it_up_first_then_opens_the_page_asked_for(): void
    {
        $this->requireTwoFactor();
        $user = $this->member();

        $this->get('/settings')->assertRedirect('/login');
        $this->post('/login', ['email' => 'mario@cipi.local', 'password' => 'secret-password'])
            ->assertRedirect(route('cipi-gui.2fa.setup'));

        $this->get('/2fa/setup')->assertOk()
            ->assertSee('Set up two-factor authentication')
            ->assertSee('An admin of this panel requires it')
            ->assertSee('<svg', false);
        $secret = session(TwoFactorService::SETUP_SECRET);
        $this->assertIsString($secret);
        $this->get('/2fa/setup')->assertOk()->assertSee(trim(chunk_split($secret, 4, ' ')));
        $this->assertSame($secret, session(TwoFactorService::SETUP_SECRET), 'a reload shows the same secret');

        [$valid] = $this->codes($secret);
        $this->post('/2fa/setup', ['code' => $valid])->assertRedirect('/settings');

        $fresh = $user->fresh();
        $this->assertTrue((bool) $fresh->two_factor_enabled);
        $this->assertSame($secret, Crypt::decryptString($fresh->two_factor_secret));
        $this->assertNull(session(TwoFactorService::SETUP_SECRET));
        $this->assertTrue(session('cipi_gui_2fa_verified'));
        $this->get('/')->assertOk();
    }

    #[Test]
    public function nothing_else_opens_until_it_is_set_up(): void
    {
        $this->requireTwoFactor();
        $this->actingAs($this->member());

        foreach (['/', '/settings', '/apps', '/databases', '/server'] as $page) {
            $this->get($page)->assertRedirect(route('cipi-gui.2fa.setup'));
        }
        $this->get('/team')->assertRedirect(route('cipi-gui.2fa.setup'));
        $this->post('/logout')->assertRedirect(route('cipi-gui.login'));
    }

    #[Test]
    public function a_session_open_when_it_is_turned_on_is_taken_to_the_setup_at_its_next_page(): void
    {
        $this->actingAs($this->member());
        $this->get('/')->assertOk();

        $this->requireTwoFactor();

        $this->get('/settings')->assertRedirect(route('cipi-gui.2fa.setup'));
        $this->get('/2fa/setup')->assertOk();
        [$valid] = $this->codes(session(TwoFactorService::SETUP_SECRET));
        $this->post('/2fa/setup', ['code' => $valid])->assertRedirect('/settings');
        $this->get('/settings')->assertOk();
    }

    #[Test]
    public function a_wrong_code_turns_nothing_on(): void
    {
        $this->requireTwoFactor();
        $user = $this->member();
        $this->actingAs($user);

        $this->get('/2fa/setup')->assertOk();
        [, $wrong] = $this->codes(session(TwoFactorService::SETUP_SECRET));

        $this->from('/2fa/setup')->post('/2fa/setup', ['code' => $wrong])
            ->assertRedirect('/2fa/setup')->assertSessionHasErrors('code');
        $this->from('/2fa/setup')->post('/2fa/setup', ['code' => '12ab56'])->assertSessionHasErrors('code');

        $this->assertFalse((bool) $user->fresh()->two_factor_enabled);
        $this->get('/')->assertRedirect(route('cipi-gui.2fa.setup'));
    }

    #[Test]
    public function an_expired_setup_starts_again_instead_of_failing(): void
    {
        $this->requireTwoFactor();
        $this->actingAs($this->member());

        $this->post('/2fa/setup', ['code' => '123456'])
            ->assertRedirect(route('cipi-gui.2fa.setup'))
            ->assertSessionHasErrors('code');
    }

    #[Test]
    public function an_account_with_2fa_gives_its_code_and_is_never_sent_to_the_setup(): void
    {
        $this->requireTwoFactor();
        $this->actingAs($this->member(['two_factor_enabled' => true, 'two_factor_secret' => Crypt::encryptString('JBSWY3DPEHPK3PXP')]));

        $this->get('/')->assertRedirect(route('cipi-gui.2fa'));
        $this->get('/2fa/setup')->assertRedirect(route('cipi-gui.2fa'));
        $this->withSession(['cipi_gui_2fa_verified' => true])->get('/')->assertOk();
        $this->get('/2fa/setup')->assertRedirect(route('cipi-gui.dashboard'));
    }

    #[Test]
    public function an_sso_session_is_left_to_the_identity_providers_mfa(): void
    {
        $this->requireTwoFactor();
        $this->actingAs($this->member());

        $this->withSession([TwoFactorService::SSO_SESSION => true, 'cipi_gui_2fa_verified' => true])
            ->get('/')->assertOk();
        $this->get('/settings')->assertOk()->assertSee('single sign-on, whose MFA counts');
    }

    #[Test]
    public function a_password_sign_in_forgets_an_earlier_sso_session(): void
    {
        $this->requireTwoFactor();
        $this->member();

        $this->withSession([TwoFactorService::SSO_SESSION => true])
            ->post('/login', ['email' => 'mario@cipi.local', 'password' => 'secret-password'])
            ->assertRedirect(route('cipi-gui.2fa.setup'));
        $this->assertNull(session(TwoFactorService::SSO_SESSION));
    }

    #[Test]
    public function an_invite_opens_on_the_setup_when_2fa_is_required(): void
    {
        $this->requireTwoFactor();
        $invite = CipiInvite::create(['token' => str_repeat('t', 48), 'role' => 'user', 'expires_at' => now()->addDay()]);

        $this->post('/invite/'.$invite->token, [
            'name' => 'Luigi', 'email' => 'luigi@example.com', 'password' => 'Str0ng-Passw0rd!', 'password_confirmation' => 'Str0ng-Passw0rd!',
        ])->assertRedirect(route('cipi-gui.2fa.setup'));
    }

    #[Test]
    public function an_admin_requires_it_from_team_once_their_own_2fa_is_on(): void
    {
        $admin = $this->admin();
        $this->member(['two_factor_enabled' => false]);
        $this->actingAs($admin);

        Livewire::test(Team::class)
            ->assertSee('Two-factor authentication')->assertSee('Optional')->assertSee('Require for everyone')
            ->call('setTwoFactorRequired', true)
            ->assertHasErrors('twoFactor');
        Panel::flush();
        $this->assertFalse(Panel::enforceTwoFactor(), 'an admin without 2FA would lock themselves out of the next page');

        $admin->forceFill(['two_factor_enabled' => true, 'two_factor_secret' => Crypt::encryptString('JBSWY3DPEHPK3PXP')])->save();

        Livewire::test(Team::class)
            ->call('setTwoFactorRequired', true)
            ->assertHasNoErrors()
            ->assertDispatched('notify');
        Panel::flush();
        $this->assertTrue(Panel::enforceTwoFactor());

        Livewire::test(Team::class)
            ->assertSee('Required for everyone')->assertSee('Setup pending')->assertSee('Make it optional')
            ->call('setTwoFactorRequired', false);
        Panel::flush();
        $this->assertFalse(Panel::enforceTwoFactor());
    }

    #[Test]
    public function required_2fa_cannot_be_turned_off_and_moves_to_a_new_device(): void
    {
        $this->requireTwoFactor();
        $user = $this->member(['two_factor_enabled' => true, 'two_factor_secret' => Crypt::encryptString('JBSWY3DPEHPK3PXP')]);
        $this->actingAs($user);

        Livewire::test(Settings::class)
            ->assertSee('Required')->assertSee('Move to a new device')->assertDontSee('Disable 2FA')
            ->set('disablePassword', 'secret-password')
            ->call('disableTwoFactor')
            ->assertHasErrors('disablePassword');
        $this->assertTrue((bool) $user->fresh()->two_factor_enabled);

        $component = Livewire::test(Settings::class)->call('startTwoFactorSetup')->assertSee('Use the new device');
        $secret = $component->get('setupSecret');
        [$valid] = $this->codes($secret);
        $component->set('verificationCode', $valid)->call('confirmTwoFactor')->assertHasNoErrors();

        $this->assertSame($secret, Crypt::decryptString($user->fresh()->two_factor_secret));
    }

    #[Test]
    public function optional_2fa_can_still_be_turned_off(): void
    {
        $user = $this->member(['two_factor_enabled' => true, 'two_factor_secret' => Crypt::encryptString('JBSWY3DPEHPK3PXP')]);
        $this->actingAs($user);

        Livewire::test(Settings::class)
            ->assertSee('Disable 2FA')
            ->set('disablePassword', 'secret-password')
            ->call('disableTwoFactor')
            ->assertHasNoErrors();
        $this->assertFalse((bool) $user->fresh()->two_factor_enabled);
    }

    #[Test]
    public function an_admin_resets_the_2fa_of_another_account_not_their_own(): void
    {
        $this->requireTwoFactor();
        $admin = $this->admin(['two_factor_enabled' => true, 'two_factor_secret' => Crypt::encryptString('JBSWY3DPEHPK3PXP')]);
        $member = $this->member(['two_factor_enabled' => true, 'two_factor_secret' => Crypt::encryptString('KRSXG5CTMVRXEZLU')]);
        $this->actingAs($admin);

        Livewire::test(Team::class)
            ->assertSee('Reset 2FA')
            ->call('confirmResetTwoFactor', $member->id)
            ->assertSee('Reset two-factor authentication')
            ->call('resetTwoFactor')
            ->assertSet('resetTwoFactorUserId', null)
            ->assertDispatched('notify');

        $this->assertFalse((bool) $member->fresh()->two_factor_enabled);
        $this->assertNull($member->fresh()->two_factor_secret);

        Livewire::test(Team::class)->call('confirmResetTwoFactor', $admin->id)->assertSet('resetTwoFactorUserId', null);
        $this->assertTrue((bool) $admin->fresh()->two_factor_enabled);
    }

    #[Test]
    public function livewire_actions_go_through_the_2fa_check_too(): void
    {
        $livewire = Livewire::getFacadeRoot();
        if (! method_exists($livewire, 'getPersistentMiddleware')) {
            $this->markTestSkipped('This Livewire does not list its persistent middleware.');
        }

        $this->assertContains(EnsureTwoFactorVerified::class, $livewire->getPersistentMiddleware());
    }
}
