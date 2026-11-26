<?php

namespace CipiGui\Services;

use CipiGui\Models\CipiPasskey;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Session\Session;
use Illuminate\Support\Facades\Schema;

/**
 * Passkeys in the panel (4.8.0), next to the password and 2FA:
 *  - sign in with one alone, from the login page — a passkey is two factors
 *    (the device, and the face, finger or PIN that unlocks it), so no code is
 *    asked after it;
 *  - or use one as the second step after the password, instead of the code;
 *  - added and removed in Settings. A passkey counts as two-factor
 *    authentication where an admin requires it for everyone.
 *
 * The relying party is the panel's host (cipi-gui.passkeys.rp_id to pin
 * another, e.g. the parent domain); every challenge lives in the session,
 * once, for five minutes.
 */
final class Passkeys
{
    public const REGISTER = 'cipi_gui_passkey_register';

    public const LOGIN = 'cipi_gui_passkey_login';

    public const SECOND = 'cipi_gui_passkey_2fa';

    private const TTL = 300;

    /** Passkeys are on unless turned off in the config, and off where only SSO signs in. */
    public static function enabled(): bool
    {
        return (bool) config('cipi-gui.passkeys.enabled', true) && ! OidcClient::only();
    }

    public static function rpId(): string
    {
        $configured = trim((string) config('cipi-gui.passkeys.rp_id', ''));

        return strtolower($configured !== '' ? $configured : request()->getHost());
    }

    /** Does this account have a passkey? (False before the migration ran.) */
    public static function has(?object $user): bool
    {
        if ($user === null || ! isset($user->id)) {
            return false;
        }

        try {
            return CipiPasskey::query()->where('user_id', $user->id)->exists();
        } catch (\Throwable) {
            return false;
        }
    }

    /** The account's user handle: stable, not personal (an HMAC of its id with the app key). */
    public static function userHandle(object $user): string
    {
        return WebAuthn::b64url(hash_hmac('sha256', 'cipi-gui-passkey:'.$user->id, (string) config('app.key'), true));
    }

    /** @return array<string, mixed> PublicKeyCredentialCreationOptions, as JSON for the browser */
    public static function registrationOptions(Authenticatable $user, Session $session): array
    {
        $challenge = WebAuthn::challenge();
        $session->put(self::REGISTER, ['challenge' => $challenge, 'user' => $user->getAuthIdentifier(), 'at' => time()]);

        return [
            'challenge' => $challenge,
            'rp' => ['name' => \CipiGui\Support\Theme::NAME, 'id' => self::rpId()],
            'user' => [
                'id' => self::userHandle($user),
                'name' => (string) ($user->email ?? $user->getAuthIdentifier()),
                'displayName' => (string) (($user->name ?? '') ?: ($user->email ?? '')),
            ],
            'pubKeyCredParams' => [['type' => 'public-key', 'alg' => WebAuthn::ES256], ['type' => 'public-key', 'alg' => WebAuthn::RS256]],
            'timeout' => self::TTL * 1000,
            'attestation' => 'none',
            'authenticatorSelection' => ['residentKey' => 'required', 'requireResidentKey' => true, 'userVerification' => 'required'],
            'excludeCredentials' => self::descriptors($user),
        ];
    }

    /** Check and keep a new passkey of the signed-in account. */
    public static function register(Authenticatable $user, array $credential, string $name, Session $session): CipiPasskey
    {
        $state = self::take($session, self::REGISTER);

        if ($state === null || (int) $state['user'] !== (int) $user->getAuthIdentifier()) {
            throw new WebAuthnException('This passkey request expired: try again.');
        }

        $data = WebAuthn::verifyRegistration($credential, $state['challenge'], self::rpId());
        $hash = CipiPasskey::hashOf($data['credential_id']);

        if (CipiPasskey::query()->where('credential_hash', $hash)->exists()) {
            throw new WebAuthnException('This passkey is already added.');
        }

        return CipiPasskey::create([
            'user_id' => $user->getAuthIdentifier(),
            'name' => mb_substr(trim($name) !== '' ? trim($name) : self::guessName($data), 0, 80),
            'credential_hash' => $hash,
            'credential_id' => $data['credential_id'],
            'public_key' => $data['public_key'],
            'alg' => $data['alg'],
            'sign_count' => $data['sign_count'],
            'aaguid' => $data['aaguid'] !== str_repeat('0', 32) ? $data['aaguid'] : null,
            'transports' => $data['transports'],
            'backed_up' => $data['backed_up'],
        ]);
    }

