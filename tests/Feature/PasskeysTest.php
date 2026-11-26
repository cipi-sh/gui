<?php

namespace CipiGui\Tests\Feature;

use CipiGui\Livewire\PasskeyList;
use CipiGui\Models\CipiPasskey;
use CipiGui\Services\Passkeys;
use CipiGui\Support\Panel;
use CipiGui\Tests\Fixtures\User;
use CipiGui\Tests\Support\SoftAuthenticator;
use CipiGui\Tests\TestCase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;

/**
 * Passkeys (4.8.0), next to the password and 2FA: added from Settings (or
 * the 2FA setup), used alone on the login page, or as the second step after
 * the password. A software authenticator plays the browser.
 */
class PasskeysTest extends TestCase
{
    private function addPasskey(User $user, SoftAuthenticator $device, string $name = 'MacBook'): void
    {
        $this->actingAs($user)->withSession(['cipi_gui_2fa_verified' => true]);
        $options = $this->postJson(route('cipi-gui.passkeys.register.options'))->assertOk()->json();
        $this->postJson(route('cipi-gui.passkeys.register'), ['credential' => $device->create($options), 'name' => $name])->assertOk();
    }

    private function signOut(): void
    {
        auth()->logout();
        $this->flushSession();
    }

    #[Test]
    public function the_login_page_offers_a_passkey(): void
    {
        $this->get(route('cipi-gui.login'))->assertOk()->assertSee('data-passkey-login', false)->assertSee('username webauthn', false);
    }

    #[Test]
    public function a_passkey_is_added_from_settings(): void
    {
        $user = $this->admin();
        $device = new SoftAuthenticator;

        $this->actingAs($user)->withSession(['cipi_gui_2fa_verified' => true]);
        $options = $this->postJson(route('cipi-gui.passkeys.register.options'))->assertOk()->json();
        $this->assertSame('localhost', $options['rp']['id']);
        $this->assertSame('required', $options['authenticatorSelection']['userVerification']);
        $this->assertSame('none', $options['attestation']);

        $this->postJson(route('cipi-gui.passkeys.register'), ['credential' => $device->create($options), 'name' => 'MacBook'])
            ->assertOk()->assertJson(['name' => 'MacBook']);

        $passkey = CipiPasskey::query()->firstOrFail();
        $this->assertSame($user->id, $passkey->user_id);
        $this->assertStringContainsString('BEGIN PUBLIC KEY', $passkey->public_key);
        $this->assertTrue(Passkeys::has($user));

        Livewire::test(PasskeyList::class)->assertSee('MacBook')->assertSee('1 passkey');
    }

    #[Test]
    public function a_passkey_signs_in_alone_with_no_code_after_it(): void
    {
        $user = $this->admin(['two_factor_enabled' => true, 'two_factor_secret' => \Illuminate\Support\Facades\Crypt::encryptString('JBSWY3DPEHPK3PXP')]);
        $device = new SoftAuthenticator;
        $this->addPasskey($user, $device);
        $this->signOut();

        $options = $this->postJson(route('cipi-gui.passkeys.login.options'))->assertOk()->json();
        $this->assertSame([], $options['allowCredentials']);

        $this->postJson(route('cipi-gui.passkeys.login'), ['credential' => $device->get($options, Passkeys::userHandle($user))])
            ->assertOk()->assertJson(['redirect' => route('cipi-gui.dashboard')]);

        $this->assertAuthenticatedAs($user);
        $this->assertTrue(session('cipi_gui_2fa_verified'));
        $this->get(route('cipi-gui.dashboard'))->assertOk();
        $this->assertNotNull(CipiPasskey::query()->first()->last_used_at);
    }

