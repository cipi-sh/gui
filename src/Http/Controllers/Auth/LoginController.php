<?php

namespace CipiGui\Http\Controllers\Auth;

use CipiGui\Services\OidcClient;
use CipiGui\Services\TwoFactorService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class LoginController
{
    public function show()
    {
        if (Auth::check()) {
            return redirect()->route('cipi-gui.dashboard');
        }

        return view('cipi-gui::auth.login', [
            'sso' => OidcClient::configured(),
            'ssoLabel' => OidcClient::label(),
            'ssoOnly' => OidcClient::only(),
        ]);
    }

    public function login(Request $request)
    {
        if (OidcClient::only()) {
            throw ValidationException::withMessages([
                'email' => __('Password sign-in is off on this panel. Use "Sign in with :label".', ['label' => OidcClient::label()]),
            ]);
        }

        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        if (! Auth::attempt($credentials, $request->boolean('remember'))) {
            throw ValidationException::withMessages([
                'email' => __('These credentials do not match our records.'),
            ]);
        }

        $request->session()->regenerate();
        $request->session()->forget(['cipi_gui_2fa_verified', TwoFactorService::SSO_SESSION]);

        $user = Auth::user();

        if ($user->two_factor_enabled ?? false) {
            return redirect()->route('cipi-gui.2fa');
        }

        // 2FA required for every account and this one has none: set up first,
        // then the page that was asked for (the intended URL is kept).
        if (TwoFactorService::setupRequired($user, $request->session())) {
            return redirect()->route('cipi-gui.2fa.setup');
        }

        return redirect()->intended(route('cipi-gui.dashboard'));
    }

    public function logout(Request $request)
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('cipi-gui.login');
    }
}
