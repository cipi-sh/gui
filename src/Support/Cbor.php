<?php

namespace CipiGui\Support;

use InvalidArgumentException;

/**
 * A CBOR decoder (RFC 8949) for what WebAuthn sends: the attestation object
 * and the COSE public key inside it. Definite lengths, integers, byte and
 * text strings, arrays, maps, tags, simple values and floats; byte strings
 * come back as PHP strings (binary), text strings too.
 */
final class Cbor
{
    /** Nesting deeper than this is not a WebAuthn structure. */
    private const DEPTH = 16;

    /** The first item of $data, and how many bytes it took. @return array{0: mixed, 1: int} */
    public static function decodeFirst(string $data): array
    {
        $offset = 0;
        $value = self::item($data, $offset, 0);

        return [$value, $offset];
    }

    /** A whole CBOR document: one item, nothing after it. */
    public static function decode(string $data): mixed
    {
        [$value, $offset] = self::decodeFirst($data);

        if ($offset !== strlen($data)) {
            throw new InvalidArgumentException('CBOR: bytes left after the first item');
        }

        return $value;
    }

    private static function item(string $data, int &$offset, int $depth): mixed
    {
        if ($depth > self::DEPTH) {
            throw new InvalidArgumentException('CBOR: nested too deep');
        }

        $initial = self::byte($data, $offset);
        $major = $initial >> 5;
        $info = $initial & 0x1f;

        if ($major === 7) {
            return match ($info) {
                20 => false,
                21 => true,
                22, 23 => null,
                25 => self::half(self::read($data, $offset, 2)),
                26 => unpack('G', self::read($data, $offset, 4))[1],
                27 => unpack('E', self::read($data, $offset, 8))[1],
                default => throw new InvalidArgumentException('CBOR: unsupported simple value '.$info),
            };
        }

        $length = self::length($data, $offset, $info);

        return match ($major) {
            0 => $length,
            1 => -1 - $length,
            2, 3 => self::read($data, $offset, $length),
            4 => self::array($data, $offset, $length, $depth),
            5 => self::map($data, $offset, $length, $depth),
            6 => self::item($data, $offset, $depth + 1), // a tag: its content
        };
    }

    private static function length(string $data, int &$offset, int $info): int
    {
        if ($info < 24) {
            return $info;
        }

        $bytes = match ($info) {
            24 => 1, 25 => 2, 26 => 4, 27 => 8,
            default => throw new InvalidArgumentException('CBOR: indefinite or reserved length'),
        };
        $raw = self::read($data, $offset, $bytes);
        $value = 0;
        for ($i = 0; $i < $bytes; $i++) {
            $value = ($value << 8) | ord($raw[$i]);
        }

        if ($value < 0) {
            throw new InvalidArgumentException('CBOR: length out of range');
        }

        return $value;
    }

    /** @return list<mixed> */
    private static function array(string $data, int &$offset, int $count, int $depth): array
    {
        if ($count > strlen($data) - $offset) {
            throw new InvalidArgumentException('CBOR: array longer than the data');
        }

        $out = [];
        for ($i = 0; $i < $count; $i++) {
            $out[] = self::item($data, $offset, $depth + 1);
        }

        return $out;
    }

    /** @return array<int|string, mixed> */
    private static function map(string $data, int &$offset, int $count, int $depth): array
    {
        if ($count > strlen($data) - $offset) {
            throw new InvalidArgumentException('CBOR: map longer than the data');
        }

        $out = [];
        for ($i = 0; $i < $count; $i++) {
            $key = self::item($data, $offset, $depth + 1);
            if (! is_int($key) && ! is_string($key)) {
                throw new InvalidArgumentException('CBOR: map key that is neither an integer nor a string');
            }
            $out[$key] = self::item($data, $offset, $depth + 1);
        }

        return $out;
    }

    private static function byte(string $data, int &$offset): int
    {
        if ($offset >= strlen($data)) {
            throw new InvalidArgumentException('CBOR: unexpected end of data');
        }

        return ord($data[$offset++]);
    }

    private static function read(string $data, int &$offset, int $length): string
    {
        if ($length < 0 || $offset + $length > strlen($data)) {
            throw new InvalidArgumentException('CBOR: unexpected end of data');
        }

        $out = substr($data, $offset, $length);
        $offset += $length;

        return $out;
    }

    private static function half(string $raw): float
    {
        $half = (ord($raw[0]) << 8) | ord($raw[1]);
        $exp = ($half >> 10) & 0x1f;
        $mant = $half & 0x3ff;
        $value = match (true) {
            $exp === 0 => $mant * 2 ** -24,
            $exp !== 31 => ($mant + 1024) * 2 ** ($exp - 25),
            $mant === 0 => INF,
            default => NAN,
        };

        return ($half & 0x8000) ? -$value : $value;
    }
}
