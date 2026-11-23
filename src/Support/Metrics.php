<?php

namespace CipiGui\Support;

/**
 * Pure helpers of the Metrics tab (API 1.41+ / Cipi ≥ 5.11.0): the ranges the
 * tab offers, how often it polls, the shape of a chart for the browser, the
 * sparklines and uptime bars drawn on the server, and how a number is shown.
 * No framework: tests/standalone.php checks them without vendor/.
 */
final class Metrics
{
    /** range => label. The core keeps raw minutes 7 days and rollups 30 days by default. */
    public const RANGES = ['1h' => '1h', '6h' => '6h', '24h' => '24h', '7d' => '7d', '30d' => '30d'];

    public const DEFAULT_RANGE = '24h';

    /** Charts of the server: metric => [title, unit, colour token]. */
    public const HOST_CHARTS = [
        'cpu' => ['CPU', '%', 'accent'],
        'mem' => ['Memory', '%', 'info'],
        'load1' => ['Load (1 min)', '', 'env'],
        'net' => ['Network', 'bps', 'info'],
    ];

    /** The collector writes a new minute every 60 s: polling faster shows nothing new. */
    public static function pollSeconds(string $range): int
    {
        return in_array($range, ['1h', '6h', '24h'], true) ? 30 : 120;
    }

    public static function validRange(?string $range): string
    {
        return is_string($range) && array_key_exists($range, self::RANGES) ? $range : self::DEFAULT_RANGE;
    }

    public static function rangeSeconds(string $range): int
    {
        $n = (int) $range;

        return match (substr($range, -1)) {
            'm' => $n * 60,
            'h' => $n * 3600,
            'd' => $n * 86400,
            default => 86400,
        };
    }

    /**
     * A chart for the browser (partials/chart-script): series of [t, avg, max]
     * points, deploy and incident markers, an optional threshold line.
     *
     * @param  array<string, mixed>  $data  the API's series answer
     * @param  list<array{metric: string, name: string, color: string, kind?: string, band?: bool}>  $lines
     * @param  list<array<string, mixed>>  $markers
     * @return array<string, mixed>
     */
    public static function chart(array $data, array $lines, string $unit, array $markers = [], ?array $threshold = null, ?float $yMax = null): array
    {
        $series = [];
        foreach ($lines as $line) {
            $points = [];
            foreach ((array) ($data['series'][$line['metric']] ?? []) as $p) {
                if (is_array($p) && isset($p[0], $p[1]) && is_numeric($p[0]) && is_numeric($p[1])) {
                    $points[] = [(int) $p[0], (float) $p[1], isset($p[2]) && is_numeric($p[2]) ? (float) $p[2] : (float) $p[1]];
                }
            }
            $series[] = [
                'name' => $line['name'],
                'color' => $line['color'],
                'kind' => $line['kind'] ?? 'area',
                'band' => (bool) ($line['band'] ?? false),
                'points' => $points,
            ];
        }

        return array_filter([
            'unit' => $unit,
            'step' => (int) ($data['step'] ?? 60),
            'from' => isset($data['from']) ? (int) $data['from'] : null,
            'to' => isset($data['to']) ? (int) $data['to'] : null,
            'series' => $series,
            'markers' => array_values($markers),
            'threshold' => $threshold,
            'yMax' => $yMax,
        ], fn ($v) => $v !== null);
    }

    /** True when no series of a chart has a point. */
    public static function chartEmpty(array $chart): bool
    {
        foreach ((array) ($chart['series'] ?? []) as $s) {
            if (! empty($s['points'])) {
                return false;
            }
        }

        return true;
    }

    /**
     * Events as chart markers within [from, to]: deploys in blue, an incident
     * opening in red, a recovery in green, a warning in amber.
     *
     * @param  list<array<string, mixed>>  $events
     * @return list<array{t: int, kind: string, label: string}>
     */
    public static function markers(array $events, ?int $from, ?int $to, ?string $subject = null): array
    {
        $out = [];
        foreach ($events as $e) {
            $t = (int) ($e['ts'] ?? 0);
            if ($t <= 0 || ($from !== null && $t < $from) || ($to !== null && $t > $to)) {
                continue;
            }
            if ($subject !== null && ($e['subject'] ?? null) !== $subject) {
                continue;
            }
            $source = (string) ($e['source'] ?? '');
            $status = (string) ($e['status'] ?? '');
            $kind = match (true) {
                $source === 'deploy' && $status !== 'crit' => 'deploy',
                $status === 'crit' => 'crit',
                $status === 'warn' => 'warn',
                default => 'ok',
            };
            $out[] = ['t' => $t, 'kind' => $kind, 'label' => trim(($source === 'deploy' ? 'Deploy' : ucfirst($source).' '.($e['subject'] ?? '')).' — '.($e['summary'] ?? ''), ' —')];
        }

        return array_slice($out, -60);
    }

