<?php

namespace CipiGui\Services;

use CipiGui\Models\CipiAppGrant;
use CipiGui\Models\CipiServer;
use CipiGui\Models\CipiServerGrant;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Who may do what. Admins see and do everything; a user has what an admin gave
 * them: a level on whole servers (view or manage) and abilities on single apps.
 *
 *   server `view`    the server on the dashboard and its page, read-only; its
 *                    apps and databases listed, apps opened read-only
 *   server `manage`  everything on that server: its page, its databases, creating
 *                    apps, every ability on every app
 *   app abilities    view, deploy, edit, env, logs, run, delete — on one app,
 *                    with or without a level on its server
 */
final class Access
{
    public const ADMIN = 'admin';

    public const USER = 'user';

    public const VIEW = 'view';

    public const MANAGE = 'manage';

    public const APP_ABILITIES = ['view', 'deploy', 'edit', 'env', 'logs', 'run', 'delete'];

    public const ABILITY_LABELS = [
        'view' => ['View', 'Overview, domains, routing, deploy audit, healthcheck — read-only'],
        'deploy' => ['Deploy', 'Deploy, roll back, unlock a stuck deploy'],
        'edit' => ['Edit', 'Configuration, domains and SSL, routing, deploy pipeline, cipi.yml, healthcheck, basic auth, suspend'],
        'env' => ['Environment', 'Read and change .env and Composer auth.json — they hold secrets'],
        'logs' => ['Logs', 'Read nginx, PHP, Laravel, worker and deploy logs'],
        'run' => ['Commands', 'Run Artisan and the whitelisted commands'],
        'delete' => ['Delete', 'Delete the app and everything it owns'],
    ];

    public const SERVER_LEVELS = [
        'view' => ['View', 'The server page read-only; its apps and databases listed, apps opened read-only'],
        'manage' => ['Manage', 'Everything on the server: PHP, services, SSH keys, databases, creating apps and every ability on every app'],
    ];

    /** @var array<int|string, array{servers: array<int, string>, apps: array<int, array<string, list<string>>>}> */
    private static array $grants = [];

    /** @var array<string, bool> */
    private static array $rolesEnabled = [];

    // ── roles ────────────────────────────────────────────────────────

    public static function isAdmin(?object $user): bool
    {
        if (! $user) {
            return false;
        }

        // Before the role migration runs, everyone who can sign in is an admin.
        if (! self::rolesEnabled($user)) {
            return true;
        }

        return ($user->role ?? self::USER) === self::ADMIN;
    }

    public static function role(?object $user): string
    {
        return self::isAdmin($user) ? self::ADMIN : self::USER;
    }

    public static function rolesEnabled(object $user): bool
    {
        $table = method_exists($user, 'getTable') ? $user->getTable() : 'users';

        return self::$rolesEnabled[$table] ??= Schema::hasColumn($table, 'role');
    }

    // ── servers ──────────────────────────────────────────────────────

    public static function serverLevel(?object $user, int $serverId): ?string
    {
        if (self::isAdmin($user)) {
            return self::MANAGE;
        }

        return $user ? (self::grantsFor($user)['servers'][$serverId] ?? null) : null;
    }

    public static function canViewServer(?object $user, int $serverId): bool
    {
        return self::serverLevel($user, $serverId) !== null;
    }

    public static function canManageServer(?object $user, int $serverId): bool
    {
        return self::serverLevel($user, $serverId) === self::MANAGE;
    }

    /** Servers the user may open: a level on the server, or an app on it. null for every server. */
    public static function visibleServerIds(?object $user): ?array
    {
        if (self::isAdmin($user)) {
            return null;
        }

        if (! $user) {
            return [];
        }

        $grants = self::grantsFor($user);

        return array_values(array_unique(array_merge(array_keys($grants['servers']), array_keys($grants['apps']))));
    }

    /** Servers where the user may create apps and databases and manage the server. null for every server. */
    public static function manageableServerIds(?object $user): ?array
    {
        if (self::isAdmin($user)) {
            return null;
        }

        if (! $user) {
            return [];
        }

        return array_keys(array_filter(self::grantsFor($user)['servers'], fn (string $level) => $level === self::MANAGE));
    }

    /** Active servers the user may open, by name. */
    public static function visibleServers(?object $user): Collection
    {
        $query = CipiServer::query()->where('is_active', true)->orderBy('name');
        $ids = self::visibleServerIds($user);

        if ($ids !== null) {
            $query->whereIn('id', $ids);
        }

        return $query->get();
    }

    /** Active servers whose page the user may open (a level on the server), by name. */
    public static function serversWithLevel(?object $user): Collection
    {
        if (self::isAdmin($user)) {
            return self::visibleServers($user);
        }

        $ids = $user ? array_keys(self::grantsFor($user)['servers']) : [];

        return CipiServer::query()->where('is_active', true)->whereIn('id', $ids)->orderBy('name')->get();
    }

    /** Every app on the server is listed (a level on the server); otherwise only granted apps. */
    public static function seesAllAppsOn(?object $user, int $serverId): bool
    {
        return self::canViewServer($user, $serverId);
    }

    // ── apps ─────────────────────────────────────────────────────────

