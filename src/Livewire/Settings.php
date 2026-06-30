<?php

namespace CipiGui\Livewire;

use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use CipiGui\Services\TwoFactorService;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('cipi-gui::layouts.app')]
#[Title('Settings')]
class Settings extends Component
{
    public bool $twoFactorEnabled = false;

    public ?string $setupSecret = null;

    public ?string $qrCodeSvg = null;

    public string $verificationCode = '';

    public string $currentPassword = '';

    public ?string $error = null;

    public ?string $success = null;

    public function mount(): void
    {
        $user = Auth::user();
        $this->twoFactorEnabled = (bool) ($user->two_factor_enabled ?? false);
    }

    public function startTwoFactorSetup(TwoFactorService $twoFactor): void
    {
        $this->error = null;
        $user = Auth::user();
        $this->setupSecret = $twoFactor->generateSecret();
        $qrUrl = $twoFactor->getQrCodeUrl($user, $this->setupSecret);

        $renderer = new ImageRenderer(
            new RendererStyle(200),
            new SvgImageBackEnd,
        );
        $writer = new Writer($renderer);
        $this->qrCodeSvg = $writer->writeString($qrUrl);
    }

    public function confirmTwoFactor(TwoFactorService $twoFactor): void
    {
        $this->validate([
            'verificationCode' => ['required', 'string', 'size:6'],
        ]);

        $user = Auth::user();

        if ($twoFactor->enable($user, $this->setupSecret, $this->verificationCode)) {
            $this->twoFactorEnabled = true;
            $this->setupSecret = null;
            $this->qrCodeSvg = null;
            $this->verificationCode = '';
            $this->success = 'Two-factor authentication enabled.';
        } else {
            $this->error = 'Invalid verification code. Try again.';
        }
    }

    public function disableTwoFactor(TwoFactorService $twoFactor): void
    {
        $this->validate([
            'currentPassword' => ['required', 'string'],
        ]);

        $user = Auth::user();

        if (! Auth::guard('web')->validate(['email' => $user->email, 'password' => $this->currentPassword])) {
            $this->error = 'Incorrect password.';
            $this->currentPassword = '';

            return;
        }

        $twoFactor->disable($user);
        session()->forget('cipi_gui_2fa_verified');
        $this->twoFactorEnabled = false;
        $this->currentPassword = '';
        $this->success = 'Two-factor authentication disabled.';
    }

    public function cancelSetup(): void
    {
        $this->setupSecret = null;
        $this->qrCodeSvg = null;
        $this->verificationCode = '';
    }

    public function render()
    {
        return view('cipi-gui::livewire.settings');
    }
}
