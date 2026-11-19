<?php

namespace CipiGui\Support;

/**
 * The answer of `GET /api/php`, read for the forms: `versions` is what the
 * server has installed, `installable` what Cipi installs and gives to an app
 * today (8.3 and later since Cipi 4.5.4, when Deployer 8 came in).
 *
 * A server installed before that can still have 7.4–8.2, with apps on them.
 * Those are legacy: listed, kept by the apps that run on them, and not offered
 * to any other — the API refuses them. Legacy is "installed, older than
 * everything installable"; the panel keeps no list of versions of its own. An
 * API that sends no `installable` list (before 1.15) marks nothing as legacy.
 */
final class PhpVersions
{
    /**
     * @param  array<string, mixed>  $data
     * @return list<string>
     */
    public static function installed(array $data): array
    {
        $versions = [];
        foreach ((array) ($data['versions'] ?? []) as $row) {
            if (is_array($row) && ! empty($row['version']) && is_scalar($row['version'])) {
                $versions[] = (string) $row['version'];
            }
        }

        return $versions;
    }

    /**
     * Installed versions no app can be put on any more.
     *
     * @param  array<string, mixed>  $data
     * @return list<string>
     */
    public static function legacy(array $data): array
    {
        $installable = array_map('strval', array_filter((array) ($data['installable'] ?? []), 'is_scalar'));
        if ($installable === []) {
            return [];
        }
        usort($installable, 'version_compare');
        $oldest = $installable[0];

        return array_values(array_filter(
            self::installed($data),
            fn (string $version) => ! in_array($version, $installable, true) && version_compare($version, $oldest, '<'),
        ));
    }

    /**
     * Installed versions an app can be created on or moved to.
     *
     * @param  array<string, mixed>  $data
     * @return list<string>
     */
    public static function assignable(array $data): array
    {
        return array_values(array_diff(self::installed($data), self::legacy($data)));
    }
}
