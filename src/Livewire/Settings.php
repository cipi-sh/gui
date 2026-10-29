<?php

namespace CipiGui\Livewire;

use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use CipiGui\Services\Access;
use CipiGui\Services\OidcClient;
use CipiGui\Services\TwoFactorService;
use CipiGui\Support\Clock;
use CipiGui\Support\Panel;
use CipiGui\Support\Theme;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use DateTimeZone;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('cipi-gui::layouts.app')]
#[Title('Settings')]
class Settings extends Component
{
    public string $name = '';

    public string $email = '';

    /** IANA zone every time in the panel is shown in for this account; UTC until chosen. */
    public string $timezone = Clock::DEFAULT;

    public string $currentPassword = '';

    public string $newPassword = '';

    public string $newPasswordConfirmation = '';

    public bool $twoFactorEnabled = false;

    public ?string $setupSecret = null;

    public ?string $qrCodeSvg = null;

    public string $verificationCode = '';

    public string $disablePassword = '';

    // Panel (admins): fleet view and how a server is picked
    public bool $fleetView = true;

    public string $serverNav = 'sidebar';

    public function mount(): void
    {
        $user = Auth::user();
        $this->name = (string) ($user->name ?? '');
        $this->email = (string) ($user->email ?? '');
        $this->timezone = Clock::timezone($user);
        $this->twoFactorEnabled = (bool) ($user->two_factor_enabled ?? false);
        $this->fleetView = Panel::fleetView();
        $this->serverNav = Panel::serverNav();
    }

    /** Settings → Panel: saved for everyone, then the page reloads so the navigation follows. */
    public function savePanel(): void
    {
        if (! Access::isAdmin(Auth::user())) {
            $this->dispatch('notify', type: 'error', message: 'Only admins can change the panel settings.');

            return;
        }

        $this->validate(['serverNav' => ['required', 'in:sidebar,picker']]);

        Panel::set(Panel::FLEET_VIEW, $this->fleetView ? '1' : '0');
        Panel::set(Panel::SERVER_NAV, $this->serverNav);

        session()->flash('cipi_gui_flash', ['type' => 'success', 'message' => 'Panel settings saved.']);
        $this->redirect(route('cipi-gui.settings'));
    }

    public function saveProfile(): void
    {
        $user = Auth::user();

        $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique($user->getTable(), 'email')->ignore($user->getKey())],
            'timezone' => ['required', 'string', Rule::in(DateTimeZone::listIdentifiers())],
        ], [
            'timezone.in' => 'Pick a time zone from the list.',
        ]);

        $user->forceFill(['name' => $this->name, 'email' => $this->email, 'timezone' => $this->timezone])->save();
        $this->dispatch('notify', type: 'success', message: 'Profile saved.');
        $this->dispatch('profile-changed'); // the header shows the name
    }

    /** @return array<string, list<string>> zones grouped by region, for the profile's select */
    public function zones(): array
    {
        return Clock::zones();
    }

    public function changePassword(): void
    {
        $this->validate([
            'currentPassword' => ['required', 'string'],
            // Same policy as the `cipi gui` installer: 12+ chars, mixed case, digit, symbol.
            'newPassword' => ['required', 'string', 'different:currentPassword', Password::min(12)->mixedCase()->numbers()->symbols()],
            'newPasswordConfirmation' => ['required', 'same:newPassword'],
        ], [
            'newPasswordConfirmation.same' => 'The passwords do not match.',
            'newPassword.different' => 'Choose a password you have not used here.',
        ]);

        $user = Auth::user();

        if (! Hash::check($this->currentPassword, $user->getAuthPassword())) {
            $this->addError('currentPassword', 'The current password is not correct.');

            return;
        }

        $user->forceFill(['password' => Hash::make($this->newPassword)])->save();
        Auth::logoutOtherDevices($this->newPassword);

        $this->reset(['currentPassword', 'newPassword', 'newPasswordConfirmation']);
        $this->dispatch('notify', type: 'success', message: 'Password changed. Other sessions were signed out.');
    }

    public function startTwoFactorSetup(TwoFactorService $twoFactor): void
    {
        $user = Auth::user();
        $this->setupSecret = $twoFactor->generateSecret();

        $writer = new Writer(new ImageRenderer(new RendererStyle(220, 1), new SvgImageBackEnd));
        $this->qrCodeSvg = $writer->writeString($twoFactor->getQrCodeUrl($user, $this->setupSecret));
    }

    public function confirmTwoFactor(TwoFactorService $twoFactor): void
    {
        $this->validate(['verificationCode' => ['required', 'digits:6']]);

        if (! $this->setupSecret) {
            $this->addError('verificationCode', 'Start the setup again.');

            return;
        }

        if (! $twoFactor->enable(Auth::user(), $this->setupSecret, $this->verificationCode)) {
            $this->addError('verificationCode', 'That code is not valid. Check the time on your phone and try again.');

            return;
        }

        session()->put('cipi_gui_2fa_verified', true);
        $this->twoFactorEnabled = true;
        $this->reset(['setupSecret', 'qrCodeSvg', 'verificationCode']);
        $this->dispatch('notify', type: 'success', message: 'Two-factor authentication is on.');
    }

    public function disableTwoFactor(TwoFactorService $twoFactor): void
    {
        $this->validate(['disablePassword' => ['required', 'string']]);

        $user = Auth::user();

        if (! Hash::check($this->disablePassword, $user->getAuthPassword())) {
            $this->addError('disablePassword', 'Incorrect password.');
            $this->disablePassword = '';

            return;
        }

        $twoFactor->disable($user);
        session()->forget('cipi_gui_2fa_verified');
        $this->twoFactorEnabled = false;
        $this->disablePassword = '';
        $this->dispatch('notify', type: 'success', message: 'Two-factor authentication is off.');
    }

    public function cancelSetup(): void
    {
        $this->reset(['setupSecret', 'qrCodeSvg', 'verificationCode']);
    }

    /** Settings → Panel → Single sign-on, read-only: the panel's .env is written by `cipi gui sso` on the server. */
    private function sso(): array
    {
        return [
            'enabled' => OidcClient::configured(),
            'label' => OidcClient::label(),
            'host' => OidcClient::issuerHost(),
            'only' => OidcClient::only(),
            'domains' => OidcClient::domains(),
            'redirect' => OidcClient::redirectUri(),
        ];
    }

    public function render()
    {
        return view('cipi-gui::livewire.settings', [
            'isAdmin' => Access::isAdmin(Auth::user()),
            'role' => Access::role(Auth::user()),
            'sso' => $this->sso(),
            'versions' => [
                'cipi/gui' => Theme::VERSION,
                'Laravel' => app()->version(),
                'Livewire' => class_exists(\Composer\InstalledVersions::class) && \Composer\InstalledVersions::isInstalled('livewire/livewire')
                    ? ltrim((string) \Composer\InstalledVersions::getPrettyVersion('livewire/livewire'), 'v')
                    : '—',
                'PHP' => PHP_VERSION,
            ],
        ]);
    }
}
