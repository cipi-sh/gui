<?php
// Passkeys without a browser (php tests/standalone-passkeys.php, no vendor/): a software
// authenticator — real P-256 and RSA keys from OpenSSL — creates and uses passkeys, and
// the panel's WebAuthn checks take what is right and refuse what is not.
namespace {
    $root = $argv[1] ?? dirname(__DIR__);
    require $root.'/src/Support/Cbor.php';
    require $root.'/src/Services/WebAuthnException.php';
    require $root.'/src/Services/WebAuthn.php';

    use CipiGui\Services\WebAuthn as W;
    use CipiGui\Support\Cbor;

    $pass = 0; $fail = 0;
    $check = function (bool $ok, string $what) use (&$pass, &$fail): void {
        if ($ok) { $pass++; echo "  OK: {$what}\n"; } else { $fail++; fwrite(STDERR, "  FAIL: {$what}\n"); }
    };
    $refused = function (callable $f, string $needle): bool {
        try { $f(); } catch (\CipiGui\Services\WebAuthnException $e) { return str_contains($e->getMessage(), $needle) ?: (fwrite(STDERR, '    said: '.$e->getMessage()."\n") && false); }
        return false;
    };

    // A CBOR encoder, enough for an authenticator.
    function cbor(mixed $v): string
    {
        $head = function (int $major, int $len): string {
            if ($len < 24) { return chr(($major << 5) | $len); }
            if ($len < 256) { return chr(($major << 5) | 24).chr($len); }
            if ($len < 65536) { return chr(($major << 5) | 25).pack('n', $len); }
            return chr(($major << 5) | 26).pack('N', $len);
        };
        return match (true) {
            is_int($v) && $v >= 0 => $head(0, $v),
            is_int($v) => $head(1, -1 - $v),
            $v instanceof Bytes => $head(2, strlen($v->b)).$v->b,
            is_string($v) => $head(3, strlen($v)).$v,
            is_bool($v) => chr($v ? 0xf5 : 0xf4),
            $v === null => chr(0xf6),
            is_array($v) && array_is_list($v) && $v !== [] => $head(4, count($v)).implode('', array_map('cbor', $v)),
            is_array($v) => $head(5, count($v)).implode('', array_map(fn ($k, $x) => cbor($k).cbor($x), array_keys($v), $v)),
        };
    }
    final class Bytes { public function __construct(public string $b) {} }

