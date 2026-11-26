<?php

namespace CipiGui\Support;

/**
 * "Save to…" next to credentials the panel shows once: the same logins as a
 * file each password manager imports — 1Password, Bitwarden, Proton Pass,
 * Apple Passwords, Google Password Manager (Chrome, Edge, Brave), KeePassXC,
 * LastPass, and the plain CSV the others read.
 *
 * A password manager has no door a web page can push an item through — only
 * 1Password publishes a "Save in 1Password" button, and it needs 1Password's
 * own script in the page that shows the passwords. So the panel does what
 * works for all of them and involves nobody else: it writes the import file.
 * It is built in the browser's memory and downloaded from there — nothing is
 * stored on the panel, nothing is sent anywhere — and it holds the passwords
 * in clear: to be deleted once imported.
 *
 * Every manager wants a web address for a login, and the browsers' ones
 * refuse a row without it: the app's own site is given, and what the
 * password really opens (SSH on which host, which database) is in the notes.
 */
final class PasswordManagers
{
    /**
     * id => [label, the first line of its import file, how to import it].
     * The columns are each manager's own: its importer matches them by name.
     */
    public const PROVIDERS = [
        '1password' => ['1Password', 'Title,Website,Username,Password,Notes', 'In 1Password: Import → CSV file.'],
        'bitwarden' => ['Bitwarden', 'folder,favorite,type,name,notes,fields,reprompt,login_uri,login_username,login_password,login_totp', 'In Bitwarden: Tools → Import data, file format “Bitwarden (csv)”.'],
        'proton' => ['Proton Pass', 'name,url,email,username,password,note,totp,vault', 'In Proton Pass: Settings → Import → Generic CSV.'],
        'apple' => ['Apple Passwords', 'Title,URL,Username,Password,Notes,OTPAuth', 'In Passwords on a Mac: File → Import Passwords.'],
        'chrome' => ['Google Password Manager', 'name,url,username,password,note', 'In Chrome, Edge or Brave: Password Manager → Settings → Import passwords.'],
        'keepass' => ['KeePassXC', 'Group,Title,Username,Password,URL,Notes', 'In KeePassXC: Database → Import → CSV file (the first line holds the field names).'],
        'lastpass' => ['LastPass', 'url,username,password,totp,extra,name,grouping,fav', 'In LastPass: Advanced Options → Import → Generic CSV file.'],
        'csv' => ['Another one (CSV)', 'title,url,username,password,notes', 'A plain CSV — title, url, username, password, notes — that most password managers import.'],
    ];

    /** The group, folder or vault the logins are filed under, where a manager has one. */
    public const FOLDER = 'Cipi';

    /**
     * One login, cleaned: everything a string, nothing a line break in a
     * field that is one line. Null without a password — there is nothing to save.
     *
     * @param  array<string, mixed>  $login
     * @return array{title: string, url: string, username: string, password: string, notes: string}|null
     */
    public static function login(array $login): ?array
    {
        $line = fn (mixed $v): string => is_scalar($v) ? trim((string) preg_replace('/[\x00-\x1F\x7F]+/', ' ', (string) $v)) : '';
        $password = is_scalar($login['password'] ?? null) ? (string) $login['password'] : '';
        if ($password === '' || preg_match('/[\x00-\x08\x0A-\x1F\x7F]/', $password) === 1) {
            return null;
        }
        $url = $line($login['url'] ?? '');
        if ($url !== '' && preg_match('#^https?://[^\s<>"\']+$#i', $url) !== 1) {
            $url = '';
        }
        $notes = is_scalar($login['notes'] ?? null) ? trim((string) preg_replace('/[\x00-\x08\x0B-\x1F\x7F]+/', ' ', (string) $login['notes'])) : '';

        return [
            'title' => $line($login['title'] ?? '') ?: 'Cipi',
            'url' => $url,
            'username' => $line($login['username'] ?? ''),
            'password' => $password,
            'notes' => $notes,
        ];
    }

