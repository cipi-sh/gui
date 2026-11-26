<?php

namespace CipiGui\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;

class CipiServer extends Model
{
    protected $fillable = [
        'name',
        'url',
        'ip',
        'token',
        'cf_access_client_id',
        'cf_access_client_secret',
        'is_active',
        'last_connected_at',
        'last_error',
    ];

    protected $hidden = [
        'token',
        'cf_access_client_secret',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'last_connected_at' => 'datetime',
        ];
    }

    public function setTokenAttribute(string $value): void
    {
        $this->attributes['token'] = Crypt::encryptString($value);
    }

    public function getTokenAttribute(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        try {
            return Crypt::decryptString($value);
        } catch (\Throwable) {
            return null;
        }
    }

    /** The service token's secret (cipi zt service-token create … --for=api), encrypted like the API token. */
    public function setCfAccessClientSecretAttribute(?string $value): void
    {
        $this->attributes['cf_access_client_secret'] = $value === null || $value === '' ? null : Crypt::encryptString($value);
    }

    public function getCfAccessClientSecretAttribute(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            return Crypt::decryptString($value);
        } catch (\Throwable) {
            return null;
        }
    }

    /** @return array<string, string> the headers that open Cloudflare Access in front of the API, when it has them */
    public function cloudflareAccessHeaders(): array
    {
        $id = trim((string) $this->cf_access_client_id);
        $secret = (string) $this->cf_access_client_secret;

        return $id !== '' && $secret !== '' ? ['CF-Access-Client-Id' => $id, 'CF-Access-Client-Secret' => $secret] : [];
    }

    public function getBaseUrlAttribute(): string
    {
        return rtrim($this->url, '/');
    }

    public function getApiUrlAttribute(): string
    {
        return $this->base_url.'/api';
    }

    /** Hostname (plus non-default port) for compact display. */
    public function getHostAttribute(): string
    {
        $parts = parse_url($this->base_url);
        $host = $parts['host'] ?? $this->base_url;

        if (isset($parts['port'])) {
            $host .= ':'.$parts['port'];
        }

        if (isset($parts['path']) && $parts['path'] !== '' && $parts['path'] !== '/') {
            $host .= $parts['path'];
        }

        return $host;
    }

    /**
     * Keep the machine's own name (`hostname`) as the server's status reports
     * it: `vmi12334` for the server the panel calls `production`. A
     * notification names the server by it, so the lists show both. Written
     * only when it changed; a panel whose migrations have not run yet goes on
     * without it.
     *
     * @param  array<string, mixed>|null  $status  the data of GET /api/status
     */
    public function rememberHostname(?array $status): void
    {
        $hostname = self::cleanHostname($status['system']['hostname'] ?? null);
        if ($hostname === null || $hostname === $this->hostname) {
            return;
        }

        try {
            $this->forceFill(['hostname' => $hostname])->save();
        } catch (\Throwable) {
            // No `hostname` column yet (php artisan migrate): nothing to remember.
            unset($this->attributes['hostname']);
        }
    }

    /** A hostname as a machine reports it, or null: letters, digits, dots, dashes, underscores. */
    public static function cleanHostname(mixed $hostname): ?string
    {
        if (! is_string($hostname)) {
            return null;
        }
        $hostname = trim($hostname);

        return $hostname !== '' && strlen($hostname) <= 253 && preg_match('/^[A-Za-z0-9]([A-Za-z0-9._-]*[A-Za-z0-9])?$/', $hostname) === 1
            ? $hostname
            : null;
    }

    /** The hostname when it says something the panel's name does not: `vmi12334` for `production`, nothing for `vmi12334`. */
    public function getMachineNameAttribute(): ?string
    {
        $hostname = self::cleanHostname($this->attributes['hostname'] ?? null);

        return $hostname !== null && strcasecmp($hostname, (string) $this->name) !== 0 ? $hostname : null;
    }

    /** Last 4 characters of the token, for "which token is this?" hints. */
    public function getTokenHintAttribute(): ?string
    {
        $token = $this->token;

        return $token ? '…'.substr($token, -4) : null;
    }

    public function markConnected(): void
    {
        // Avoid a write on every API call: refresh at most once a minute.
        if ($this->last_error === null
            && $this->last_connected_at !== null
            && $this->last_connected_at->gt(now()->subMinute())) {
            return;
        }

        $this->forceFill([
            'last_connected_at' => now(),
            'last_error' => null,
        ])->save();
    }

    public function markError(string $message): void
    {
        // Output of a failed command, as an older API passes it on: no colours.
        $clean = trim((string) preg_replace(['/\x1b\[[0-9;]*[A-Za-z]/', '/\[\d{1,3}(?:;\d{1,3})*m/', '/━+/u'], '', $message));
        $message = $clean !== '' ? $clean : $message;
        if ($this->last_error === $message) {
            return;
        }

        $this->forceFill(['last_error' => $message])->save();
    }
}