    // The software authenticator: one key pair, a credential id, a counter.
    final class Authenticator
    {
        public $key;
        public string $id;
        public int $count = 0;
        public function __construct(public string $kind = 'ec', public bool $verifies = true)
        {
            $this->key = $kind === 'ec'
                ? openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1'])
                : openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048]);
            $this->id = random_bytes(32);
        }
        public function cose(): string
        {
            $d = openssl_pkey_get_details($this->key);
            return $this->kind === 'ec'
                ? cbor([1 => 2, 3 => -7, -1 => 1, -2 => new Bytes(str_pad($d['ec']['x'], 32, "\0", STR_PAD_LEFT)), -3 => new Bytes(str_pad($d['ec']['y'], 32, "\0", STR_PAD_LEFT))])
                : cbor([1 => 3, 3 => -257, -1 => new Bytes($d['rsa']['n']), -2 => new Bytes($d['rsa']['e'])]);
        }
        public function flags(bool $at): int { return 0x01 | ($this->verifies ? 0x04 : 0) | ($at ? 0x40 : 0); }
        public function create(string $challenge, string $origin, string $rpId, ?string $type = 'webauthn.create'): array
        {
            $auth = hash('sha256', $rpId, true).chr($this->flags(true)).pack('N', $this->count).str_repeat("\x11", 16).pack('n', strlen($this->id)).$this->id.$this->cose();
            $client = json_encode(['type' => $type, 'challenge' => $challenge, 'origin' => $origin, 'crossOrigin' => false]);
            return ['id' => W::b64url($this->id), 'rawId' => W::b64url($this->id), 'type' => 'public-key',
                'response' => ['clientDataJSON' => W::b64url($client), 'attestationObject' => W::b64url(cbor(['fmt' => 'none', 'attStmt' => [], 'authData' => new Bytes($auth)])), 'transports' => ['internal', 'hybrid', '<script>']]];
        }
        public function get(string $challenge, string $origin, string $rpId, int $step = 1): array
        {
            $this->count += $step;
            $auth = hash('sha256', $rpId, true).chr($this->flags(false)).pack('N', $this->count);
            $client = json_encode(['type' => 'webauthn.get', 'challenge' => $challenge, 'origin' => $origin]);
            openssl_sign($auth.hash('sha256', $client, true), $sig, $this->key, OPENSSL_ALGO_SHA256);
            return ['id' => W::b64url($this->id), 'rawId' => W::b64url($this->id), 'type' => 'public-key',
                'response' => ['clientDataJSON' => W::b64url($client), 'authenticatorData' => W::b64url($auth), 'signature' => W::b64url($sig), 'userHandle' => W::b64url('u1')]];
        }
    }

    echo "--- CBOR\n";
    $check(Cbor::decode(cbor([1 => 2, -1 => 'x', 'k' => [1, -24, -25, 1000, 70000], 'b' => new Bytes("\x00\xff")])) === [1 => 2, -1 => 'x', 'k' => [1, -24, -25, 1000, 70000], 'b' => "\x00\xff"], 'maps with integer and text keys, negative and long integers, byte strings');
    $check(Cbor::decode("\xf5") === true && Cbor::decode("\xf6") === null && Cbor::decode("\xf9\x3c\x00") === 1.0 && Cbor::decode("\xc0\x63abc") === 'abc', 'true, null, a half float, a tagged item');
    $bad = 0;
    foreach (["\x5f", "\x9f\xff", "\x58\x10ab", "\xa1\x01", "\x01\x02", str_repeat("\x81", 40)."\x00"] as $junk) {
        try { Cbor::decode($junk); } catch (\InvalidArgumentException) { $bad++; }
    }
    $check($bad === 6, 'indefinite lengths, truncated data, trailing bytes and absurd nesting are refused');

    $rp = 'panel.example.com';
    $origin = 'https://panel.example.com';
    foreach (['ec' => 'ES256 (P-256)', 'rsa' => 'RS256 (RSA 2048)'] as $kind => $label) {
        echo "--- {$label}\n";
        $a = new Authenticator($kind);
        $ch = W::challenge();
        $reg = W::verifyRegistration($a->create($ch, $origin, $rp), $ch, $rp);
        $check($reg['credential_id'] === W::b64url($a->id) && str_contains($reg['public_key'], 'BEGIN PUBLIC KEY') && $reg['alg'] === ($kind === 'ec' ? -7 : -257) && $reg['aaguid'] === str_repeat('11', 16), 'a new passkey: its id, its key as PEM, its algorithm and model');
        $check($reg['transports'] === ['internal', 'hybrid'], 'transports kept, anything else dropped');
        $ch = W::challenge();
        $count = W::verifyAssertion($a->get($ch, $origin, $rp), $ch, $rp, $reg['public_key'], 0, $reg['alg']);
        $check($count === 1, 'a sign-in with it verifies; the counter moves on');
        $ch = W::challenge();
        $check($refused(fn () => W::verifyAssertion($a->get($ch, $origin, $rp, 0), $ch, $rp, $reg['public_key'], 1, $reg['alg']), 'counter went backwards'), 'a counter that did not move is refused (a copied authenticator)');
        $other = new Authenticator($kind);
        $ch = W::challenge();
        $check($refused(fn () => W::verifyAssertion($other->get($ch, $origin, $rp), $ch, $rp, $reg['public_key'], 0, $reg['alg']), 'signature does not verify'), 'another key\'s signature is refused');
    }

    echo "--- what is refused\n";
    $a = new Authenticator();
    $ch = W::challenge();
    $check($refused(fn () => W::verifyRegistration($a->create(W::challenge(), $origin, $rp), $ch, $rp), 'challenge does not match'), 'another challenge');
    $check($refused(fn () => W::verifyRegistration($a->create($ch, 'https://evil.example.net', $rp), $ch, $rp), 'another site'), 'another origin');
    $check($refused(fn () => W::verifyRegistration($a->create($ch, 'http://panel.example.com', $rp), $ch, $rp), 'another site'), 'plain http (not localhost)');
    $check($refused(fn () => W::verifyRegistration($a->create($ch, $origin, 'other.example.com'), $ch, $rp), 'belongs to another site'), 'an rpId hash for another site');
    $check($refused(fn () => W::verifyRegistration($a->create($ch, $origin, $rp, 'webauthn.get'), $ch, $rp), 'another ceremony'), 'a sign-in sent as a registration');
    $nouv = new Authenticator('ec', false);
    $check($refused(fn () => W::verifyRegistration($nouv->create($ch, $origin, $rp), $ch, $rp), 'did not verify the person'), 'no user verification (no PIN, fingerprint or face)');
    $cred = $a->create($ch, $origin, $rp);
    $cred['rawId'] = W::b64url(random_bytes(32));
    $check($refused(fn () => W::verifyRegistration($cred, $ch, $rp), 'does not match the authenticator data'), 'a credential id that is not the one in the data');
    $check(W::originAllowed('https://panel.example.com', 'panel.example.com') && W::originAllowed('https://a.panel.example.com', 'panel.example.com') && W::originAllowed('http://localhost:8000', 'localhost')
        && ! W::originAllowed('https://panel.example.com.evil.net', 'panel.example.com') && ! W::originAllowed('https://xpanel.example.com', 'panel.example.com') && ! W::originAllowed('https://panel.example.com/x', 'panel.example.com'), 'origins: the host or below it, https or localhost, nothing that only looks alike');
    $check(str_contains((string) (function () { try { W::coseToPem([1 => 1, 3 => -8, -1 => 6, -2 => str_repeat('a', 32)]); } catch (\Throwable $e) { return $e->getMessage(); } })(), 'not supported'), 'Ed25519 (not asked for) is refused plainly');

    echo "\n=== {$pass} passed, {$fail} failed ===\n";
    exit($fail === 0 ? 0 : 1);
}
