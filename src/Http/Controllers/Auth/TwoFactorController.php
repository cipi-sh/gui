<?php

namespace CipiGui\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use CipiGui\Services\TwoFactorService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class TwoFactorController extends Controller
{
    public function show()
    {
        $user = Auth::user();

        if (! $user || ! ($user->two_factor_enabled ?? false)) {
            return redirect()->route('cipi-gui.dashboard');
        }

        if (session('cipi_gui_2fa_verified')) {
            return redirect()->route('cipi-gui.dashboard');
        }

        return view('cipi-gui::auth.2fa');
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
}
