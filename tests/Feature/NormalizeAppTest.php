<?php

namespace CipiGui\Tests\Feature;

use CipiGui\Livewire\Concerns\InteractsWithCipiServer;
use CipiGui\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

class NormalizeAppTest extends TestCase
{
    private function normalizer(): object
    {
        return new class
        {
            use InteractsWithCipiServer {
                normalizeApp as public;
                appKind as public;
                appRuntimeLabel as public;
            }

            public function dispatch(...$args): void {}
        };
    }

    #[Test]
    public function list_payloads_without_custom_are_classified_from_engine_and_node(): void
    {
        $n = $this->normalizer();

        // GET /api/apps (1.31) omits `custom`.
        $laravel = $n->normalizeApp(['app' => 'shop', 'engine' => 'mariadb', 'node' => false, 'php' => '8.5', 'octane' => 'frankenphp']);
        $custom = $n->normalizeApp(['app' => 'blog', 'engine' => null, 'node' => false, 'php' => '8.4']);
        $node = $n->normalizeApp(['app' => 'docs', 'engine' => null, 'node' => true, 'node_mode' => 'static', 'node_version' => 22]);

        $this->assertSame('laravel', $n->appKind($laravel));
        $this->assertSame('custom', $n->appKind($custom));
        $this->assertSame('node', $n->appKind($node));
        $this->assertSame('PHP 8.5 · Octane', $n->appRuntimeLabel($laravel));
        $this->assertSame('Node 22', $n->appRuntimeLabel($node));
    }

    #[Test]
    public function an_explicit_custom_flag_wins_and_string_booleans_are_parsed(): void
    {
        $n = $this->normalizer();

        $app = $n->normalizeApp(['app' => 'legacy', 'custom' => 'true', 'suspended' => '1', 'force_https' => 'false', 'basic_auth' => 0]);

        $this->assertTrue($app['custom']);
        $this->assertTrue($app['suspended']);
        $this->assertFalse($app['force_https']);
        $this->assertFalse($app['basic_auth']);
        $this->assertSame([], $app['redirects']);
        $this->assertNull($app['octane']);
    }
}
