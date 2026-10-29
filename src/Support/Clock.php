<?php

namespace CipiGui\Support;

use Carbon\CarbonInterface;
use DateTimeZone;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;

/**
 * The times the panel shows, in the zone of the signed-in account (Settings →
 * Profile; UTC until one is chosen). Each server reports timestamps with its
 * own offset and the panel keeps UTC: converting on display is what makes the
 * deploys of two servers comparable, and what lets a team spread over time
 * zones read the same ledger. Relative times ("3 minutes ago") need nothing.
 */
final class Clock
{
    public const DEFAULT = 'UTC';

    /** The account's zone, UTC when none is set or the stored value is unknown. */
    public static function timezone(?object $user = null): string
    {
        $user ??= Auth::user();
        $tz = $user?->timezone ?? null;

        return is_string($tz) && self::isValid($tz) ? $tz : self::DEFAULT;
    }

    public static function isValid(?string $tz): bool
    {
        return is_string($tz) && $tz !== '' && in_array($tz, DateTimeZone::listIdentifiers(), true);
    }

    /** The value in the account's zone; null when it is not a time. */
    public static function local(CarbonInterface|string|int|null $value): ?Carbon
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            $time = match (true) {
                $value instanceof CarbonInterface => Carbon::instance($value),
                is_int($value) => Carbon::createFromTimestamp($value),
                default => Carbon::parse($value),
            };
        } catch (\Throwable) {
            return null;
        }

        return $time->setTimezone(self::timezone());
    }

    public static function format(CarbonInterface|string|int|null $value, string $format = 'Y-m-d H:i'): string
    {
        return self::local($value)?->format($format) ?? '';
    }

    /** "UTC", "CET", or "UTC+5:30" when the zone has no alphabetic abbreviation — next to a time. */
    public static function abbreviation(): string
    {
        $tz = self::timezone();
        if ($tz === self::DEFAULT) {
            return 'UTC';
        }

        $now = Carbon::now($tz);
        $abbr = $now->format('T');
        if (preg_match('/^[A-Z]{2,5}$/', $abbr)) {
            return $abbr;
        }

        return self::offsetLabel($now);
    }

    /** "UTC" or "Europe/Rome (CET, UTC+1)" — the hint above a table of absolute times. */
    public static function label(): string
    {
        $tz = self::timezone();
        if ($tz === self::DEFAULT) {
            return 'UTC';
        }

        $now = Carbon::now($tz);
        $abbr = self::abbreviation();
        $offset = self::offsetLabel($now);

        return $tz.' ('.($abbr !== $offset ? $abbr.', ' : '').$offset.')';
    }

    /** Identifiers grouped by region for a select — UTC first, then Africa … Pacific, the rest under Other. */
    public static function zones(): array
    {
        $groups = [];
        foreach (DateTimeZone::listIdentifiers() as $id) {
            if ($id === self::DEFAULT) {
                continue;
            }
            $region = str_contains($id, '/') ? explode('/', $id, 2)[0] : 'Other';
            $groups[$region][] = $id;
        }
        ksort($groups);

        return [self::DEFAULT => [self::DEFAULT]] + $groups;
    }

    private static function offsetLabel(Carbon $now): string
    {
        $minutes = $now->utcOffset();
        if ($minutes === 0) {
            return 'UTC';
        }
        $sign = $minutes < 0 ? '-' : '+';
        $h = intdiv(abs($minutes), 60);
        $m = abs($minutes) % 60;

        return 'UTC'.$sign.$h.($m ? ':'.sprintf('%02d', $m) : '');
    }
}
