<?php

namespace CipiGui\Tests\Feature;

use CipiGui\Livewire\AppDetail;
use CipiGui\Tests\TestCase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;

/**
 * A deploy whose release went live and whose command then failed (the
 * post-deploy steps, or Cipi before 5.8.1 exiting 1 after every successful
 * deploy) is told apart from a deploy that failed.
 */
class DeployPublishedTest extends TestCase
{
    private const PUBLISHED = "\e[0;32m[INFO]\e[0m Deploying 'shop'...\n\n➤ Executing task deploy:symlink\n✔ Ok\nSuccessfully deployed!\n\n\e[0;32m✓\e[0m Deploy completed  (release 4, 11s)";

    /** @var array<string, mixed> Answer of GET /api/jobs/job-1 next to id and type */
    private array $job = [];

    private int $auditReads = 0;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake(function (Request $request) {
            $path = parse_url($request->url(), PHP_URL_PATH);

            return match (true) {
                $path === '/api/apps/shop' => Http::response(['data' => [
                    'app' => 'shop', 'domain' => 'shop.example.com', 'php' => '8.5', 'engine' => 'mariadb', 'node' => false, 'aliases' => [],
                    'repository' => 'git@github.com:acme/shop.git', 'branch' => 'main',
                ]]),
                $path === '/api/apps/shop/deploy' && $request->method() === 'POST' => Http::response(['job_id' => 'job-1', 'status' => 'pending'], 202),
                $path === '/api/apps/shop/deploy/audit' => $this->audit(),
                $path === '/api/apps/shop/logs' => Http::response(['data' => [
                    'available_types' => ['deploy'], 'files' => [['path' => '/home/shop/logs/deploy.log', 'lines' => []]], 'warnings' => [],
                ]]),
                str_starts_with($path, '/api/jobs/') => Http::response(['data' => ['id' => 'job-1', 'type' => 'app-deploy'] + $this->job]),
                default => Http::response(['data' => []]),
            };
        });

        $server = $this->server();
        $this->actingAs($this->admin());
        session(['cipi_gui_server_id' => $server->id]);
    }

    private function audit()
    {
        $this->auditReads++;

        return Http::response(['data' => ['app' => 'shop', 'records' => [
            ['seq' => 1, 'ts' => '2026-10-27T08:00:00Z', 'event' => 'published', 'release' => '4', 'origin' => 'panel'],
        ]]]);
    }

    #[Test]
    public function a_deploy_that_published_and_then_failed_is_a_warning_with_its_own_hints(): void
    {
        // What cipi/api returns for Cipi < 5.8.1: exit 1, the success line as the error.
        $this->job = ['status' => 'failed', 'exit_code' => 1, 'output' => self::PUBLISHED, 'result' => ['error' => 'Deploy completed  (release 4, 11s)']];

        $component = Livewire::test(AppDetail::class, ['name' => 'shop'])->call('setTab', 'deploy');
        $reads = $this->auditReads;

        $component->call('deploy')->call('pollJob')
            ->assertSet('activeJobStatus', 'failed')
            ->assertSet('jobRunning', false)
            ->assertSet('activeJobError', 'The release is live, but cipi deploy exited with code 1 afterwards without saying why. '
                .'Check the deploy log. Cipi before 5.8.1 exits 1 after every successful deploy: run cipi self-update on the server.')
            ->assertSee('The release is live, but the command then failed')
            ->assertSee('What to check')
            ->assertSee('nothing was rolled back')
            ->assertDontSee('The job failed')
            ->assertDontSee('Deploy troubleshooting')
            ->assertDontSee('Add the deploy key to your Git provider');

        $this->assertSame($reads + 1, $this->auditReads, 'the Deploy tab reloads its audit: a new release is live');
    }

    #[Test]
    public function a_published_deploy_quotes_the_post_deploy_error(): void
    {
        $this->job = ['status' => 'failed', 'exit_code' => 1, 'output' => self::PUBLISHED
            ."\n\n→ Post-deploy steps (cipi.yml)...\n\e[0;31m[ERROR]\e[0m Post-deploy: 1 of 2 failed (warn)\n[WARN] Post-deploy steps failed — see the deploy log."];

        Livewire::test(AppDetail::class, ['name' => 'shop'])->call('deploy')->call('pollJob')
            ->assertSet('activeJobError', 'The release is live, but cipi deploy exited with code 1 afterwards: Post-deploy: 1 of 2 failed (warn)')
            ->assertSee('The release is live, but the command then failed');
    }

    #[Test]
    public function a_deploy_that_did_not_publish_is_still_a_failed_job_with_the_deploy_hints(): void
    {
        $this->job = ['status' => 'failed', 'exit_code' => 1, 'result' => ['error' => 'Permission denied (publickey).'],
            'output' => "➤ Executing task deploy:update_code\n\e[0;31m[ERROR]\e[0m Permission denied (publickey)."];

        Livewire::test(AppDetail::class, ['name' => 'shop'])->call('deploy')->call('pollJob')
            ->assertSet('activeJobError', 'Permission denied (publickey).')
            ->assertSee('The job failed')
            ->assertSee('Deploy troubleshooting')
            ->assertDontSee('The release is live');
    }

    #[Test]
    public function a_completed_deploy_is_untouched(): void
    {
        $this->job = ['status' => 'completed', 'exit_code' => 0, 'output' => self::PUBLISHED, 'result' => ['app' => 'shop', 'deployed' => true]];

        Livewire::test(AppDetail::class, ['name' => 'shop'])->call('deploy')->call('pollJob')
            ->assertSet('activeJobStatus', 'completed')
            ->assertSet('activeJobError', null)
            ->assertDontSee('The release is live, but');
    }
}
