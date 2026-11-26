<?php

namespace CipiGui\Http\Controllers\Auth;

use CipiGui\Services\OidcClient;
use CipiGui\Services\Passkeys;
use CipiGui\Services\TwoFactorService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class TwoFactorController
{
    public function show()
    {
        $user = Auth::user();

        if (! $user || ! TwoFactorService::hasSecondFactor($user)) {
            return redirect()->route('cipi-gui.dashboard');
        }

        if (session('cipi_gui_2fa_verified')) {
            return redirect()->route('cipi-gui.dashboard');
        }

        return view('cipi-gui::auth.2fa', [
            'totp' => (bool) ($user->two_factor_enabled ?? false),
            'passkey' => Passkeys::enabled() && Passkeys::has($user),
        ]);
    }

    public function verify(Request $request, TwoFactorService $twoFactor)
    {
        $request->validate([
            'code' => ['required', 'string', 'size:6'],
        ]);

        $user = Auth::user();

        if (! $twoFactor->verify($user, $request->input('code'))) {
            throw ValidationException::withMessages([
                'code' => __('Invalid authentication code.'),
            ]);
        }

        $request->session()->put('cipi_gui_2fa_verified', true);

        return redirect()->intended(route('cipi-gui.dashboard'));
    }

    /**
     * The 2FA setup an account goes through before anything else when an admin
     * requires 2FA for everyone (4.6.0). The secret waits in the session — never
     * in the page — until a code from the app confirms it.
     */
    public function setup(Request $request, TwoFactorService $twoFactor)
    {
        $user = Auth::user();

        if (TwoFactorService::hasSecondFactor($user)) {
            return redirect()->route($request->session()->get('cipi_gui_2fa_verified') ? 'cipi-gui.dashboard' : 'cipi-gui.2fa');
        }

        if (! TwoFactorService::setupRequired($user, $request->session())) {
            // Not required: 2FA is set up from Settings, like before.
            return redirect()->route('cipi-gui.settings');
        }

        $secret = $request->session()->get(TwoFactorService::SETUP_SECRET);
        if (! is_string($secret) || $secret === '') {
            $secret = $twoFactor->generateSecret();
            $request->session()->put(TwoFactorService::SETUP_SECRET, $secret);
        }

        return view('cipi-gui::auth.2fa-setup', [
            'email' => (string) ($user->email ?? ''),
            'secret' => $secret,
            'qrCodeSvg' => $twoFactor->qrCodeSvg($user, $secret),
            'sso' => OidcClient::configured(),
            'ssoLabel' => OidcClient::label(),
            'passkeys' => Passkeys::enabled() && Passkeys::ready(),
        ]);
    }

    public function enable(Request $request, TwoFactorService $twoFactor)
    {
        $request->validate(['code' => ['required', 'digits:6']], [
            'code.digits' => __('Enter the 6 digits your authenticator app shows.'),
        ]);

        $user = Auth::user();

        if ($user->two_factor_enabled ?? false) {
            return redirect()->route('cipi-gui.dashboard');
        }

        $secret = $request->session()->get(TwoFactorService::SETUP_SECRET);
        if (! is_string($secret) || $secret === '') {
            return redirect()->route('cipi-gui.2fa.setup')
                ->withErrors(['code' => __('The setup started again: scan the new QR code.')]);
        }

        if (! $twoFactor->enable($user, $secret, (string) $request->input('code'))) {
            throw ValidationException::withMessages([
                'code' => __('That code is not valid. Check the time on your phone and try again.'),
            ]);
        }

        $request->session()->forget(TwoFactorService::SETUP_SECRET);
        $request->session()->put('cipi_gui_2fa_verified', true);
        $request->session()->flash('cipi_gui_flash', ['type' => 'success', 'message' => __('Two-factor authentication is on. Every sign-in asks for a code from now on.')]);

        return redirect()->intended(route('cipi-gui.dashboard'));
    }
}
