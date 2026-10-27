<?php

namespace CipiGui\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class CipiInvite extends Model
{
    protected $table = 'cipi_invites';

    protected $fillable = ['token', 'label', 'role', 'server_grants', 'app_grants', 'created_by', 'expires_at', 'accepted_at', 'accepted_by'];

    protected function casts(): array
    {
        return [
            'server_grants' => 'array',
            'app_grants' => 'array',
            'expires_at' => 'datetime',
            'accepted_at' => 'datetime',
        ];
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->whereNull('accepted_at')->where('expires_at', '>', now());
    }

    public function isUsable(): bool
    {
        return $this->accepted_at === null && $this->expires_at->isFuture();
    }
}
