<?php

namespace CipiGui\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;

class CipiServer extends Model
{
    protected $fillable = [
        'name',
        'url',
        'token',
        'is_active',
        'last_connected_at',
        'last_error',
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

    public function markConnected(): void
    {
        $this->update([
            'last_connected_at' => now(),
            'last_error' => null,
        ]);
    }

    public function markError(string $message): void
    {
        $this->update(['last_error' => $message]);
    }
}
