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
        'is_active',
        'last_connected_at',
        'last_error',
    ];

    protected $hidden = [
        'token',
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
        if ($this->last_error === $message) {
            return;
        }

        $this->forceFill(['last_error' => $message])->save();
    }
}
