<?php

namespace CipiGui\Support;

class Theme
{
    public const VERSION = '2.1.0';

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
