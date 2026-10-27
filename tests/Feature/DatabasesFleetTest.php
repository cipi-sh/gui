<?php

namespace CipiGui\Tests\Feature;

use CipiGui\Livewire\Databases;
use CipiGui\Models\CipiServer;
use CipiGui\Tests\TestCase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;

class DatabasesFleetTest extends TestCase
{
    private CipiServer $prod;

    private CipiServer $staging;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake(function (Request $request) {
            $host = parse_url($request->url(), PHP_URL_HOST);
            $path = parse_url($request->url(), PHP_URL_PATH);
            $staging = $host === 'staging.example.com';

            return match (true) {
                $path === '/api/dbs/shop/backup' && $request->method() === 'POST' => Http::response(['job_id' => 'job-1', 'status' => 'pending'], 202),
                $path === '/api/dbs' && $request->method() === 'POST' => Http::response(['job_id' => 'job-2', 'status' => 'pending'], 202),
                $path === '/api/dbs' => Http::response(['data' => $staging
                    ? [['engine' => 'pgsql', 'name' => 'apistg', 'user' => 'apistg', 'size' => '12 MB']]
                    : [['engine' => 'mariadb', 'name' => 'shop', 'user' => 'shop', 'size' => '842 MB'], ['engine' => 'mariadb', 'name' => 'blog', 'user' => 'blog', 'size' => '96 MB']],
                ]),
                $path === '/api/dbs/engines' => Http::response(['data' => ['default' => $staging ? 'pgsql' : 'mariadb', 'engines' => [
                    ['engine' => $staging ? 'pgsql' : 'mariadb', 'status' => 'running', 'port' => $staging ? 5432 : 3306, 'default' => true],
                ]]]),
                default => Http::response(['data' => []]),
            };
        });

        $this->prod = $this->server();
        $this->staging = $this->server(['name' => 'staging', 'url' => 'https://staging.example.com']);
        $this->actingAs($this->admin());
        session(['cipi_gui_server_id' => $this->prod->id]);
    }

    #[Test]
    public function databases_of_every_server_are_listed_with_engine_cards_across_servers(): void
    {
        Livewire::test(Databases::class)->call('loadDatabases')
            ->assertSee('shop')->assertSee('apistg')
            ->assertSeeInOrder(['All servers', 'production', 'staging'])
            ->assertSee('MariaDB')->assertSee('PostgreSQL')
            ->assertSee('3 of 3 databases')->assertSee('on 2 servers')
            ->call('setScope', $this->staging->id)
            ->assertSee('apistg')->assertDontSee('842 MB')
            ->assertSee('port 5432');
    }

    #[Test]
    public function the_create_dialog_takes_the_engines_of_the_chosen_server(): void
    {
        Livewire::test(Databases::class)->call('loadDatabases')->call('openCreate')
            ->assertSet('createServerId', $this->prod->id)->assertSet('dbEngine', 'mariadb')
            ->set('createServerId', $this->staging->id)
            ->assertSet('dbEngine', 'pgsql')
            ->set('dbName', 'reports')
            ->call('createDatabase')->assertHasNoErrors()
            ->assertSet('jobLabel', 'Create database reports on staging');

        Http::assertSent(fn (Request $r) => $r->method() === 'POST' && $r->url() === 'https://staging.example.com/api/dbs' && $r['engine'] === 'pgsql');
    }

    #[Test]
    public function row_actions_go_to_the_rows_server(): void
    {
        Livewire::test(Databases::class)->call('loadDatabases')
            ->call('backup', 'shop', 'mariadb', $this->prod->id)
            ->assertSet('jobLabel', 'Back up shop on production');

        Http::assertSent(fn (Request $r) => $r->method() === 'POST' && $r->url() === 'https://vps.example.com/api/dbs/shop/backup');
    }
}