    /**
     * An SVG path for a sparkline: the line, and the same closed down to the
     * baseline for its fill. Gaps (null) are skipped.
     *
     * @param  list<float|int|null>  $values
     * @return array{line: string, area: string}|null
     */
    public static function spark(array $values, int $w = 96, int $h = 24): ?array
    {
        $values = array_values(array_filter($values, fn ($v) => is_numeric($v)));
        $n = count($values);
        if ($n < 2) {
            return null;
        }
        $min = min($values);
        $max = max($values);
        $span = $max - $min ?: 1;
        $pad = 2;
        $pts = [];
        foreach ($values as $i => $v) {
            $x = round($i / ($n - 1) * $w, 1);
            $y = round($h - $pad - (($v - $min) / $span) * ($h - 2 * $pad), 1);
            $pts[] = $x.','.$y;
        }
        $line = 'M'.implode(' L', $pts);

        return ['line' => $line, 'area' => $line.' L'.$w.','.$h.' L0,'.$h.' Z'];
    }

    /** Colour of an uptime figure: the thresholds a status page uses. */
    public static function uptimeClass(mixed $pct): string
    {
        if (! is_numeric($pct)) {
            return 'is-none';
        }

        return match (true) {
            $pct >= 99.9 => 'is-ok',
            $pct >= 99 => 'is-warn',
            default => 'is-bad',
        };
    }

    /**
     * One bar per UTC day for the last $days days, oldest first; a day without
     * probes has pct null.
     *
     * @param  list<array{0: int, 1: float|null}>  $days
     * @return list<array{ts: int, pct: float|null}>
     */
    public static function uptimeDays(array $days, int $count, ?int $now = null): array
    {
        $byDay = [];
        foreach ($days as $d) {
            if (is_array($d) && isset($d[0]) && is_numeric($d[0])) {
                $byDay[intdiv((int) $d[0], 86400)] = isset($d[1]) && is_numeric($d[1]) ? (float) $d[1] : null;
            }
        }
        $today = intdiv($now ?? time(), 86400);
        $out = [];
        for ($i = $count - 1; $i >= 0; $i--) {
            $day = $today - $i;
            $out[] = ['ts' => $day * 86400, 'pct' => $byDay[$day] ?? null];
        }

        return $out;
    }

    /** A value with its unit, short: 12.3%, 840 ms, 1.4 s, 312 MB, 1.2 GB, 3.4 MB/s, 1.2k. */
    public static function format(mixed $value, string $unit = '', int $decimals = 1): string
    {
        if (! is_numeric($value)) {
            return '—';
        }
        $v = (float) $value;

        return match ($unit) {
            '%' => self::num($v, $decimals).'%',
            'ms' => $v >= 1000 ? self::num($v / 1000, 2).' s' : self::num($v, 0).' ms',
            'mb' => $v >= 1024 ? self::num($v / 1024, 1).' GB' : self::num($v, 0).' MB',
            'bps' => self::bytes($v).'/s',
            'count' => $v >= 10000 ? self::num($v / 1000, 1).'k' : self::num($v, $v < 10 && $v != (int) $v ? 1 : 0),
            default => self::num($v, $decimals),
        };
    }

    public static function bytes(float $v): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $i = 0;
        while ($v >= 1024 && $i < count($units) - 1) {
            $v /= 1024;
            $i++;
        }

        return self::num($v, $i === 0 ? 0 : 1).' '.$units[$i];
    }

    private static function num(float $v, int $decimals): string
    {
        return number_format($v, $decimals, '.', ',');
    }

    /** Badge class of an event status. */
    public static function eventClass(string $status): string
    {
        return match ($status) {
            'ok' => 'is-ok',
            'warn' => 'is-warn',
            'crit' => 'is-bad',
            default => 'is-none',
        };
    }
}
