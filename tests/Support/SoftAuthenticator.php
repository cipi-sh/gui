<?php

namespace CipiGui\Tests\Support;

use CipiGui\Services\WebAuthn;

/**
 * A software authenticator for the passkey tests: a real P-256 key pair from
 * OpenSSL, a credential id and a signature counter, answering what a browser
 * would send for navigator.credentials.create() and .get().
 */
final class SoftAuthenticator
{
    /** @var \OpenSSLAsymmetricKey */
    private $key;

    public string $id;

    public int $count = 0;

    public function __construct(public bool $verifies = true)
    {
        $this->key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        $this->id = random_bytes(32);
    }

    /** @param  array<string, mixed>  $options  what the panel's options route answered */
    public function create(array $options, string $origin = 'http://localhost'): array
    {
        $rpId = (string) ($options['rp']['id'] ?? 'localhost');
        $d = openssl_pkey_get_details($this->key);
        $cose = self::cbor([1 => 2, 3 => -7, -1 => 1, -2 => ['bytes' => str_pad($d['ec']['x'], 32, "\0", STR_PAD_LEFT)], -3 => ['bytes' => str_pad($d['ec']['y'], 32, "\0", STR_PAD_LEFT)]]);
        $flags = 0x01 | ($this->verifies ? 0x04 : 0) | 0x40;
        $auth = hash('sha256', $rpId, true).chr($flags).pack('N', $this->count).str_repeat("\0", 16).pack('n', strlen($this->id)).$this->id.$cose;
        $client = json_encode(['type' => 'webauthn.create', 'challenge' => $options['challenge'], 'origin' => $origin, 'crossOrigin' => false]);

        return [
            'id' => WebAuthn::b64url($this->id), 'rawId' => WebAuthn::b64url($this->id), 'type' => 'public-key',
            'response' => [
                'clientDataJSON' => WebAuthn::b64url($client),
                'attestationObject' => WebAuthn::b64url(self::cbor(['fmt' => 'none', 'attStmt' => [], 'authData' => ['bytes' => $auth]])),
                'transports' => ['internal'],
            ],
        ];
    }

    /** @param  array<string, mixed>  $options */
    public function get(array $options, ?string $userHandle = null, string $origin = 'http://localhost'): array
    {
        $this->count++;
        $rpId = (string) ($options['rpId'] ?? 'localhost');
        $auth = hash('sha256', $rpId, true).chr(0x01 | ($this->verifies ? 0x04 : 0)).pack('N', $this->count);
        $client = json_encode(['type' => 'webauthn.get', 'challenge' => $options['challenge'], 'origin' => $origin]);
        openssl_sign($auth.hash('sha256', $client, true), $signature, $this->key, OPENSSL_ALGO_SHA256);

        return [
            'id' => WebAuthn::b64url($this->id), 'rawId' => WebAuthn::b64url($this->id), 'type' => 'public-key',
            'response' => [
                'clientDataJSON' => WebAuthn::b64url($client), 'authenticatorData' => WebAuthn::b64url($auth),
                'signature' => WebAuthn::b64url($signature), 'userHandle' => $userHandle,
            ],
        ];
    }

    /** CBOR for what an authenticator sends; ['bytes' => …] is a byte string. */
    public static function cbor(mixed $v): string
    {
        $head = function (int $major, int $len): string {
            if ($len < 24) {
                return chr(($major << 5) | $len);
            }
            if ($len < 256) {
                return chr(($major << 5) | 24).chr($len);
            }

            return chr(($major << 5) | 25).pack('n', $len);
        };

        if (is_array($v) && array_keys($v) === ['bytes']) {
            return $head(2, strlen($v['bytes'])).$v['bytes'];
        }

        return match (true) {
            is_int($v) && $v >= 0 => $head(0, $v),
            is_int($v) => $head(1, -1 - $v),
            is_string($v) => $head(3, strlen($v)).$v,
            is_array($v) && $v === [] => $head(5, 0),
            is_array($v) => $head(5, count($v)).implode('', array_map(fn ($k, $x) => self::cbor($k).self::cbor($x), array_keys($v), $v)),
        };
    }
}