    #[Test]
    public function a_replayed_or_foreign_sign_in_is_refused(): void
    {
        $user = $this->admin();
        $device = new SoftAuthenticator;
        $this->addPasskey($user, $device);
        $this->signOut();

        // Another site's origin.
        $options = $this->postJson(route('cipi-gui.passkeys.login.options'))->json();
        $this->postJson(route('cipi-gui.passkeys.login'), ['credential' => $device->get($options, null, 'https://evil.example.net')])->assertStatus(422);
        $this->assertGuest();

        // The same answer twice: the challenge was used up.
        $options = $this->postJson(route('cipi-gui.passkeys.login.options'))->json();
        $answer = $device->get($options);
        $this->postJson(route('cipi-gui.passkeys.login'), ['credential' => $answer])->assertOk();
        $this->signOut();
        $this->postJson(route('cipi-gui.passkeys.login'), ['credential' => $answer])->assertStatus(422);
        $this->assertGuest();

        // A passkey this panel never saw.
        $options = $this->postJson(route('cipi-gui.passkeys.login.options'))->json();
        $this->postJson(route('cipi-gui.passkeys.login'), ['credential' => (new SoftAuthenticator)->get($options)])->assertStatus(422);
        $this->assertGuest();
    }

    #[Test]
    public function after_the_password_a_passkey_is_the_second_step(): void
    {
        $user = $this->admin();
        $device = new SoftAuthenticator;
        $this->addPasskey($user, $device);
        $this->signOut();

        $this->post(route('cipi-gui.login.submit'), ['email' => $user->email, 'password' => 'secret-password'])->assertRedirect(route('cipi-gui.2fa'));
        $this->get(route('cipi-gui.dashboard'))->assertRedirect(route('cipi-gui.2fa'));
        $this->get(route('cipi-gui.2fa'))->assertOk()->assertSee('Use a passkey')->assertDontSee('one-time-code', false);

        $options = $this->postJson(route('cipi-gui.2fa.passkey.options'))->assertOk()->json();
        $this->assertCount(1, $options['allowCredentials']);
        $this->postJson(route('cipi-gui.2fa.passkey'), ['credential' => $device->get($options)])->assertOk();

        $this->get(route('cipi-gui.dashboard'))->assertOk();
    }

    #[Test]
    public function a_passkey_is_not_added_with_the_password_alone_when_2fa_is_on(): void
    {
        $user = $this->admin(['two_factor_enabled' => true, 'two_factor_secret' => \Illuminate\Support\Facades\Crypt::encryptString('JBSWY3DPEHPK3PXP')]);

        $this->actingAs($user)->postJson(route('cipi-gui.passkeys.register.options'))->assertForbidden();
    }

    #[Test]
    public function a_passkey_satisfies_the_2fa_requirement(): void
    {
        Panel::set(Panel::ENFORCE_2FA, '1');
        $user = $this->member();

        // No second factor: the setup page, which offers a passkey too.
        $this->actingAs($user)->get(route('cipi-gui.dashboard'))->assertRedirect(route('cipi-gui.2fa.setup'));
        $this->get(route('cipi-gui.2fa.setup'))->assertOk()->assertSee('Use a passkey instead');

        $device = new SoftAuthenticator;
        $options = $this->postJson(route('cipi-gui.passkeys.register.options'))->assertOk()->json();
        $this->postJson(route('cipi-gui.passkeys.register'), ['credential' => $device->create($options), 'setup' => 1])->assertOk();

        $this->get(route('cipi-gui.dashboard'))->assertOk();
    }

    #[Test]
    public function the_last_passkey_stays_while_2fa_is_required_and_there_is_no_app(): void
    {
        $user = $this->admin();
        $this->addPasskey($user, new SoftAuthenticator);
        Panel::set(Panel::ENFORCE_2FA, '1');

        Livewire::test(PasskeyList::class)->call('remove', CipiPasskey::query()->first()->id)->assertDispatched('notify', type: 'error');
        $this->assertSame(1, CipiPasskey::query()->count());
    }

    #[Test]
    public function a_passkey_without_user_verification_is_refused(): void
    {
        $user = $this->admin();
        $this->actingAs($user)->withSession(['cipi_gui_2fa_verified' => true]);
        $options = $this->postJson(route('cipi-gui.passkeys.register.options'))->json();

        $this->postJson(route('cipi-gui.passkeys.register'), ['credential' => (new SoftAuthenticator(false))->create($options)])
            ->assertStatus(422)->assertJsonFragment(['message' => 'The authenticator did not verify the person (no PIN, fingerprint or face).']);
        $this->assertSame(0, CipiPasskey::query()->count());
    }
}
