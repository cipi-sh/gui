<?php

namespace CipiGui\Support;

/**
 * The nginx page cache of an app (`cipi cache`, Cipi 5.17.0): the values its
 * form takes, checked as the server checks them, and how its numbers read.
 *
 * The page cache is not OPcache. OPcache is on already on every Cipi server
 * and keeps PHP's compiled code in memory — PHP still runs at every request.
 * With the page cache nginx answers with the page itself, and PHP does not run.
 */
final class PageCache
{
    /** How long a page is kept: quick choices of the form (any value the rule accepts can be typed). */
    public const TTL_CHOICES = ['1m' => '1 minute', '5m' => '5 minutes', '10m' => '10 minutes', '30m' => '30 minutes', '1h' => '1 hour', '6h' => '6 hours', '1d' => '1 day', '7d' => '7 days'];

    public const SIZE_CHOICES = ['128m' => '128 MB', '256m' => '256 MB', '512m' => '512 MB', '1g' => '1 GB', '2g' => '2 GB', '5g' => '5 GB'];

    /** A number and s, m, h or d — one second to thirty days. */
    public static function ttlSeconds(string $ttl): ?int
    {
        if (preg_match('/^([1-9][0-9]{0,6})(s|m|h|d)$/', $ttl, $m) !== 1) {
            return null;
        }
        $seconds = (int) $m[1] * ['s' => 1, 'm' => 60, 'h' => 3600, 'd' => 86400][$m[2]];

        return $seconds >= 1 && $seconds <= 2592000 ? $seconds : null;
    }

    public static function ttlValid(string $ttl): bool
    {
        return self::ttlSeconds($ttl) !== null;
    }

    /** Megabytes or gigabytes, 16m to 100g. */
    public static function sizeValid(string $size): bool
    {
        if (preg_match('/^([1-9][0-9]{0,5})(m|g)$/', $size, $m) !== 1) {
            return false;
        }
        $mb = (int) $m[1] * ($m[2] === 'g' ? 1024 : 1);

        return $mb >= 16 && $mb <= 102400;
    }

    /** "10m" → "10 minutes", "1h" → "1 hour". */
    public static function ttlLabel(string $ttl): string
    {
        if (preg_match('/^([1-9][0-9]{0,6})(s|m|h|d)$/', $ttl, $m) !== 1) {
            return $ttl;
        }
        $unit = ['s' => 'second', 'm' => 'minute', 'h' => 'hour', 'd' => 'day'][$m[2]];

        return $m[1].' '.$unit.((int) $m[1] === 1 ? '' : 's');
    }

    /** "256m" → "256 MB", "2g" → "2 GB". */
    public static function sizeLabel(string $size): string
    {
        return preg_match('/^([1-9][0-9]{0,5})(m|g)$/', $size, $m) === 1 ? $m[1].' '.($m[2] === 'g' ? 'GB' : 'MB') : $size;
    }

    public static function bytes(int|float $bytes): string
    {
        return match (true) {
            $bytes >= 1073741824 => number_format($bytes / 1073741824, 1).' GB',
            $bytes >= 1048576 => number_format($bytes / 1048576, 1).' MB',
            $bytes >= 1024 => number_format($bytes / 1024).' KB',
            default => ((int) $bytes).' B',
        };
    }

    /**
     * What a custom deploy recipe or a script runs, as the app's user, to
     * empty the cache: the server's own words when it gives them.
     *
     * @param  array<string, mixed>|null  $cache
     */
    public static function clearCommand(?array $cache, string $app): string
    {
        $command = $cache['clear_command'] ?? null;

        return is_string($command) && preg_match('#^sudo /usr/local/bin/cipi-cache-clear [a-z][a-z0-9]{2,31}$#', $command) === 1
            ? $command
            : 'sudo /usr/local/bin/cipi-cache-clear '.$app;
    }
}