    /** @return list<string> */
    public static function appAbilities(?object $user, int $serverId, string $app): array
    {
        $level = self::serverLevel($user, $serverId);

        if ($level === self::MANAGE) {
            return self::APP_ABILITIES;
        }

        $abilities = $level === self::VIEW ? ['view'] : [];
        $granted = $user ? (self::grantsFor($user)['apps'][$serverId][$app] ?? []) : [];

        if ($granted !== []) {
            $abilities = array_merge($abilities, ['view'], $granted);
        }

        return array_values(array_unique(array_intersect(self::APP_ABILITIES, $abilities)));
    }

    public static function can(?object $user, string $ability, int $serverId, string $app): bool
    {
        return in_array($ability, self::appAbilities($user, $serverId, $app), true);
    }

    /** @return array<string, bool> ability => allowed, for views */
    public static function appAbilityMap(?object $user, int $serverId, string $app): array
    {
        $allowed = self::appAbilities($user, $serverId, $app);

        return array_combine(self::APP_ABILITIES, array_map(fn (string $a) => in_array($a, $allowed, true), self::APP_ABILITIES));
    }

    // ── grants ───────────────────────────────────────────────────────

    /** @return array{servers: array<int, string>, apps: array<int, array<string, list<string>>>} */
    public static function grantsFor(object $user): array
    {
        $id = $user->getAuthIdentifier();

        if (isset(self::$grants[$id])) {
            return self::$grants[$id];
        }

        $servers = [];
        $apps = [];

        try {
            foreach (CipiServerGrant::query()->where('user_id', $id)->get() as $grant) {
                $servers[(int) $grant->server_id] = in_array($grant->level, [self::VIEW, self::MANAGE], true) ? $grant->level : self::VIEW;
            }
            foreach (CipiAppGrant::query()->where('user_id', $id)->get() as $grant) {
                $abilities = array_values(array_intersect(self::APP_ABILITIES, (array) $grant->abilities));
                if ($abilities !== []) {
                    $apps[(int) $grant->server_id][(string) $grant->app] = $abilities;
                }
            }
        } catch (\Throwable) {
            // Tables missing (migrations not run): no grants.
        }

        return self::$grants[$id] = ['servers' => $servers, 'apps' => $apps];
    }

    /**
     * Replace a user's grants.
     *
     * @param  array<int|string, string>  $serverLevels  server id => '' | view | manage
     * @param  array<int|string, array<string, list<string>>>  $appGrants  server id => app => abilities
     */
    public static function replaceGrants(int $userId, array $serverLevels, array $appGrants): void
    {
        [$serverRows, $appRows] = self::normalizeGrants($serverLevels, $appGrants);

        DB::transaction(function () use ($userId, $serverRows, $appRows) {
            CipiServerGrant::query()->where('user_id', $userId)->delete();
            CipiAppGrant::query()->where('user_id', $userId)->delete();

            foreach ($serverRows as $serverId => $level) {
                CipiServerGrant::create(['user_id' => $userId, 'server_id' => $serverId, 'level' => $level]);
            }
            foreach ($appRows as $serverId => $apps) {
                foreach ($apps as $app => $abilities) {
                    CipiAppGrant::create(['user_id' => $userId, 'server_id' => $serverId, 'app' => $app, 'abilities' => $abilities]);
                }
            }
        });

        unset(self::$grants[$userId]);
    }

    /**
     * Clean editor input: known servers only, valid levels and abilities, no app
     * grants on a server the user manages anyway.
     *
     * @return array{0: array<int, string>, 1: array<int, array<string, list<string>>>}
     */
    public static function normalizeGrants(array $serverLevels, array $appGrants): array
    {
        $known = CipiServer::query()->pluck('id')->map(fn ($id) => (int) $id)->all();
        $servers = [];
        $apps = [];

        foreach ($serverLevels as $serverId => $level) {
            $serverId = (int) $serverId;
            if (in_array($serverId, $known, true) && in_array($level, [self::VIEW, self::MANAGE], true)) {
                $servers[$serverId] = $level;
            }
        }

        foreach ($appGrants as $serverId => $perApp) {
            $serverId = (int) $serverId;
            if (! in_array($serverId, $known, true) || ($servers[$serverId] ?? null) === self::MANAGE || ! is_array($perApp)) {
                continue;
            }
            foreach ($perApp as $app => $abilities) {
                $abilities = array_values(array_intersect(self::APP_ABILITIES, array_map('strval', (array) $abilities)));
                if (preg_match('/^[a-z][a-z0-9]{2,31}$/', (string) $app) && $abilities !== []) {
                    $apps[$serverId][(string) $app] = $abilities;
                }
            }
        }

        return [$servers, $apps];
    }

    /** "All servers", or "2 servers · 3 apps" — for the Team page. */
    public static function summary(object $user): string
    {
        if (self::isAdmin($user)) {
            return 'Every server';
        }

        $grants = self::grantsFor($user);
        $servers = count($grants['servers']);
        $apps = array_sum(array_map('count', $grants['apps']));

        if ($servers === 0 && $apps === 0) {
            return 'No access yet';
        }

        $parts = [];
        if ($servers > 0) {
            $parts[] = $servers.' '.($servers === 1 ? 'server' : 'servers');
        }
        if ($apps > 0) {
            $parts[] = $apps.' '.($apps === 1 ? 'app' : 'apps');
        }

        return implode(' · ', $parts);
    }

    public static function flush(): void
    {
        self::$grants = [];
        self::$rolesEnabled = [];
    }
}
