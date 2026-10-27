<?php

namespace CipiGui\Support;

/**
 * The environment label of an app — `env` on the API (cipi/api 1.36+): a short
 * lowercase word, `production` by default, that tells the environments of one
 * project apart (shop on fra1 is production, shopstg on staging is staging) and
 * makes deploys read `cipi-<env>.yml` before `cipi.yml`. Older servers do not
 * report it, which means production.
 */
final class AppEnv
{
    public const DEFAULT = 'production';

    /** The API's rule: a lowercase letter or digit, then up to 31 of `a-z 0-9 _ -`. */
    public const PATTERN = '/^[a-z0-9][a-z0-9_-]{0,31}$/';

    public const MAX_LENGTH = 32;

    /** Offered by the forms; anything matching PATTERN is accepted. */
    public const SUGGESTIONS = ['production', 'staging', 'development', 'testing'];

    /** Laravel validation rule, the same regex the API applies. */
    public static function rule(): string
    {
        return 'regex:'.self::PATTERN;
    }

    public static function message(): string
    {
        return 'Use up to 32 lowercase letters, digits, - or _, starting with a letter or digit — production, staging, development, testing or your own.';
    }

    public static function valid(string $env): bool
    {
        return preg_match(self::PATTERN, $env) === 1;
    }

    /** The label as the API reports it; absent or empty (older servers) means production. */
    public static function normalize(mixed $value): string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : self::DEFAULT;
    }

    public static function isProduction(string $env): bool
    {
        return $env === self::DEFAULT;
    }

    /** Badge class: production stays muted, every other environment stands out. */
    public static function badgeClass(string $env): string
    {
        return self::isProduction($env) ? 'badge-gray' : 'badge-env';
    }

    /**
     * The cipi.yml files a deploy of this environment looks for at the root of the
     * release, in order: the first one found is used.
     *
     * @return list<string>
     */
    public static function ymlFiles(string $env): array
    {
        return ['cipi-'.$env.'.yml', 'cipi.yml'];
    }
}
