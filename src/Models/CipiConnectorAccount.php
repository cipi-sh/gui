<?php

namespace CipiGui\Models;

use CipiGui\Infrastructure\Connector;
use CipiGui\Infrastructure\Connectors;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * One account of a connector (Settings → Connectors): a Cloudflare API token,
 * a DigitalOcean or Hetzner token, an AWS access key, a Google Cloud service
 * account, an Azure service principal… The credentials are stored encrypted
 * and never go back to the page: secret fields are left blank to keep them.
 *
 * @property int $id
 * @property string $uuid
 * @property string $connector
 * @property string $name
 * @property array<string, mixed>|null $credentials
 * @property string|null $status
 * @property \Illuminate\Support\Carbon|null $checked_at
 */
class CipiConnectorAccount extends Model
{
    protected $table = 'cipi_connector_accounts';

    protected $fillable = ['uuid', 'connector', 'name', 'credentials', 'status', 'checked_at'];

    protected $hidden = ['credentials'];

    protected function casts(): array
    {
        return [
            'credentials' => 'encrypted:array',
            'checked_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (CipiConnectorAccount $account) {
            $account->uuid ??= (string) Str::uuid();
        });
        static::deleted(fn (CipiConnectorAccount $account) => $account->forget());
    }

    public function definition(): Connector
    {
        return Connectors::make($this->connector);
    }

    /** The API client of the connector, signed in with this account. */
    public function client(): object
    {
        return $this->definition()->client($this->credentials ?? []);
    }

    public function credential(string $name, mixed $default = null): mixed
    {
        return ($this->credentials ?? [])[$name] ?? $default;
    }

    public function isHealthy(): bool
    {
        return $this->status === 'ok' || str_starts_with((string) $this->status, 'ok ');
    }

    /**
     * What the pages read from this account is cached for a little while
     * under a version number: a change made from the panel bumps it.
     */
    public function cacheKey(string ...$parts): string
    {
        return 'cipi-gui.infra:'.$this->uuid.':'.Cache::get($this->versionKey(), 0).':'.md5(implode('|', $parts));
    }

    public function forget(): void
    {
        Cache::forever($this->versionKey(), (int) Cache::get($this->versionKey(), 0) + 1);
    }

    private function versionKey(): string
    {
        return 'cipi-gui.infra:'.$this->uuid.':version';
    }
}
