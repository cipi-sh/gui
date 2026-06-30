<?php

namespace CipiGui\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class LoginController extends Controller
{
    public function show()
    {
        if (Auth::check()) {
            return redirect()->route('cipi-gui.dashboard');
        }

        return view('cipi-gui::auth.login');
    }

    public function login(Request $request)
    {
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
        $request->session()->forget('cipi_gui_2fa_verified');

        $user = Auth::user();

        if ($user->two_factor_enabled ?? false) {
            return redirect()->route('cipi-gui.2fa');
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
