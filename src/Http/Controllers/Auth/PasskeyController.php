<?php

namespace CipiGui\Http\Controllers\Auth;

use CipiGui\Models\CipiPasskey;
use CipiGui\Services\Passkeys;
use CipiGui\Services\TwoFactorService;
use CipiGui\Services\WebAuthnException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

/**
 * The passkey ceremonies, for the panel's own script (fetch → navigator.credentials
 * → fetch): options with a fresh challenge, then the browser's answer to check.
 *
 *  - /passkeys/login…   (guests)    sign in with a passkey alone;
 *  - /2fa/passkey…      (signed in) the second step after the password;
 *  - /passkeys/register… (signed in) add one (Settings, or the 2FA setup).
 */
class PasskeyController
{
    public function loginOptions(Request $request): JsonResponse
    {
        abort_unless(Passkeys::enabled(), 404);

        return response()->json(Passkeys::authenticationOptions($request->session()));
    }

    public function login(Request $request): JsonResponse
    {
        abort_unless(Passkeys::enabled(), 404);

        try {
            $passkey = Passkeys::authenticate($this->credential($request), $request->session());
        } catch (WebAuthnException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $guard = Auth::guard(config('cipi-gui.guard', 'web'));
        $user = $guard->getProvider()->retrieveById($passkey->user_id);

        if ($user === null) {
            return response()->json(['message' => 'This passkey\'s account no longer exists.'], 422);
        }

        $guard->login($user, $request->boolean('remember'));
        $request->session()->regenerate();
        $request->session()->forget(TwoFactorService::SSO_SESSION);
        // A passkey is two factors in one (the device, and what unlocks it): no code after it.
        $request->session()->put('cipi_gui_2fa_verified', true);
        Log::info('cipi-gui.passkey', ['user' => $passkey->user_id, 'passkey' => $passkey->id, 'action' => 'sign-in']);

        return response()->json(['redirect' => $request->session()->pull('url.intended', route('cipi-gui.dashboard'))]);
    }

    public function secondStepOptions(Request $request): JsonResponse
    {
        abort_unless(Passkeys::enabled(), 404);

        return response()->json(Passkeys::authenticationOptions($request->session(), $request->user()));
    }

    public function secondStep(Request $request): JsonResponse
    {
        abort_unless(Passkeys::enabled(), 404);

        try {
            $passkey = Passkeys::authenticate($this->credential($request), $request->session(), $request->user());
        } catch (WebAuthnException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $request->session()->put('cipi_gui_2fa_verified', true);
        Log::info('cipi-gui.passkey', ['user' => $passkey->user_id, 'passkey' => $passkey->id, 'action' => 'second-step']);

        return response()->json(['redirect' => $request->session()->pull('url.intended', route('cipi-gui.dashboard'))]);
    }

    public function registerOptions(Request $request): JsonResponse
    {
        abort_unless(Passkeys::enabled(), 404);
        abort_unless(Passkeys::mayRegister($request->user(), $request->session()), 403, 'Give your two-factor code first.');

        return response()->json(Passkeys::registrationOptions($request->user(), $request->session()));
    }

    public function register(Request $request): JsonResponse
    {
        abort_unless(Passkeys::enabled(), 404);
        abort_unless(Passkeys::mayRegister($request->user(), $request->session()), 403, 'Give your two-factor code first.');

        $request->validate(['name' => ['nullable', 'string', 'max:80']]);
        $first = ! TwoFactorService::hasSecondFactor($request->user());

        try {
            $passkey = Passkeys::register($request->user(), $this->credential($request), (string) $request->input('name', ''), $request->session());
        } catch (WebAuthnException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        // The account's first second factor, added in this session: the session has it now.
        if ($first) {
            $request->session()->put('cipi_gui_2fa_verified', true);
        }
        Log::info('cipi-gui.passkey', ['user' => $passkey->user_id, 'passkey' => $passkey->id, 'action' => 'added']);

        return response()->json([
            'id' => $passkey->id,
            'name' => $passkey->name,
            'redirect' => $first && $request->boolean('setup') ? $request->session()->pull('url.intended', route('cipi-gui.dashboard')) : null,
        ]);
    }

    /** @return array<string, mixed> the PublicKeyCredential the script sent */
    private function credential(Request $request): array
    {
        $credential = $request->input('credential');

        if (! is_array($credential) || ! is_string($credential['id'] ?? null) || ! is_array($credential['response'] ?? null)) {
            throw new WebAuthnException('The browser sent no passkey.');
        }

        return $credential;
    }
}
