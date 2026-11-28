<?php

namespace CipiGui\Support;

/**
 * An SFTP backup destination: the fields of its form, checked as the server
 * checks them, and how to install its key on the remote.
 */
final class BackupSftp
{
    public const NAME_RE = '/^[a-z][a-z0-9-]{1,31}$/';

    public const HOST_RE = '/^[A-Za-z0-9]([A-Za-z0-9.:-]{0,252}[A-Za-z0-9])?$/';

    public const USER_RE = '/^[A-Za-z0-9_][A-Za-z0-9._-]{0,63}$/';

    public const PATH_RE = '#^/?[A-Za-z0-9._/-]{0,255}$#';

    /**
     * @param  list<string>  $taken  names already used (a new destination only)
     * @return array<string, string>  sftpName|sftpHost|sftpPort|sftpUser|sftpPath => message
     */
    public static function errors(string $name, string $host, string $port, string $user, string $path, array $taken = []): array
    {
        $errors = [];
        if (! preg_match(self::NAME_RE, $name) || in_array($name, ['local', 's3'], true)) {
            $errors['sftpName'] = 'Lowercase letters, digits and dashes, 2 to 32 — not local or s3.';
        } elseif (in_array($name, $taken, true)) {
            $errors['sftpName'] = "There is a destination called {$name} already.";
        }
        if (! preg_match(self::HOST_RE, $host)) {
            $errors['sftpHost'] = 'An IP address or a hostname — u1234.your-storagebox.de, backup.example.com.';
        }
        if (! ctype_digit($port) || (int) $port < 1 || (int) $port > 65535) {
            $errors['sftpPort'] = 'A port from 1 to 65535 — 22, or 23 for a Storage Box.';
        }
        if (! preg_match(self::USER_RE, $user)) {
            $errors['sftpUser'] = 'The user on the remote: letters, digits, . _ -';
        }
        if (! preg_match(self::PATH_RE, $path) || str_contains($path, '..')) {
            $errors['sftpPath'] = 'A folder: letters, digits, . _ - / — empty for the login\'s home.';
        }

        return $errors;
    }

    public static function isStorageBox(?array $dest): bool
    {
        return str_ends_with((string) ($dest['host'] ?? ''), '.your-storagebox.de');
    }

    /**
     * The command that installs the key on a Storage Box from a computer that
     * has the key's public half in a file: ssh-copy-id over SFTP, with the box's password once.
     */
    public static function storageBoxCommand(array $dest): string
    {
        return 'ssh-copy-id -p '.((int) ($dest['port'] ?? 23)).' -s -f -i cipi-backup-'.($dest['name'] ?? 'box').'.pub '.($dest['user'] ?? 'u0000').'@'.($dest['host'] ?? 'u0000.your-storagebox.de');
    }

    /** The line to append on a server of yours, as the backup user. */
    public static function authorizedKeysCommand(string $key): string
    {
        return "mkdir -p ~/.ssh && chmod 700 ~/.ssh && echo '".str_replace("'", '', $key)."' >> ~/.ssh/authorized_keys && chmod 600 ~/.ssh/authorized_keys";
    }
}
