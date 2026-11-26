<?php

namespace CipiGui\Services;

use CipiGui\Support\Cbor;

/**
 * WebAuthn (passkeys) without a library: what the panel checks of what a
 * browser sends when a passkey is created and when it is used. Pure
 * functions: the challenge, the origin and the relying party come from the
 * caller (Passkeys keeps them in the session).
 *
 * The panel asks for no attestation (it trusts the account that adds the
 * passkey, not the maker of the device): the attestation statement is not
 * read. It requires user verification (Face ID, a PIN, a fingerprint), so a
 * passkey is two factors in one: something the person has and is or knows.
 * Keys: ES256 (P-256) and RS256 — every platform and security key in use.
 */
final class WebAuthn
{
    public const ES256 = -7;

    public const RS256 = -257;

    private const FLAG_UP = 0x01;

    private const FLAG_UV = 0x04;

    private const FLAG_AT = 0x40;

    private const FLAG_ED = 0x80;

    public static function b64url(string $binary): string
    {
        return rtrim(strtr(base64_encode($binary), '+/', '-_'), '=');
    }

    public static function unb64url(string $text): string
    {
        $raw = base64_decode(strtr($text, '-_', '+/').str_repeat('=', (4 - strlen($text) % 4) % 4), true);

        if ($raw === false) {
            throw new WebAuthnException('Not base64url.');
        }

        return $raw;
    }

    public static function challenge(): string
    {
        return self::b64url(random_bytes(32));
    }

    /**
     * A new passkey (navigator.credentials.create), checked: the client data
     * is for this challenge and origin, the authenticator data for this
     * relying party, with the person present and verified, and a key the
     * panel can use.
     *
     * @param  array<string, mixed>  $credential  the PublicKeyCredential as JSON (id, rawId, type, response.clientDataJSON, response.attestationObject, response.transports)
     * @return array{credential_id: string, public_key: string, alg: int, sign_count: int, aaguid: string, transports: list<string>, backed_up: bool}
     */
    public static function verifyRegistration(array $credential, string $challenge, string $rpId): array
    {
        if (($credential['type'] ?? null) !== 'public-key') {
            throw new WebAuthnException('Not a public-key credential.');
        }

        $response = is_array($credential['response'] ?? null) ? $credential['response'] : [];
        self::clientData((string) ($response['clientDataJSON'] ?? ''), 'webauthn.create', $challenge, $rpId);

        try {
            $attestation = Cbor::decode(self::unb64url((string) ($response['attestationObject'] ?? '')));
        } catch (\InvalidArgumentException $e) {
            throw new WebAuthnException('The attestation object is not CBOR: '.$e->getMessage());
        }

        if (! is_array($attestation) || ! is_string($attestation['authData'] ?? null)) {
            throw new WebAuthnException('The attestation object has no authenticator data.');
        }

        $auth = self::authData($attestation['authData'], $rpId);

        if (! ($auth['flags'] & self::FLAG_AT) || $auth['credential_id'] === null || $auth['cose'] === null) {
            throw new WebAuthnException('The authenticator sent no credential.');
        }

        $rawId = self::unb64url((string) ($credential['rawId'] ?? $credential['id'] ?? ''));
        if (! hash_equals($auth['credential_id'], $rawId)) {
            throw new WebAuthnException('The credential id does not match the authenticator data.');
        }

        [$pem, $alg] = self::coseToPem($auth['cose']);

        $transports = array_values(array_filter((array) ($response['transports'] ?? []), fn ($t) => is_string($t) && preg_match('/^[a-z-]{2,20}$/', $t)));

        return [
            'credential_id' => self::b64url($auth['credential_id']),
            'public_key' => $pem,
            'alg' => $alg,
            'sign_count' => $auth['sign_count'],
            'aaguid' => $auth['aaguid'],
            'transports' => $transports,
            'backed_up' => (bool) ($auth['flags'] & 0x10),
        ];
    }

