<?php

namespace CipiGui\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * The OpenID Connect side of single sign-on, driven by `cipi-gui.sso` (written
 * into the panel's .env by `cipi gui sso` on the server): discovery, the
 * authorization URL with PKCE, the code exchange and the userinfo call.
 *
 * Identity comes from the userinfo endpoint — a server-to-server call over
 * TLS — so the ID token is decoded only to check nonce, audience and issuer,
 * and no signature library is needed.
 */
final class OidcClient
{
    private const DISCOVERY_TTL = 3600;

    private const DEFAULT_SCOPES = 'openid email profile';

    // ── configuration ────────────────────────────────────────────────

    /** Issuer, client id and secret are all set; otherwise the panel behaves as if SSO did not exist. */
    public static function configured(): bool
    {
        return self::issuer() !== ''
            && self::clientId() !== ''
            && trim((string) config('cipi-gui.sso.client_secret')) !== '';
    }

    public static function issuer(): string
    {
        return rtrim(trim((string) config('cipi-gui.sso.issuer')), '/');
    }

    public static function issuerHost(): string
    {
        return strtolower((string) parse_url(self::issuer(), PHP_URL_HOST));
    }

    public static function clientId(): string
    {
        return trim((string) config('cipi-gui.sso.client_id'));
    }

    /** Password sign-in is off for everyone; `cipi gui reset-user` on the server is the break-glass. */
    public static function only(): bool
    {
        return self::configured() && filter_var(config('cipi-gui.sso.only'), FILTER_VALIDATE_BOOL);
    }

    /** The button text: `CIPI_GUI_SSO_LABEL`, or a name for the well-known issuer hosts. */
    public static function label(): string
    {
        $label = trim((string) config('cipi-gui.sso.label'));
        if ($label !== '') {
            return $label;
        }

        $host = self::issuerHost();

        return match (true) {
            $host === 'login.microsoftonline.com' => 'Microsoft Entra ID',
            $host === 'accounts.google.com' => 'Google',
            str_contains($host, 'okta.com') => 'Okta',
            default => 'SSO',
        };
    }

    /** @return list<string> lowercase email domains whose people get an account on first sign-in */
    public static function domains(): array
    {
        $raw = config('cipi-gui.sso.domains', '');
        $parts = is_array($raw) ? $raw : explode(',', (string) $raw);

        return array_values(array_filter(array_map(fn ($domain) => strtolower(trim((string) $domain)), $parts)));
    }

    public static function scopes(): string
    {
        $raw = trim((string) config('cipi-gui.sso.scopes', self::DEFAULT_SCOPES));
        $scopes = preg_split('/[\s,]+/', $raw !== '' ? $raw : self::DEFAULT_SCOPES, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        if (! in_array('openid', $scopes, true)) {
            array_unshift($scopes, 'openid'); // without it the provider answers with plain OAuth and no ID token
        }

        return implode(' ', $scopes);
    }

    /** What to register at the identity provider. */
    public static function redirectUri(): string
    {
        return route('cipi-gui.sso.callback');
    }

    // ── the flow ─────────────────────────────────────────────────────

    /**
     * The provider's openid-configuration, cached for an hour.
     *
     * @return array<string, mixed>
     */
    public function discovery(): array
    {
        $issuer = self::issuer();

        return Cache::remember('cipi-gui.oidc.discovery.'.sha1($issuer), self::DISCOVERY_TTL, function () use ($issuer): array {
            $response = $this->call(fn (PendingRequest $http) => $http->get($issuer.'/.well-known/openid-configuration'));
            $document = (array) $response->json();

            if (! $response->successful()) {
                throw new OidcException('The identity provider did not serve its OpenID configuration (HTTP '.$response->status().').');
            }

            foreach (['authorization_endpoint', 'token_endpoint', 'userinfo_endpoint'] as $key) {
                if (! is_string($document[$key] ?? null) || $document[$key] === '') {
                    throw new OidcException("The OpenID configuration of the identity provider has no {$key}.");
                }
            }

            return $document;
        });
    }

    public function authorizationUrl(string $state, string $nonce, string $codeVerifier): string
    {
        $endpoint = (string) $this->discovery()['authorization_endpoint'];

        $query = http_build_query([
            'response_type' => 'code',
            'client_id' => self::clientId(),
            'redirect_uri' => self::redirectUri(),
            'scope' => self::scopes(),
            'state' => $state,
            'nonce' => $nonce,
            'code_challenge' => self::codeChallenge($codeVerifier),
            'code_challenge_method' => 'S256',
        ], '', '&', PHP_QUERY_RFC3986);

        return $endpoint.(str_contains($endpoint, '?') ? '&' : '?').$query;
    }

    /**
     * Trade the authorization code for tokens at the token endpoint.
     *
     * @return array{access_token: string, id_token: string}
     */
    public function exchangeCode(string $code, string $codeVerifier): array
    {
        $response = $this->call(fn (PendingRequest $http) => $http->asForm()->post((string) $this->discovery()['token_endpoint'], [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => self::redirectUri(),
            'client_id' => self::clientId(),
            'client_secret' => (string) config('cipi-gui.sso.client_secret'),
            'code_verifier' => $codeVerifier,
        ]));
        $tokens = (array) $response->json();

        if (! $response->successful() || ! is_string($tokens['access_token'] ?? null) || ! is_string($tokens['id_token'] ?? null)) {
            throw new OidcException('The identity provider did not issue a token: '.self::providerError($tokens, $response->status()));
        }

        return ['access_token' => $tokens['access_token'], 'id_token' => $tokens['id_token']];
    }

    /**
     * The claims of the userinfo endpoint; `sub` is guaranteed and a string.
     *
     * @return array<string, mixed>
     */
    public function userinfo(string $accessToken): array
    {
        $response = $this->call(fn (PendingRequest $http) => $http->withToken($accessToken)->get((string) $this->discovery()['userinfo_endpoint']));
        $claims = (array) $response->json();

        if (! $response->successful() || ! is_scalar($claims['sub'] ?? null) || (string) $claims['sub'] === '') {
            throw new OidcException('The identity provider did not return a profile: '.self::providerError($claims, $response->status()));
        }

        $claims['sub'] = (string) $claims['sub'];

        return $claims;
    }

    /**
     * The payload of an ID token — not its signature, see the class comment.
     *
     * @return array<string, mixed>
     */
    public static function idTokenClaims(string $idToken): array
    {
        $parts = explode('.', $idToken);
        $payload = count($parts) === 3 ? json_decode((string) self::base64UrlDecode($parts[1]), true) : null;

        if (! is_array($payload)) {
            throw new OidcException('The identity provider returned a malformed ID token.');
        }

        return $payload;
    }

    /** Issuer, audience and nonce of the ID token must be ours. */
    public static function verifyIdToken(array $claims, string $nonce): void
    {
        if (self::normalizeIssuer((string) ($claims['iss'] ?? '')) !== self::normalizeIssuer(self::issuer())) {
            throw new OidcException('The ID token was issued by "'.((string) ($claims['iss'] ?? '')).'", not by the configured issuer.');
        }

        if (! in_array(self::clientId(), array_map('strval', (array) ($claims['aud'] ?? [])), true)) {
            throw new OidcException('The ID token was not issued for this panel (audience mismatch).');
        }

        if ($nonce === '' || ! is_string($claims['nonce'] ?? null) || ! hash_equals($nonce, $claims['nonce'])) {
            throw new OidcException('The ID token does not belong to this sign-in attempt (nonce mismatch). Try again.');
        }
    }

    // ── PKCE ─────────────────────────────────────────────────────────

    /** 64 characters of [A-Za-z0-9]: within RFC 7636's alphabet and length. */
    public static function codeVerifier(): string
    {
        return Str::random(64);
    }

    public static function codeChallenge(string $verifier): string
    {
        return self::base64UrlEncode(hash('sha256', $verifier, true));
    }

    // ── helpers ──────────────────────────────────────────────────────

    /** @param  \Closure(PendingRequest): Response  $send */
    private function call(\Closure $send): Response
    {
        $http = Http::acceptJson()
            ->timeout((int) config('cipi-gui.http_timeout', 30))
            ->connectTimeout((int) config('cipi-gui.http_connect_timeout', 10));

        try {
            return $send($http);
        } catch (ConnectionException $e) {
            throw new OidcException('The identity provider could not be reached: '.$e->getMessage(), previous: $e);
        }
    }

    /** `accounts.google.com` and `https://accounts.google.com/` name the same issuer. */
    private static function normalizeIssuer(string $issuer): string
    {
        return rtrim(strtolower((string) preg_replace('#^https?://#i', '', trim($issuer))), '/');
    }

    private static function providerError(array $body, int $status): string
    {
        $message = $body['error_description'] ?? $body['error'] ?? null;

        return is_string($message) && $message !== '' ? $message : 'HTTP '.$status;
    }

    private static function base64UrlEncode(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    private static function base64UrlDecode(string $text): string|false
    {
        $text = strtr($text, '-_', '+/');

        return base64_decode($text.str_repeat('=', (4 - strlen($text) % 4) % 4), true);
    }
}
