<?php

namespace CipiGui\Tests\Feature;

use CipiGui\Livewire\ServerManage;
use CipiGui\Models\CipiServer;
use CipiGui\Services\Access;
use CipiGui\Tests\TestCase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;

/**
 * The Packages tab (4.6.0, API 1.44 / Cipi 5.14.0): the closed catalog,
 * installed and removed from the panel as jobs.
 */
class PackagesTest extends TestCase
{
    private CipiServer $server;

    /** @var array<string, array{0: array<string, mixed>, 1: int}> "METHOD path" => [body, status] */
    private array $answers = [];

    protected function setUp(): void
    {
        parent::setUp();

        $job = [['job_id' => '11111111-2222-4333-8444-555555555555', 'status' => 'pending'], 202];
        $this->answers = [
            'GET /api/packages' => [['data' => ['packages' => [
                ['id' => 'image-optimizers', 'packages' => ['jpegoptim', 'optipng', 'pngquant', 'gifsicle', 'webp'], 'description' => 'Image optimisers', 'kind' => 'tools',
                    'binaries' => ['jpegoptim', 'optipng', 'pngquant', 'gifsicle', 'cwebp'], 'missing' => ['pngquant', 'gifsicle'], 'installed' => false, 'partial' => true],
                ['id' => 'imagemagick', 'packages' => ['imagemagick'], 'description' => 'ImageMagick CLI', 'kind' => 'tools',
                    'binaries' => ['convert'], 'missing' => [], 'installed' => true, 'partial' => false],
                ['id' => 'browsershot', 'packages' => ['libnss3', 'libgbm1', 'fonts-liberation'], 'description' => 'Libraries and fonts for the Chrome Puppeteer downloads', 'kind' => 'libraries',
                    'binaries' => [], 'missing' => ['libnss3', 'libgbm1', 'fonts-liberation'], 'installed' => false, 'partial' => false],
                ['id' => 'ffmpeg', 'packages' => ['ffmpeg'], 'description' => 'Audio/video transcoding', 'kind' => 'tools',
                    'binaries' => ['ffmpeg', 'ffprobe'], 'missing' => ['ffmpeg'], 'installed' => false, 'partial' => false],
            ]]], 200],
            'POST /api/packages/browsershot' => $job,
            'POST /api/packages/image-optimizers' => $job,
            'DELETE /api/packages/imagemagick' => $job,
        ];

        Http::fake(function (Request $request) {
            $key = $request->method().' '.parse_url($request->url(), PHP_URL_PATH);
            [$body, $status] = $this->answers[$key] ?? [['data' => []], 200];

            return Http::response($body, $status);
        });

        $this->server = $this->server();
        $this->actingAs($this->admin());
        session(['cipi_gui_server_id' => $this->server->id]);
    }

    private function sent(string $method, string $path): int
    {
        return Http::recorded(fn (Request $r) => $r->method() === $method && parse_url($r->url(), PHP_URL_PATH) === $path)->count();
    }

    private function tab()
    {
        return Livewire::withQueryParams(['tab' => 'packages'])->test(ServerManage::class, ['serverId' => $this->server->id])->call('init');
    }

    #[Test]
    public function the_tab_shows_the_catalog_with_its_commands_and_what_is_missing(): void
    {
        $this->tab()
            ->assertSee('browsershot')->assertSee('Libraries')->assertSee('npm install puppeteer')
            ->assertSee('ffprobe')
            ->assertSee('Partial')->assertSee('pngquant gifsicle')
            ->assertSee('Complete install')
            ->assertDontSee('Read-only by design');
    }

    #[Test]
    public function entries_are_installed_and_removed_as_jobs(): void
    {
        $page = $this->tab();

        $page->call('installPackage', 'browsershot');
        $this->assertSame(1, $this->sent('POST', '/api/packages/browsershot'));

        $page->call('dismissJob')->call('installPackage', 'image-optimizers');    // partial: completes it
        $this->assertSame(1, $this->sent('POST', '/api/packages/image-optimizers'));

        $page->call('dismissJob')->call('removePackage', 'imagemagick');
        $this->assertSame(1, $this->sent('DELETE', '/api/packages/imagemagick'));
    }

    #[Test]
    public function only_what_the_page_lists_and_what_makes_sense_is_sent(): void
    {
        $this->tab()
            ->call('installPackage', 'chromium')          // not in the catalog
            ->call('installPackage', 'imagemagick')       // already installed
            ->call('removePackage', 'ffmpeg');            // not installed

        $this->assertSame(0, $this->sent('POST', '/api/packages/chromium'));
        $this->assertSame(0, $this->sent('POST', '/api/packages/imagemagick'));
        $this->assertSame(0, $this->sent('DELETE', '/api/packages/ffmpeg'));
    }

    #[Test]
    public function a_view_only_user_reads_it_and_changes_nothing(): void
    {
        $member = $this->member();
        Access::replaceGrants((int) $member->getKey(), [$this->server->id => Access::VIEW], []);
        $this->actingAs($member);

        $this->tab()
            ->assertSee('browsershot')
            ->assertDontSeeHtml('wire:click="installPackage(')
            ->call('installPackage', 'browsershot')
            ->call('removePackage', 'imagemagick');

        $this->assertSame(0, $this->sent('POST', '/api/packages/browsershot'));
        $this->assertSame(0, $this->sent('DELETE', '/api/packages/imagemagick'));
    }

    #[Test]
    public function an_older_server_says_why(): void
    {
        $this->answers['POST /api/packages/browsershot'] = [['error' => 'Installing and removing packages from the panel needs Cipi 5.14.0+ — this server runs Cipi 5.13.1. Run: cipi self-update'], 503];

        $this->tab()->call('installPackage', 'browsershot')->assertSee('this server runs Cipi 5.13.1');
    }
}
