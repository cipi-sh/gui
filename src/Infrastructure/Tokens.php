<?php

namespace CipiGui\Infrastructure;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Short-lived access tokens for the providers that sign in with OAuth 2.0:
 * a Google Cloud service account (a JWT signed with its key) and an Azure
 * service principal (client credentials). Each is kept, encrypted, a little
 * less than it lives.
 */
final class Tokens
{
    /**
     * A Google access token for a service account's JSON key.
     *
     * @param  array<string, mixed>  $key  the JSON key, decoded
     */
    public static function google(array $key, string $scope = 'https://www.googleapis.com/auth/cloud-platform'): string
    {
        $email = (string) ($key['client_email'] ?? '');
        $private = (string) ($key['private_key'] ?? '');
        $uri = (string) ($key['token_uri'] ?? 'https://oauth2.googleapis.com/token');

        if ($email === '' || $private === '') {
            throw new ConnectorException(__('This is not a service account key: it needs client_email and private_key (IAM → Service accounts → Keys → Add key → JSON).'));
        }

        if (! str_starts_with($uri, 'https://')) {
            throw new ConnectorException(__('The key names a token address that is not https.'));
        }

        return self::remember('google:'.hash('sha256', $email.'|'.$private.'|'.$scope), function () use ($email, $private, $uri, $scope) {
            $now = time();
            $segments = [
                self::b64(json_encode(['alg' => 'RS256', 'typ' => 'JWT'])),
                self::b64(json_encode(['iss' => $email, 'scope' => $scope, 'aud' => $uri, 'iat' => $now, 'exp' => $now + 3600])),
            ];
            $input = implode('.', $segments);
            $pkey = openssl_pkey_get_private($private);

            if ($pkey === false || ! openssl_sign($input, $signature, $pkey, OPENSSL_ALGO_SHA256)) {
                throw new ConnectorException(__('The private key of the service account could not sign: paste the whole JSON key again.'));
            }

            $answer = self::form($uri, [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion' => $input.'.'.self::b64($signature),
            ]);

            return [(string) ($answer['access_token'] ?? ''), (int) ($answer['expires_in'] ?? 3600)];
        });
    }

    /** An Azure Resource Manager token for a service principal (an app registration and its secret). */
    public static function azure(string $tenant, string $clientId, string $secret, string $scope = 'https://management.azure.com/.default'): string
    {
        if (! preg_match('/^[A-Za-z0-9.-]{3,80}$/', $tenant)) {
            throw new ConnectorException(__('The tenant is its ID (a GUID) or its domain (contoso.onmicrosoft.com).'));
        }

        return self::remember('azure:'.hash('sha256', $tenant.'|'.$clientId.'|'.$secret.'|'.$scope), function () use ($tenant, $clientId, $secret, $scope) {
            $answer = self::form('https://login.microsoftonline.com/'.rawurlencode($tenant).'/oauth2/v2.0/token', [
                'grant_type' => 'client_credentials',
                'client_id' => $clientId,
                'client_secret' => $secret,
                'scope' => $scope,
            ]);

            return [(string) ($answer['access_token'] ?? ''), (int) ($answer['expires_in'] ?? 3600)];
        });
    }

    /**
     * A token from an OAuth 2.0 token endpoint: client credentials (Aikido,
     * with the client in Basic auth) or the password grant (Contabo).
     *
     * @param  array<string, string>  $form  grant_type and what it needs
     * @param  array{0: string, 1: string}|null  $basic  client id and secret, sent as Basic auth
     */
    public static function oauth(string $url, array $form, ?array $basic = null): string
    {
        if (! str_starts_with($url, 'https://')) {
            throw new ConnectorException(__('The token address must be https.'));
        }

        return self::remember('oauth:'.hash('sha256', $url.'|'.json_encode($form).'|'.json_encode($basic)), function () use ($url, $form, $basic) {
            $answer = self::form($url, $form, $basic);

            return [(string) ($answer['access_token'] ?? ''), (int) ($answer['expires_in'] ?? 3600)];
        });
    }

    /**
     * A sign-in that answers more than a token (Backblaze B2: the token, the
     * API address and the account), kept encrypted like the tokens.
     *
     * @param  callable(): array{0: array<string, mixed>, 1: int}  $fetch  the session and how long it lives
     * @return array<string, mixed>
     */
    public static function session(string $key, callable $fetch): array
    {
        $sealed = self::remember('session:'.hash('sha256', $key), function () use ($fetch) {
            [$session, $lifetime] = $fetch();

            return [(string) json_encode($session), $lifetime];
        });
        $session = json_decode($sealed, true);

        return is_array($session) ? $session : [];
    }

    /**
     * @param  callable(): array{0: string, 1: int}  $fetch  the token and how long it lives
     */
    private static function remember(string $key, callable $fetch): string
    {
        $cacheKey = 'cipi-gui.infra-token:'.$key;
        $sealed = Cache::get($cacheKey);

        if (is_string($sealed)) {
            try {
                $token = decrypt($sealed);
                if (is_string($token) && $token !== '') {
                    return $token;
                }
            } catch (\Throwable) {
                // An APP_KEY rotated since: ask again.
            }
        }

        [$token, $lifetime] = $fetch();

        if ($token === '') {
            throw new ConnectorException(__('The provider gave no access token.'));
        }

        Cache::put($cacheKey, encrypt($token), max(60, $lifetime - 300));

        return $token;
    }

    /**
     * @param  array<string, string>  $form
     * @return array<string, mixed>
     */
    private static function form(string $url, array $form, ?array $basic = null): array
    {
        try {
            $request = Http::asForm()->acceptJson()->connectTimeout(10)->timeout(30);
            $response = ($basic === null ? $request : $request->withBasicAuth($basic[0], $basic[1]))->post($url, $form);
        } catch (ConnectionException $e) {
            throw new ConnectorException(__('Not reachable: :reason', ['reason' => $e->getMessage()]), previous: $e);
        }

        $json = $response->json();
        $json = is_array($json) ? $json : [];

        if ($response->failed()) {
            $message = (string) ($json['error_description'] ?? $json['error'] ?? $response->body());
            throw new ConnectorException(__('Sign-in refused (:status): :message', ['status' => $response->status(), 'message' => mb_substr($message, 0, 300)]), $response->status());
        }

        return $json;
    }

    private static function b64(string|false $data): string
    {
        return rtrim(strtr(base64_encode((string) $data), '+/', '-_'), '=');
    }
}
