<?php

namespace CipiGui\Support;

use CipiGui\Models\CipiSetting;

/**
 * Panel-wide settings, decided by admins on Settings → Panel and stored in
 * cipi_settings. Read once per request; defaults apply until the table exists.
 */
final class Panel
{
    public const FLEET_VIEW = 'fleet_view';

    public const SERVER_NAV = 'server_nav';

    /** @var array<string, ?string>|null */
    private static ?array $values = null;

    /** Apps and databases of every server in one list, no server switcher in the header. */
    public static function fleetView(): bool
    {
        return self::get(self::FLEET_VIEW, '1') === '1';
    }

    /** How a server is picked in fleet view: `sidebar` (one entry per server) or `picker` (a select on the Server page). */
    public static function serverNav(): string
    {
        return self::get(self::SERVER_NAV, 'sidebar') === 'picker' ? 'picker' : 'sidebar';
    }

    public static function get(string $key, ?string $default = null): ?string
    {
        if (self::$values === null) {
            try {
                self::$values = CipiSetting::query()->pluck('value', 'key')->all();
            } catch (\Throwable) {
                self::$values = []; // migrations not run yet
            }
        }

        return self::$values[$key] ?? $default;
    }

    public static function set(string $key, ?string $value): void
    {
        CipiSetting::query()->updateOrCreate(['key' => $key], ['value' => $value]);
        self::$values = null;
    }

    public static function flush(): void
    {
        self::$values = null;
    }
}
