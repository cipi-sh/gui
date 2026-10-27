<?php

namespace CipiGui\Http\Controllers\Auth;

use CipiGui\Models\CipiInvite;
use CipiGui\Services\Access;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rules\Password;

/**
 * An invite link (Team → Invite) opens a sign-up form; the account gets the
 * role and the grants the admin chose when creating the link. One link, one
 * account; links expire.
 */
class InviteController
{
    public function show(string $token)
    {
        $invite = CipiInvite::query()->where('token', $token)->first();

        return view('cipi-gui::auth.invite', [
            'invite' => $invite,
            'usable' => $invite?->isUsable() ?? false,
            'token' => $token,
        ]);
    }

    public function accept(Request $request, string $token)
    {
        $invite = CipiInvite::query()->where('token', $token)->first();

        if (! $invite || ! $invite->isUsable()) {
            return redirect()->route('cipi-gui.invite', ['token' => $token]);
        }

        $model = config('auth.providers.users.model', 'App\\Models\\User');
        $table = (new $model)->getTable();

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:'.$table.',email'],
            // Same policy as Settings and the `cipi gui` installer.
            'password' => ['required', 'string', 'confirmed', Password::min(12)->mixedCase()->numbers()->symbols()],
        ]);

        $user = new $model;
        $user->forceFill([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => Hash::make($data['password']),
        ]);
        if (Schema::hasColumn($table, 'role')) {
            $user->forceFill(['role' => $invite->role === Access::ADMIN ? Access::ADMIN : Access::USER]);
        }
        $user->save();

        if ($invite->role !== Access::ADMIN) {
            Access::replaceGrants((int) $user->getKey(), (array) ($invite->server_grants ?? []), (array) ($invite->app_grants ?? []));
        }

        $invite->forceFill(['accepted_at' => now(), 'accepted_by' => $user->getKey()])->save();

        Auth::login($user);
        $request->session()->regenerate();
        $request->session()->forget('cipi_gui_2fa_verified');

        return redirect()->route('cipi-gui.dashboard');
    }
}