    /**
     * PublicKeyCredentialRequestOptions: for the login page (any passkey of
     * this panel: the browser offers the ones it has) or for the second step
     * of a signed-in account (its own).
     *
     * @return array<string, mixed>
     */
    public static function authenticationOptions(Session $session, ?Authenticatable $user = null): array
    {
        $challenge = WebAuthn::challenge();
        $session->put($user === null ? self::LOGIN : self::SECOND, ['challenge' => $challenge, 'user' => $user?->getAuthIdentifier(), 'at' => time()]);

        return [
            'challenge' => $challenge,
            'rpId' => self::rpId(),
            'timeout' => self::TTL * 1000,
            'userVerification' => 'required',
            'allowCredentials' => $user === null ? [] : self::descriptors($user),
        ];
    }

    /** The passkey a sign-in used, checked; its account must be $user when one is signed in already. */
    public static function authenticate(array $assertion, Session $session, ?Authenticatable $user = null): CipiPasskey
    {
        $state = self::take($session, $user === null ? self::LOGIN : self::SECOND);

        if ($state === null || ($user !== null && (int) $state['user'] !== (int) $user->getAuthIdentifier())) {
            throw new WebAuthnException('This sign-in expired: try again.');
        }

        $id = (string) ($assertion['rawId'] ?? $assertion['id'] ?? '');
        $passkey = $id !== '' ? CipiPasskey::query()->where('credential_hash', CipiPasskey::hashOf($id))->first() : null;

        if ($passkey === null || ($user !== null && (int) $passkey->user_id !== (int) $user->getAuthIdentifier())) {
            throw new WebAuthnException('This passkey is not one of this panel\'s accounts. Sign in with your password, then add it in Settings.');
        }

        // A passkey made for this account carries its handle: one made for another is refused.
        $handle = (string) ($assertion['response']['userHandle'] ?? '');
        $owner = $passkey->user_id;
        if ($handle !== '' && ! hash_equals(self::userHandle((object) ['id' => $owner]), $handle)) {
            throw new WebAuthnException('This passkey belongs to another account.');
        }

        $count = WebAuthn::verifyAssertion($assertion, $state['challenge'], self::rpId(), $passkey->public_key, (int) $passkey->sign_count, (int) $passkey->alg);
        $passkey->forceFill(['sign_count' => $count, 'last_used_at' => now()])->save();

        return $passkey;
    }

    /** @return list<array{type: string, id: string, transports?: list<string>}> */
    private static function descriptors(Authenticatable $user): array
    {
        try {
            return CipiPasskey::query()->where('user_id', $user->getAuthIdentifier())->get()
                ->map(fn (CipiPasskey $p) => array_filter(['type' => 'public-key', 'id' => $p->credential_id, 'transports' => $p->transports ?: null]))
                ->values()->all();
        } catch (\Throwable) {
            return [];
        }
    }

    /** @return array{challenge: string, user: mixed, at: int}|null the challenge of a ceremony, once */
    private static function take(Session $session, string $key): ?array
    {
        $state = $session->pull($key);

        if (! is_array($state) || ! is_string($state['challenge'] ?? null) || (int) ($state['at'] ?? 0) < time() - self::TTL) {
            return null;
        }

        return $state;
    }

    /** A name when the person gave none: what kind of passkey it is. */
    private static function guessName(array $data): string
    {
        $transports = $data['transports'] ?? [];

        return match (true) {
            in_array('internal', $transports, true) && ($data['backed_up'] ?? false) => 'Synced passkey',
            in_array('internal', $transports, true) => 'This device',
            in_array('hybrid', $transports, true) => 'Phone',
            in_array('usb', $transports, true) || in_array('nfc', $transports, true) => 'Security key',
            default => 'Passkey',
        }.' · '.now()->format('Y-m-d');
    }

    /** Whether an account may add a passkey in this session: verified, or with no second factor yet (it is setting one up). */
    public static function mayRegister(object $user, Session $session): bool
    {
        if ($session->get('cipi_gui_2fa_verified') || $session->get(TwoFactorService::SSO_SESSION)) {
            return true;
        }

        return ! TwoFactorService::hasSecondFactor($user);
    }

    /** Schema check for the views (a panel updated, not yet migrated). */
    public static function ready(): bool
    {
        try {
            return Schema::hasTable('cipi_passkeys');
        } catch (\Throwable) {
            return false;
        }
    }
}
