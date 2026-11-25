<?php

namespace CipiGui\Support;

/**
 * A block of `KEY=value` lines — pasted from a README, a chat, another .env —
 * read into variables, and the list of variables written back as text.
 *
 * Read the way Laravel's dotenv reads a file: blank lines and comments are
 * skipped, `export` is dropped, a value in double quotes takes `\"` and `\\`,
 * one in single quotes is taken as it is, and a bare value ends at ` #`.
 * Written the way `cipi app env --set` writes it, so text made by dump() and
 * read by parse() gives back the same values.
 *
 * One line per variable: a value that runs over several lines is refused,
 * because the server's .env editor works on lines too.
 */
class EnvText
{
    public const MAX_BYTES = 65536;

    public const MAX_VARS = 500;

    /** What the API accepts as a key (CipiAppEnvService::assertEnvKey). */
    public const KEY_PATTERN = '/^[A-Za-z_][A-Za-z0-9_]*$/';

    /**
     * @return array{vars: array<string, string>, errors: list<string>, duplicates: list<string>}
     */
    public static function parse(string $text): array
    {
        $vars = [];
        $errors = [];
        $duplicates = [];

        if (strlen($text) > self::MAX_BYTES) {
            return ['vars' => [], 'errors' => ['The text is too long (64 KB at most).'], 'duplicates' => []];
        }

        if (str_starts_with($text, "\xEF\xBB\xBF")) {
            $text = substr($text, 3); // a byte order mark, from a file saved on Windows
        }

        foreach (preg_split('/\r\n|\n|\r/', $text) ?: [] as $index => $line) {
            $n = $index + 1;
            $line = trim($line, " \t\0\x0B");
            if ($line === '' || $line[0] === '#') {
                continue;
            }
            // The fence of a code block copied with its content.
            if (str_starts_with($line, '```')) {
                continue;
            }
            $line = (string) preg_replace('/^export\s+/', '', $line);

            $eq = strpos($line, '=');
            if ($eq === false) {
                $errors[] = "Line {$n}: expected KEY=value.";

                continue;
            }
            $key = trim(substr($line, 0, $eq));
            if (! preg_match(self::KEY_PATTERN, $key)) {
                $errors[] = "Line {$n}: “".self::short($key).'” is not a key — letters, digits and _ only, not starting with a digit.';

                continue;
            }

            $value = self::value(ltrim(substr($line, $eq + 1), " \t"), $n, $errors);
            if ($value === null) {
                continue;
            }
            if (preg_match('/[\x00-\x08\x0A-\x1F\x7F]/', $value)) {
                $errors[] = "Line {$n}: {$key} holds a control character.";

                continue;
            }

            if (array_key_exists($key, $vars)) {
                $duplicates[] = $key;
                unset($vars[$key]); // the last one wins, in the place of the last one
            }
            $vars[$key] = $value;
        }

        if (count($vars) > self::MAX_VARS) {
            $errors[] = 'Too many variables ('.self::MAX_VARS.' at most).';
        }

        return ['vars' => $vars, 'errors' => $errors, 'duplicates' => array_values(array_unique($duplicates))];
    }

    /** @param  list<string>  $errors */
    private static function value(string $raw, int $n, array &$errors): ?string
    {
        if ($raw === '') {
            return '';
        }

        if ($raw[0] === '"') {
            $out = '';
            $len = strlen($raw);
            for ($i = 1; $i < $len; $i++) {
                $c = $raw[$i];
                if ($c === '\\' && $i + 1 < $len && ($raw[$i + 1] === '"' || $raw[$i + 1] === '\\')) {
                    $out .= $raw[++$i];

                    continue;
                }
                if ($c === '"') {
                    return self::tail(substr($raw, $i + 1), $n, $errors) ? $out : null;
                }
                $out .= $c;
            }
            $errors[] = "Line {$n}: the double quote is never closed — a value has to stay on one line.";

            return null;
        }

        if ($raw[0] === "'") {
            $end = strpos($raw, "'", 1);
            if ($end === false) {
                $errors[] = "Line {$n}: the single quote is never closed — a value has to stay on one line.";

                return null;
            }

            return self::tail(substr($raw, $end + 1), $n, $errors) ? substr($raw, 1, $end - 1) : null;
        }

        // A bare value ends where a comment starts: whitespace, then #.
        $raw = (string) preg_replace('/[ \t]+#.*$/', '', $raw);

        return rtrim($raw, " \t");
    }

    /**
     * After a closing quote: nothing, or a comment.
     *
     * @param  list<string>  $errors
     */
    private static function tail(string $rest, int $n, array &$errors): bool
    {
        $rest = trim($rest, " \t");
        if ($rest === '' || $rest[0] === '#') {
            return true;
        }
        $errors[] = "Line {$n}: text after the closing quote (“".self::short($rest).'”).';

        return false;
    }

    /**
     * @param  iterable<array{key: string, value: string}>  $rows
     */
    public static function dump(iterable $rows): string
    {
        $lines = [];
        foreach ($rows as $row) {
            $key = trim((string) ($row['key'] ?? ''));
            if ($key !== '') {
                $lines[] = $key.'='.self::quote((string) ($row['value'] ?? ''));
            }
        }

        return implode("\n", $lines);
    }

    /** `_env_escape_value` of the CLI: quoted when a space, a quote, #, $, `, \ or = is in it. */
    public static function quote(string $value): string
    {
        if ($value !== '' && ! preg_match('/[ \t#"\'\\\\$`=]/', $value)) {
            return $value;
        }
        if ($value === '') {
            return '';
        }

        return '"'.str_replace(['\\', '"'], ['\\\\', '\\"'], $value).'"';
    }

    /**
     * What applying the variables to the rows changes: how many keys are new,
     * how many get another value, how many go away (only when the text
     * replaces the list).
     *
     * @param  list<array{key: string, value: string}>  $rows
     * @param  array<string, string>  $vars
     * @return array{rows: list<array{key: string, value: string}>, added: int, changed: int, removed: int}
     */
    public static function apply(array $rows, array $vars, bool $replace): array
    {
        $current = [];
        foreach ($rows as $row) {
            $key = (string) ($row['key'] ?? '');
            if ($key !== '') {
                $current[$key] = (string) ($row['value'] ?? '');
            }
        }

        $added = count(array_diff_key($vars, $current));
        $changed = 0;
        foreach ($vars as $key => $value) {
            if (array_key_exists($key, $current) && $current[$key] !== $value) {
                $changed++;
            }
        }

        if ($replace) {
            $removed = count(array_diff_key($current, $vars));
            $next = $vars;
        } else {
            $removed = 0;
            $next = array_replace($current, $vars); // known keys keep their place, new ones go to the end
        }

        $out = [];
        foreach ($next as $key => $value) {
            $out[] = ['key' => (string) $key, 'value' => (string) $value];
        }

        return ['rows' => $out, 'added' => $added, 'changed' => $changed, 'removed' => $removed];
    }

    private static function short(string $text): string
    {
        $text = (string) preg_replace('/[^\x20-\x7E]/', '?', $text);

        return strlen($text) > 40 ? substr($text, 0, 37).'…' : $text;
    }
}
