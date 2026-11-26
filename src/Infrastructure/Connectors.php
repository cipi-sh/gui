<?php

namespace CipiGui\Infrastructure;

use CipiGui\Infrastructure\Connectors\Aikido;
use CipiGui\Infrastructure\Connectors\Aws;
use CipiGui\Infrastructure\Connectors\Azure;
use CipiGui\Infrastructure\Connectors\Backblaze;
use CipiGui\Infrastructure\Connectors\BetterStack;
use CipiGui\Infrastructure\Connectors\Boogle;
use CipiGui\Infrastructure\Connectors\Cloudflare;
use CipiGui\Infrastructure\Connectors\Contabo;
use CipiGui\Infrastructure\Connectors\Datadog;
use CipiGui\Infrastructure\Connectors\DigitalOcean;
use CipiGui\Infrastructure\Connectors\Gandi;
use CipiGui\Infrastructure\Connectors\GoogleCloud;
use CipiGui\Infrastructure\Connectors\Hackly;
use CipiGui\Infrastructure\Connectors\Hetzner;
use CipiGui\Infrastructure\Connectors\Johnny;
use CipiGui\Infrastructure\Connectors\Linode;
use CipiGui\Infrastructure\Connectors\Mailgun;
use CipiGui\Infrastructure\Connectors\Namecheap;
use CipiGui\Infrastructure\Connectors\OhDear;
use CipiGui\Infrastructure\Connectors\Ovh;
use CipiGui\Infrastructure\Connectors\Porkbun;
use CipiGui\Infrastructure\Connectors\Postmark;
use CipiGui\Infrastructure\Connectors\Resend;
use CipiGui\Infrastructure\Connectors\Scaleway;
use CipiGui\Infrastructure\Connectors\Sentry;
use CipiGui\Infrastructure\Connectors\UpCloud;
use CipiGui\Infrastructure\Connectors\Vultr;
use CipiGui\Infrastructure\Connectors\Wasabi;
use CipiGui\Models\CipiConnectorAccount;
use RuntimeException;

/**
 * Every provider the panel drives, in the order of Settings → Connectors and
 * of the sidebar. A connector shows in the sidebar once it has an account;
 * both are for admins only.
 */
final class Connectors
{
    /** @var array<string, class-string<Connector>> */
    public const ALL = [
        'cloudflare' => Cloudflare::class,
        'digitalocean' => DigitalOcean::class,
        'hetzner' => Hetzner::class,
        'aws' => Aws::class,
        'gcp' => GoogleCloud::class,
        'azure' => Azure::class,
        'ovh' => Ovh::class,
        'vultr' => Vultr::class,
        'linode' => Linode::class,
        'scaleway' => Scaleway::class,
        'contabo' => Contabo::class,
        'upcloud' => UpCloud::class,
        'porkbun' => Porkbun::class,
        'gandi' => Gandi::class,
        'namecheap' => Namecheap::class,
        'johnny' => Johnny::class,
        'backblaze' => Backblaze::class,
        'wasabi' => Wasabi::class,
        'postmark' => Postmark::class,
        'mailgun' => Mailgun::class,
        'resend' => Resend::class,
        'boogle' => Boogle::class,
        'ohdear' => OhDear::class,
        'betterstack' => BetterStack::class,
        'sentry' => Sentry::class,
        'datadog' => Datadog::class,
        'aikido' => Aikido::class,
        'hackly' => Hackly::class,
    ];

    /** The groups of Settings → Connectors, in order. */
    public const CATEGORIES = [
        'cloud' => 'Clouds and servers',
        'dns' => 'Domains and DNS',
        'storage' => 'Object storage',
        'email' => 'Email',
        'monitoring' => 'Monitoring and errors',
        'security' => 'Security',
    ];

    /** @return array<string, Connector> */
    public static function all(): array
    {
        return array_map(fn (string $class) => app($class), self::ALL);
    }

    public static function find(string $key): ?Connector
    {
        return isset(self::ALL[$key]) ? app(self::ALL[$key]) : null;
    }

    public static function make(string $key): Connector
    {
        return self::find($key) ?? throw new RuntimeException("Unknown connector [{$key}].");
    }

    /**
     * Settings → Connectors, group by group: each connector where it belongs,
     * and again, with what it does there, in the groups it also covers.
     *
     * @return array<string, list<array{connector: Connector, blurb: string|null}>>
     */
    public static function grouped(): array
    {
        $groups = array_fill_keys(array_keys(self::CATEGORIES), []);

        foreach (self::all() as $connector) {
            $groups[$connector->category()][] = ['connector' => $connector, 'blurb' => null];
        }

        foreach (self::all() as $connector) {
            foreach ($connector->alsoIn() as $category => $blurb) {
                if (isset($groups[$category]) && $category !== $connector->category()) {
                    $groups[$category][] = ['connector' => $connector, 'blurb' => $blurb];
                }
            }
        }

        return array_filter($groups);
    }

    /**
     * The connectors with at least one account: the sidebar's Infrastructure.
     *
     * @return list<Connector>
     */
    public static function connected(): array
    {
        try {
            $keys = CipiConnectorAccount::query()->distinct()->pluck('connector')->all();
        } catch (\Throwable) {
            // Before the migration ran (a panel just updated): no connector yet.
            return [];
        }

        return array_values(array_filter(self::all(), fn (Connector $c) => in_array($c->key(), $keys, true)));
    }
}
