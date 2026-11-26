<?php

namespace CipiGui\Support;

class Theme
{
    public const VERSION = '4.9.0';

    /**
     * The cipi/api release this panel is written against (minor). What a
     * server actually runs is its own to say: GET /api/status → system.api.
     */
    public const API = '1.47';

    /** The panel's name where a browser or an authenticator shows one: not the host app's APP_NAME ("Laravel" on a fresh install). */
    public const NAME = 'Cipi';

    public static function packageRoot(): string
    {
        return dirname(__DIR__, 2);
    }

    public static function cssPath(): string
    {
        return self::packageRoot().'/resources/css/cipi-gui.css';
    }

    public static function faviconPath(): string
    {
        return self::packageRoot().'/resources/assets/favicon.svg';
    }

    public static function css(): string
    {
        $path = self::cssPath();

        if (! is_readable($path)) {
            return '/* cipi-gui: stylesheet not found at '.$path.' */';
        }

        return (string) file_get_contents($path);
    }

    public static function fingerprint(): string
    {
        $path = self::cssPath();

        return is_readable($path) ? (string) md5_file($path) : 'missing';
    }
}
