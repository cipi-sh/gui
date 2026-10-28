<?php

namespace CipiGui\Http\Controllers\Auth;

use CipiGui\Services\Access;
use CipiGui\Services\OidcClient;
use CipiGui\Services\OidcException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Single sign-on over OpenID Connect (`cipi-gui.sso`, written by `cipi gui sso`
 * on the server). The identity provider authenticates the person — and runs
 * its own MFA, so the panel's 2FA step is not asked for — and the panel maps
 * the identity to an account: the one already linked to it, else the one with
 * the same email (linked on first sign-in). People from the auto-provisioned
 * domains get a user account with no access; everyone else needs an invite.
 */
class SsoController
{
    public function redirect(Request $request, OidcClient $oidc): RedirectResponse
    {
        abort_unless(OidcClient::configured(), 404);

        $state = Str::random(40);
        $nonce = Str::random(40);
        $verifier = OidcClient::codeVerifier();

        try {
            $url = $oidc->authorizationUrl($state, $nonce, $verifier);
        } catch (OidcException $e) {
            return $this->refuse($e->getMessage());
        }

        $request->session()->put([
            'cipi_gui_sso_state' => $state,
            'cipi_gui_sso_nonce' => $nonce,
            'cipi_gui_sso_verifier' => $verifier,
        ]);

        return redirect()->away($url);
    }

    public function callback(Request $request, OidcClient $oidc): RedirectResponse
    {
        abort_unless(OidcClient::configured(), 404);

        // One attempt per handshake: whatever happens next, these are spent.
        $state = (string) $request->session()->pull('cipi_gui_sso_state', '');
        $nonce = (string) $request->session()->pull('cipi_gui_sso_nonce', '');
        $verifier = (string) $request->session()->pull('cipi_gui_sso_verifier', '');

        if ($request->filled('error')) {
            return $this->refuse(OidcClient::label().' refused the sign-in: '.($request->input('error_description') ?: $request->input('error')));
        }

        if ($state === '' || ! hash_equals($state, (string) $request->query('state', ''))) {
            return $this->refuse('This sign-in did not start from this browser (state mismatch). Try again.');
        }

        if (! $request->filled('code')) {
            return $this->refuse('The identity provider sent no authorization code.');
        }

        try {
            $identity = $this->identity($oidc, (string) $request->query('code'), $verifier, $nonce);
            $user = $this->account($identity);
        } catch (OidcException $e) {
            return $this->refuse($e->getMessage());
        }

        Auth::login($user);
        $request->session()->regenerate();
        // The identity provider is the authority for MFA; the panel's own 2FA step is not asked for again.
        $request->session()->put('cipi_gui_2fa_verified', true);

        return redirect()->intended(route('cipi-gui.dashboard'));
    }

    /**
     * Who signed in: userinfo for the identity, the ID token for nonce, audience and issuer.
     *
     * @return array{sub: string, email: string, name: string}
     */
    private function identity(OidcClient $oidc, string $code, string $verifier, string $nonce): array
    {
        $tokens = $oidc->exchangeCode($code, $verifier);
        $idToken = OidcClient::idTokenClaims($tokens['id_token']);
        OidcClient::verifyIdToken($idToken, $nonce);

        $claims = $oidc->userinfo($tokens['access_token']);

        if ((string) ($idToken['sub'] ?? '') !== $claims['sub']) {
            throw new OidcException('The profile does not belong to the signed-in identity (subject mismatch).');
        }

        // Only a real email claim: preferred_username is a login name, not an address.
        $email = strtolower(trim((string) ($claims['email'] ?? '')));
        if ($email === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new OidcException('The identity provider did not share an email address. Ask its admin to release the email claim.');
        }

        if (in_array($claims['email_verified'] ?? null, [false, 'false', 0, '0'], true)) {
            throw new OidcException("The email {$email} is not verified at the identity provider.");
        }

        return ['sub' => $claims['sub'], 'email' => $email, 'name' => trim((string) ($claims['name'] ?? ''))];
    }

    /**
     * The account for an identity: by subject, else by email (linked now), else provisioned.
     *
     * @param  array{sub: string, email: string, name: string}  $identity
     */
    private function account(array $identity): object
    {
        $model = config('auth.providers.users.model', 'App\\Models\\User');

        if (! Schema::hasColumn((new $model)->getTable(), 'sso_subject')) {
            throw new OidcException('The panel database is not ready for single sign-on: an admin must run `cipi gui update` on the server.');
        }

        $user = $model::query()->where('sso_subject', $identity['sub'])->first();
        if ($user) {
            return $user;
        }

        $user = $model::query()->whereRaw('lower(email) = ?', [$identity['email']])->first();
        if ($user) {
            if (filled($user->sso_subject) && $user->sso_subject !== $identity['sub']) {
                throw new OidcException("The account {$identity['email']} is linked to another identity.");
            }

            if (blank($user->sso_subject)) {
                $user->forceFill(['sso_subject' => $identity['sub']])->save();
            }

            return $user;
        }

        return $this->provision($model, $identity);
    }

    /**
     * A user with no grants for someone from an auto-provisioned domain; an admin grants access in Team.
     *
     * @param  class-string<\Illuminate\Database\Eloquent\Model>  $model
     * @param  array{sub: string, email: string, name: string}  $identity
     */
    private function provision(string $model, array $identity): object
    {
        if (! in_array(Str::afterLast($identity['email'], '@'), OidcClient::domains(), true)) {
            throw new OidcException("No panel account for {$identity['email']}. Ask an admin for an invite link.");
        }

        $user = new $model;
        $user->forceFill([
            'name' => $identity['name'] !== '' ? $identity['name'] : Str::before($identity['email'], '@'),
            'email' => $identity['email'],
            'password' => Hash::make(Str::random(40)), // never used: the provider authenticates
            'sso_subject' => $identity['sub'],
        ]);
        if (Schema::hasColumn($user->getTable(), 'role')) {
            $user->forceFill(['role' => Access::USER]);
        }
        $user->save();

        return $user;
    }

    /** Back to the login page with the reason, like a failed password sign-in; nothing is created or logged in. */
    private function refuse(string $message): RedirectResponse
    {
        return redirect()->route('cipi-gui.login')->withErrors(['email' => $message]);
    }
}