    /**
     * The logins of a finished job's result (app create, database create or
     * new password): what the overlay shows as rows, as items to save.
     *
     * @param  array<string, mixed>  $result  the job's parsed result
     * @param  array{server?: ?string, host?: ?string, ip?: ?string, url?: ?string}  $context  where it happened
     * @return list<array{title: string, url: string, username: string, password: string, notes: string}>
     */
    public static function fromJobResult(array $result, array $context = []): array
    {
        $text = fn (mixed $v): string => is_scalar($v) ? trim((string) $v) : '';
        $server = $text($context['server'] ?? '');
        $address = $text($context['ip'] ?? '') ?: $text($context['host'] ?? '');
        $app = $text($result['app'] ?? '');
        $domain = $text($result['domain'] ?? '');
        $url = $domain !== '' && ! str_contains($domain, '*') ? 'https://'.$domain : $text($context['url'] ?? '');
        $where = $server !== '' ? " on {$server}" : '';
        $saved = 'Saved from the Cipi panel.';
        $logins = [];

        $ssh = is_array($result['ssh'] ?? null) ? $result['ssh'] : [];
        if ($text($ssh['password'] ?? '') !== '') {
            $user = $text($ssh['user'] ?? '') ?: $app;
            $logins[] = [
                'title' => ($app ?: $user).' — SSH / SFTP'.$where,
                'url' => $url,
                'username' => $user,
                'password' => (string) $ssh['password'],
                'notes' => trim('SSH and SFTP'.($address !== '' ? ": ssh {$user}@{$address} (port 22)" : '').". {$saved}"),
            ];
        }

        $database = is_array($result['database'] ?? null) ? $result['database'] : [];
        if ($text($database['password'] ?? '') !== '') {
            $user = $text($database['user'] ?? '') ?: $app;
            $logins[] = [
                'title' => ($app ?: $user).' — database'.$where,
                'url' => $url,
                'username' => $user,
                'password' => (string) $database['password'],
                'notes' => trim('Database '.($text($database['name'] ?? '') ?: $user).', on 127.0.0.1 of the server'.($address !== '' ? " ({$address})" : '').". {$saved}"),
            ];
        }

        // db create, db password: the database is the whole result.
        if (! isset($result['ssh']) && $text($result['password'] ?? '') !== '') {
            $name = is_string($result['database'] ?? null) ? $text($result['database']) : '';
            $user = $text($result['user'] ?? '') ?: $name;
            $engine = $text($result['engine'] ?? '');
            $logins[] = [
                'title' => ($name ?: $user ?: 'Database').' — database'.$where,
                'url' => $url,
                'username' => $user,
                'password' => (string) $result['password'],
                'notes' => trim('Database '.($name ?: $user).($engine !== '' ? " ({$engine})" : '').', on 127.0.0.1 of the server'.($address !== '' ? " ({$address})" : '').". {$saved}"),
            ];
        }

        return array_values(array_filter(array_map([self::class, 'login'], $logins)));
    }

    /**
     * The import file of one manager for these logins.
     *
     * @param  list<array<string, mixed>>  $logins
     */
    public static function file(string $provider, array $logins): string
    {
        $provider = isset(self::PROVIDERS[$provider]) ? $provider : 'csv';
        $rows = [self::PROVIDERS[$provider][1]];
        foreach ($logins as $login) {
            $l = self::login($login);
            if ($l === null) {
                continue;
            }
            $rows[] = self::csvLine(match ($provider) {
                '1password' => [$l['title'], $l['url'], $l['username'], $l['password'], $l['notes']],
                'bitwarden' => [self::FOLDER, '', 'login', $l['title'], $l['notes'], '', '', $l['url'], $l['username'], $l['password'], ''],
                'proton' => [$l['title'], $l['url'], '', $l['username'], $l['password'], $l['notes'], '', ''],
                'apple' => [$l['title'], $l['url'], $l['username'], $l['password'], $l['notes'], ''],
                'chrome' => [$l['title'], $l['url'], $l['username'], $l['password'], $l['notes']],
                'keepass' => [self::FOLDER, $l['title'], $l['username'], $l['password'], $l['url'], $l['notes']],
                'lastpass' => [$l['url'], $l['username'], $l['password'], '', $l['notes'], $l['title'], self::FOLDER, '0'],
                default => [$l['title'], $l['url'], $l['username'], $l['password'], $l['notes']],
            });
        }

        return implode("\r\n", $rows)."\r\n";
    }

    /**
     * Every manager with its file, for the menu: built once, with the page.
     *
     * @param  list<array<string, mixed>>  $logins
     * @return list<array{id: string, label: string, hint: string, filename: string, content: string}>
     */
    public static function menu(array $logins, string $name = 'cipi'): array
    {
        $slug = trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($name)), '-') ?: 'cipi';
        $menu = [];
        foreach (self::PROVIDERS as $id => [$label, , $hint]) {
            $menu[] = ['id' => $id, 'label' => $label, 'hint' => $hint, 'filename' => "{$slug}-{$id}.csv", 'content' => self::file($id, $logins)];
        }

        return $menu;
    }

    /** The logins as text, for a note or a manager that takes a paste. */
    public static function text(array $logins): string
    {
        $blocks = [];
        foreach ($logins as $login) {
            $l = self::login($login);
            if ($l === null) {
                continue;
            }
            $blocks[] = implode("\n", array_filter([
                $l['title'],
                $l['url'] !== '' ? 'URL: '.$l['url'] : null,
                $l['username'] !== '' ? 'Username: '.$l['username'] : null,
                'Password: '.$l['password'],
                $l['notes'] !== '' ? $l['notes'] : null,
            ]));
        }

        return implode("\n\n", $blocks);
    }

    /**
     * One CSV line, RFC 4180: a field with a comma, a quote, a line break or
     * a space at an end is quoted, quotes doubled.
     *
     * @param  list<string>  $fields
     */
    public static function csvLine(array $fields): string
    {
        return implode(',', array_map(
            fn (string $f) => $f !== '' && (strpbrk($f, ",\"\r\n") !== false || $f !== trim($f)) ? '"'.str_replace('"', '""', $f).'"' : $f,
            $fields,
        ));
    }
}
