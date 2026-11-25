<?php

namespace CipiGui\Tests\Feature;

use CipiGui\Http\Controllers\SiteIconController;
use CipiGui\Livewire\Apps;
use CipiGui\Tests\TestCase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\URL;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;

class SiteIconTest extends TestCase
{
    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==';

    private function iconUrl(string $site, string $name = 'shop'): string
    {
        return URL::signedRoute('cipi-gui.site-icon', ['site' => $site, 'name' => $name], absolute: false);
    }

    #[Test]
    public function the_declared_icon_is_fetched_once_then_served_from_the_cache(): void
    {
        Http::fake(fn (Request $request) => match (parse_url($request->url(), PHP_URL_PATH) ?: '/') {
            '/' => Http::response('<html><head><link rel="mask-icon" href="/mask.svg"><link rel="shortcut icon" href="img/fav.png"></head></html>'),
            // A server that does not know the type: the bytes say it is a PNG.
            '/img/fav.png' => Http::response(base64_decode(self::PNG), 200, ['Content-Type' => 'application/octet-stream']),
            default => Http::response('', 404),
        });

        $this->get($this->iconUrl('https://203.0.113.10'))
            ->assertOk()
            ->assertHeader('Content-Type', 'image/png')
            ->assertHeader('Cache-Control', 'max-age=604800, private')
            ->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->get($this->iconUrl('https://203.0.113.10'))->assertOk()->assertContent(base64_decode(self::PNG));

        Http::assertSentCount(2); // the home page and the icon, once
    }

    #[Test]
    public function a_site_on_this_machine_or_a_private_network_is_never_fetched(): void
    {
        Http::fake();

        foreach (['http://127.0.0.1', 'http://10.0.0.5', 'http://169.254.169.254'] as $site) {
            $this->get($this->iconUrl($site))->assertOk()->assertHeader('Content-Type', 'image/svg+xml');
        }

        Http::assertNothingSent();
    }

    #[Test]
    public function a_site_without_an_icon_gets_the_apps_initial_for_a_day(): void
    {
        // Laravel's empty public/favicon.ico, and a catch-all that answers HTML: neither is an icon.
        Http::fake(fn (Request $request) => str_ends_with($request->url(), '/favicon.ico')
            ? Http::response('', 200, ['Content-Type' => 'image/x-icon'])
            : Http::response('<html></html>'));

        // The app's initial, not the host's: console on app.example.com is C, not A (sites here are TEST-NET IPs: public, no DNS lookup).
        $this->get($this->iconUrl('http://203.0.113.20', 'console'))
            ->assertOk()
            ->assertHeader('Content-Type', 'image/svg+xml')
            ->assertHeader('Cache-Control', 'max-age=86400, private')
            ->assertSee('>C</text>', false);
    }

    #[Test]
    public function only_urls_signed_by_the_panel_are_fetched(): void
    {
        Http::fake();

        $this->get('/site-icon?site='.urlencode('http://169.254.169.254'))->assertForbidden();

        Http::assertNothingSent();
    }

    #[Test]
    public function hrefs_resolve_against_the_page(): void
    {
        $this->assertSame('https://x.test/en/fav.png', SiteIconController::resolve('fav.png', 'https://x.test/en/'));
        $this->assertSame('https://x.test/fav.png', SiteIconController::resolve('/fav.png', 'https://x.test/en/'));
        $this->assertSame('https://cdn.test/i.png', SiteIconController::resolve('//cdn.test/i.png', 'https://x.test'));
        $this->assertSame('http://x.test:8080/fav.ico', SiteIconController::resolve('fav.ico', 'http://x.test:8080'));
        $this->assertNull(SiteIconController::resolve('data:image/png;base64,AAAA', 'https://x.test'));
    }

    #[Test]
    public function every_app_row_shows_its_site_icon(): void
    {
        Http::fake(['*' => Http::response(['data' => [
            ['app' => 'shop', 'domain' => '*.shop.example.com', 'force_https' => true, 'php' => '8.5', 'aliases' => []],
        ]])]);
        $this->server();
        $this->actingAs($this->admin());

        Livewire::test(Apps::class)
            ->call('loadApps')
            ->assertSeeHtml('class="site-icon"')
            ->assertSeeHtml('/site-icon?name=shop&amp;site=https%3A%2F%2Fshop.example.com&amp;signature=');
    }
}
