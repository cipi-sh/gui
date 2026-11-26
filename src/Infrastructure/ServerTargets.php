<?php

namespace CipiGui\Infrastructure;

use CipiGui\Models\CipiServer;

/**
 * The servers of the panel as targets of a DNS record: a record's value may
 * be a server's name (mozart), turned into its address (Connections → the
 * server's IP) when the record is saved, or any address typed. In a list of
 * records an address that is a server's says which.
 */
final class ServerTargets
{
    /** @var array<string, string>|null name => ip, for this request */
    private static ?array $servers = null;

    /** @return array<string, string> name => ip of every server that has one */
    public static function servers(): array
    {
        if (self::$servers !== null) {
            return self::$servers;
        }

        $servers = [];

        try {
            foreach (CipiServer::query()->orderBy('name')->get(['name', 'ip']) as $server) {
                if (is_string($server->ip) && $server->ip !== '') {
                    $servers[(string) $server->name] = $server->ip;
                }
            }
        } catch (\Throwable) {
            $servers = [];
        }

        return self::$servers = $servers;
    }

    /** Forget what this request read (tests, a server just saved). */
    public static function flush(): void
    {
        self::$servers = null;
    }

    /**
     * The suggestions of a record's value: each server by name.
     *
     * @return array<string, string> value => label
     */
    public static function options(): array
    {
        $options = [];
        foreach (self::servers() as $name => $ip) {
            $options[$name] = $name.' · '.$ip;
        }

        return $options;
    }

    /**
     * A record's value as the provider gets it: a server's name becomes its
     * address for A and AAAA records (of the right family), anything else is
     * kept as typed.
     */
    public static function resolve(?string $type, mixed $value): mixed
    {
        if (! is_string($value) || trim($value) === '') {
            return $value;
        }

        $value = trim($value);
        $type = strtoupper((string) $type);
        $ip = null;

        foreach (self::servers() as $name => $address) {
            if (strcasecmp($name, $value) === 0) {
                $ip = $address;
                break;
            }
        }

        if ($ip === null) {
            return $value;
        }

        $v6 = str_contains($ip, ':');

        if ($type === 'A' && $v6) {
            throw new ConnectorException(__('Server :name has an IPv6 address (:ip): use an AAAA record.', ['name' => $value, 'ip' => $ip]));
        }

        if ($type === 'AAAA' && ! $v6) {
            throw new ConnectorException(__('Server :name has an IPv4 address (:ip): use an A record.', ['name' => $value, 'ip' => $ip]));
        }

        if (! in_array($type, ['A', 'AAAA'], true)) {
            throw new ConnectorException(__('A server is a target for A and AAAA records; a :type record takes a name or a value.', ['type' => $type ?: '—']));
        }

        return $ip;
    }

    /**
     * Several values of one record set (one per line in the form), each resolved.
     *
     * @param  list<string>  $values
     * @return list<string>
     */
    public static function resolveAll(?string $type, array $values): array
    {
        return array_map(fn ($value) => (string) self::resolve($type, $value), $values);
    }

    /** The server an address is, when it is one of the panel's. */
    public static function nameOf(mixed $ip): ?string
    {
        if (! is_string($ip) || $ip === '') {
            return null;
        }

        $name = array_search($ip, self::servers(), true);

        return is_string($name) ? $name : null;
    }

    /** A record's value for a list: the address, and the server it is. */
    public static function label(mixed $value): string
    {
        $text = is_scalar($value) ? (string) $value : '';
        $name = self::nameOf($text);

        return $name !== null ? $text.' → '.$name : $text;
    }
}
