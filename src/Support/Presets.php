<?php

namespace CipiGui\Support;

/**
 * The one-click commands of an app's Artisan and Commands tabs. They ship
 * with a default list and an admin of the panel can change it — add the
 * commands the team runs, remove the ones it never does, the defaults
 * included — from the tab itself. One list for the whole panel, stored in
 * cipi_settings; until somebody edits it, the defaults apply.
 *
 * A preset only fills the input: what runs is still the command the user
 * sends, checked by the server like any other.
 */
final class Presets
{
    public const ARTISAN = 'artisan';

    public const RUN = 'run';

    public const DEFAULTS = [
        self::ARTISAN => [
            'about', 'migrate:status', 'migrate --force', 'optimize', 'optimize:clear', 'cache:clear',
            'config:cache', 'queue:restart', 'schedule:list', 'storage:link',
        ],
        self::RUN => [
            'composer install --no-dev --no-interaction', 'composer dump-autoload --optimize',
            'npm ci', 'npm run build', 'git status', 'du -sh current shared logs',
        ],
    ];

    public const MAX = 40;

    public const MAX_LENGTH = 200;

    public static function isKind(string $kind): bool
    {
        return isset(self::DEFAULTS[$kind]);
    }

    /** @return list<string> the saved list, or the defaults when none was saved */
    public static function get(string $kind): array
    {
        if (! self::isKind($kind)) {
            return [];
        }

        $stored = Panel::get(self::key($kind));
        if ($stored === null) {
            return self::DEFAULTS[$kind];
        }

        $list = json_decode($stored, true);
        if (! is_array($list)) {
            return self::DEFAULTS[$kind];
        }

        return self::clean($list);
    }

    /** True once the list was edited (an empty saved list counts). */
    public static function isCustom(string $kind): bool
    {
        return self::isKind($kind) && Panel::get(self::key($kind)) !== null;
    }

    /**
     * One command as it is stored: a single line, spaces collapsed, and for
     * Artisan without a leading "php artisan" (the input adds it).
     */
    public static function normalize(string $kind, string $command): string
    {
        $command = trim((string) preg_replace('/\s+/', ' ', $command));
        if ($kind === self::ARTISAN) {
            $command = trim((string) preg_replace('/^(php\s+)?artisan\s+/i', '', $command));
        }

        return $command;
    }

    /** Why a command cannot be saved, or null. */
    public static function error(string $kind, string $command): ?string
    {
        $command = self::normalize($kind, $command);

        return match (true) {
            ! self::isKind($kind) => 'Unknown list.',
            $command === '' => 'Enter a command to save.',
            mb_strlen($command) > self::MAX_LENGTH => 'A saved command can be at most '.self::MAX_LENGTH.' characters.',
            (bool) preg_match('/[\x00-\x1f\x7f]/', $command) => 'A saved command is a single line of text.',
            in_array($command, self::get($kind), true) => 'That command is already in the list.',
            count(self::get($kind)) >= self::MAX => 'The list is full ('.self::MAX.' commands): remove one first.',
            default => null,
        };
    }

    public static function add(string $kind, string $command): void
    {
        if (self::error($kind, $command) !== null) {
            return;
        }
        self::save($kind, [...self::get($kind), self::normalize($kind, $command)]);
    }

    public static function remove(string $kind, string $command): void
    {
        if (! self::isKind($kind)) {
            return;
        }
        self::save($kind, array_values(array_filter(self::get($kind), fn (string $c) => $c !== $command)));
    }

    /** Back to the list the panel ships with. */
    public static function reset(string $kind): void
    {
        if (self::isKind($kind)) {
            \CipiGui\Models\CipiSetting::query()->whereKey(self::key($kind))->delete();
            Panel::flush();
        }
    }

    /** @param  list<string>  $list */
    private static function save(string $kind, array $list): void
    {
        Panel::set(self::key($kind), json_encode(self::clean($list), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    /**
     * @param  array<mixed>  $list
     * @return list<string>
     */
    private static function clean(array $list): array
    {
        $out = [];
        foreach ($list as $item) {
            if (! is_string($item)) {
                continue;
            }
            $item = trim($item);
            if ($item !== '' && mb_strlen($item) <= self::MAX_LENGTH && ! preg_match('/[\x00-\x1f\x7f]/', $item) && ! in_array($item, $out, true)) {
                $out[] = $item;
            }
        }

        return array_slice($out, 0, self::MAX);
    }

    private static function key(string $kind): string
    {
        return $kind.'_presets';
    }
}