    /**
     * A sign-in (navigator.credentials.get), checked against the stored key:
     * client data, relying party, presence and verification, the signature
     * over authenticatorData || SHA-256(clientDataJSON), and a counter that
     * moved on (a copied authenticator would repeat it).
     *
     * @param  array<string, mixed>  $assertion
     * @return int the new signature counter
     */
    public static function verifyAssertion(array $assertion, string $challenge, string $rpId, string $publicKeyPem, int $storedCount, int $alg = self::ES256): int
    {
        if (($assertion['type'] ?? null) !== 'public-key') {
            throw new WebAuthnException('Not a public-key credential.');
        }

        $response = is_array($assertion['response'] ?? null) ? $assertion['response'] : [];
        $clientRaw = self::unb64url((string) ($response['clientDataJSON'] ?? ''));
        self::clientData((string) ($response['clientDataJSON'] ?? ''), 'webauthn.get', $challenge, $rpId);

        $authRaw = self::unb64url((string) ($response['authenticatorData'] ?? ''));
        $auth = self::authData($authRaw, $rpId);

        $signature = self::unb64url((string) ($response['signature'] ?? ''));
        $key = openssl_pkey_get_public($publicKeyPem);
        if ($key === false) {
            throw new WebAuthnException('The stored key is not readable.');
        }

        if (! in_array($alg, [self::ES256, self::RS256], true)) {
            throw new WebAuthnException('Unsupported key algorithm.');
        }

        if (openssl_verify($authRaw.hash('sha256', $clientRaw, true), $signature, $key, OPENSSL_ALGO_SHA256) !== 1) {
            throw new WebAuthnException('The signature does not verify.');
        }

        // Many passkeys (synced ones) always send 0: only a counter that went backwards is a sign of a clone.
        if ($auth['sign_count'] !== 0 || $storedCount !== 0) {
            if ($auth['sign_count'] <= $storedCount) {
                throw new WebAuthnException('The signature counter went backwards: this authenticator may have been copied.');
            }
        }

        return $auth['sign_count'];
    }

    /** Is this origin one the relying party may be used from? https (http on localhost), the host the rpId or below it. */
    public static function originAllowed(string $origin, string $rpId): bool
    {
        $parts = parse_url($origin);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower((string) ($parts['host'] ?? ''));
        $rpId = strtolower($rpId);

        if ($host === '' || $rpId === '' || isset($parts['path']) && $parts['path'] !== '' && $parts['path'] !== '/') {
            return false;
        }

        $local = in_array($host, ['localhost', '127.0.0.1', '[::1]'], true) || str_ends_with($host, '.localhost');
        if ($scheme !== 'https' && ! ($scheme === 'http' && $local)) {
            return false;
        }

        return $host === $rpId || str_ends_with($host, '.'.$rpId);
    }

    private static function clientData(string $encoded, string $type, string $challenge, string $rpId): void
    {
        $json = json_decode(self::unb64url($encoded), true);

        if (! is_array($json)) {
            throw new WebAuthnException('The client data is not JSON.');
        }

        if (($json['type'] ?? null) !== $type) {
            throw new WebAuthnException('The client data is for another ceremony.');
        }

        if (! is_string($json['challenge'] ?? null) || ! hash_equals($challenge, $json['challenge'])) {
            throw new WebAuthnException('The challenge does not match: start again.');
        }

        if (! is_string($json['origin'] ?? null) || ! self::originAllowed($json['origin'], $rpId)) {
            throw new WebAuthnException('The passkey was used from another site ('.mb_substr((string) ($json['origin'] ?? '?'), 0, 80).').');
        }

        if (($json['crossOrigin'] ?? false) === true) {
            throw new WebAuthnException('The passkey was used from inside another site.');
        }
    }

