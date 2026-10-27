<?php

namespace CipiGui\Tests\Feature;

use CipiGui\Services\Access;
use CipiGui\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

class AccessTest extends TestCase
{
    #[Test]
    public function admins_see_and_do_everything(): void
    {
        $server = $this->server();
        $admin = $this->admin();

        $this->assertTrue(Access::isAdmin($admin));
        $this->assertNull(Access::visibleServerIds($admin));
        $this->assertTrue(Access::canManageServer($admin, $server->id));
        $this->assertSame(Access::APP_ABILITIES, Access::appAbilities($admin, $server->id, 'shop'));
        $this->assertSame('Every server', Access::summary($admin));
    }

    #[Test]
    public function a_user_has_only_what_was_granted(): void
    {
        $prod = $this->server();
        $staging = $this->server(['name' => 'staging', 'url' => 'https://staging.example.com']);
        $other = $this->server(['name' => 'other', 'url' => 'https://other.example.com']);
        $user = $this->member();

        Access::replaceGrants($user->id, [$prod->id => 'view', $staging->id => 'manage'], [
            $prod->id => ['shop' => ['deploy', 'logs']],
            $staging->id => ['api' => ['delete']], // dropped: the server is managed anyway
            $other->id => ['blog' => ['view']],
        ]);

        $this->assertFalse(Access::isAdmin($user));
        $this->assertEqualsCanonicalizing([$prod->id, $staging->id, $other->id], Access::visibleServerIds($user));
        $this->assertSame([$staging->id], Access::manageableServerIds($user));

        $this->assertTrue(Access::canViewServer($user, $prod->id));
        $this->assertFalse(Access::canManageServer($user, $prod->id));
        $this->assertTrue(Access::canManageServer($user, $staging->id));
        $this->assertFalse(Access::canViewServer($user, $other->id), 'an app grant alone does not open the server page');

        $this->assertSame(['view', 'deploy', 'logs'], Access::appAbilities($user, $prod->id, 'shop'));
        $this->assertSame(['view'], Access::appAbilities($user, $prod->id, 'blog'), 'server view = every app read-only');
        $this->assertSame(Access::APP_ABILITIES, Access::appAbilities($user, $staging->id, 'anything'));
        $this->assertSame(['view'], Access::appAbilities($user, $other->id, 'blog'));
        $this->assertSame([], Access::appAbilities($user, $other->id, 'docs'));
        $this->assertTrue(Access::seesAllAppsOn($user, $prod->id));
        $this->assertFalse(Access::seesAllAppsOn($user, $other->id));
        $this->assertSame('2 servers · 2 apps', Access::summary($user));
    }

    #[Test]
    public function grants_are_cleaned_before_saving(): void
    {
        $server = $this->server();

        [$servers, $apps] = Access::normalizeGrants(
            [$server->id => 'manage', 999 => 'view', $server->id + 1 => 'bogus'],
            [$server->id => ['shop' => ['deploy']], 999 => ['x' => ['view']]],
        );

        $this->assertSame([$server->id => 'manage'], $servers);
        $this->assertSame([], $apps, 'no app grants on a managed server, none on unknown servers');

        [, $apps] = Access::normalizeGrants([], [$server->id => ['shop' => ['deploy', 'nope', 'view'], 'Bad Name' => ['view'], 'empty' => []]]);
        $this->assertSame([$server->id => ['shop' => ['view', 'deploy']]], $apps);
    }
}
