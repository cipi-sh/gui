<?php

namespace CipiGui\Tests;

use CipiGui\CipiGuiServiceProvider;
use CipiGui\Models\CipiServer;
use CipiGui\Tests\Fixtures\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\LivewireServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    use RefreshDatabase;

    protected function getPackageProviders($app): array
    {
        return [LivewireServiceProvider::class, CipiGuiServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('k', 32)));
        $app['config']->set('database.default', 'testing');
        $app['config']->set('auth.providers.users.model', User::class);
        $app['config']->set('session.driver', 'array');
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/Fixtures/migrations');
    }

    protected function admin(array $attributes = []): User
    {
        return User::create($attributes + [
            'name' => 'Admin',
            'email' => 'admin@cipi.local',
            'password' => 'secret-password',
        ]);
    }

    protected function server(array $attributes = []): CipiServer
    {
        return CipiServer::create($attributes + [
            'name' => 'production',
            'url' => 'https://vps.example.com',
            'token' => 'demo-token-1234567890',
            'is_active' => true,
        ]);
    }
}