    /**
     * @return array{flags: int, sign_count: int, aaguid: string, credential_id: ?string, cose: ?array<int, mixed>}
     */
    private static function authData(string $raw, string $rpId): array
    {
        if (strlen($raw) < 37) {
            throw new WebAuthnException('The authenticator data is too short.');
        }

        if (! hash_equals(hash('sha256', $rpId, true), substr($raw, 0, 32))) {
            throw new WebAuthnException('The passkey belongs to another site.');
        }

        $flags = ord($raw[32]);
        if (! ($flags & self::FLAG_UP)) {
            throw new WebAuthnException('The person was not present.');
        }
        if (! ($flags & self::FLAG_UV)) {
            throw new WebAuthnException('The authenticator did not verify the person (no PIN, fingerprint or face).');
        }

        $count = unpack('N', substr($raw, 33, 4))[1];
        $out = ['flags' => $flags, 'sign_count' => (int) $count, 'aaguid' => '', 'credential_id' => null, 'cose' => null];

        if ($flags & self::FLAG_AT) {
            if (strlen($raw) < 55) {
                throw new WebAuthnException('The credential data is too short.');
            }
            $out['aaguid'] = bin2hex(substr($raw, 37, 16));
            $len = unpack('n', substr($raw, 53, 2))[1];
            if ($len < 16 || $len > 1023 || strlen($raw) < 55 + $len) {
                throw new WebAuthnException('The credential id has a wrong length.');
            }
            $out['credential_id'] = substr($raw, 55, $len);

            try {
                [$cose, $used] = Cbor::decodeFirst(substr($raw, 55 + $len));
            } catch (\InvalidArgumentException $e) {
                throw new WebAuthnException('The public key is not CBOR: '.$e->getMessage());
            }
            if (! is_array($cose)) {
                throw new WebAuthnException('The public key is not a COSE key.');
            }
            $out['cose'] = $cose;

            if (! ($flags & self::FLAG_ED) && 55 + $len + $used !== strlen($raw)) {
                throw new WebAuthnException('Bytes left after the public key.');
            }
        }

        return $out;
    }

    /**
     * A COSE public key as PEM, and its algorithm.
     *
     * @param  array<int, mixed>  $cose
     * @return array{0: string, 1: int}
     */
    public static function coseToPem(array $cose): array
    {
        $kty = $cose[1] ?? null;
        $alg = $cose[3] ?? null;

        if ($kty === 2 && $alg === self::ES256) {
            $x = $cose[-2] ?? null;
            $y = $cose[-3] ?? null;
            if (($cose[-1] ?? null) !== 1 || ! is_string($x) || ! is_string($y) || strlen($x) !== 32 || strlen($y) !== 32) {
                throw new WebAuthnException('Not a P-256 key.');
            }
            $der = hex2bin('3059301306072a8648ce3d020106082a8648ce3d030107034200')."\x04".$x.$y;

            return [self::pem($der), self::ES256];
        }

        if ($kty === 3 && $alg === self::RS256) {
            $n = $cose[-1] ?? null;
            $e = $cose[-2] ?? null;
            if (! is_string($n) || ! is_string($e) || strlen($n) < 256) {
                throw new WebAuthnException('Not an RSA key of 2048 bits or more.');
            }
            $rsa = self::der(0x30, self::derInteger($n).self::derInteger($e));
            $algo = self::der(0x30, hex2bin('06092a864886f70d0101010500'));
            $der = self::der(0x30, $algo.self::der(0x03, "\x00".$rsa));

            return [self::pem($der), self::RS256];
        }

        throw new WebAuthnException('This kind of key is not supported (ES256 or RS256).');
    }

    private static function pem(string $der): string
    {
        $pem = "-----BEGIN PUBLIC KEY-----\n".chunk_split(base64_encode($der), 64, "\n")."-----END PUBLIC KEY-----\n";

        if (openssl_pkey_get_public($pem) === false) {
            throw new WebAuthnException('The public key is not valid.');
        }

        return $pem;
    }

    private static function der(int $tag, string $content): string
    {
        $len = strlen($content);
        if ($len < 0x80) {
            return chr($tag).chr($len).$content;
        }
        $bytes = ltrim(pack('N', $len), "\x00");

        return chr($tag).chr(0x80 | strlen($bytes)).$bytes.$content;
    }

    private static function derInteger(string $unsigned): string
    {
        $unsigned = ltrim($unsigned, "\x00");
        if ($unsigned === '' || (ord($unsigned[0]) & 0x80)) {
            $unsigned = "\x00".$unsigned;
        }

        return self::der(0x02, $unsigned);
    }
}
