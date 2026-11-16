<?php

namespace CipiGui\Tests\Feature;

use CipiGui\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * Every Blade view of the panel, compiled the way a request compiles it
 * (Livewire's precompilers included) and parsed — whether a test happens to
 * render it or not. A view that does not compile is found here, by name,
 * instead of by the first page that includes it.
 */
class ViewsCompileTest extends TestCase
{
    /** @return list<string> */
    private function views(): array
    {
        $views = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(__DIR__.'/../../resources/views', \FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            if (str_ends_with((string) $file, '.blade.php')) {
                $views[] = (string) $file;
            }
        }
        sort($views);

        return $views;
    }

    #[Test]
    public function every_view_compiles_to_php_that_parses(): void
    {
        $this->assertNotEmpty($this->views());

        foreach ($this->views() as $view) {
            $compiled = app('blade.compiler')->compileString((string) file_get_contents($view));

            try {
                token_get_all($compiled, TOKEN_PARSE);
            } catch (\ParseError $e) {
                $this->fail(basename($view).' does not compile: '.$e->getMessage().' (line '.$e->getLine().' of the compiled view)');
            }
        }
    }

    #[Test]
    public function no_directive_is_left_in_a_compiled_view(): void
    {
        // Blade reads "@endif" as a directive only where a word could start: after a
        // letter, a digit or an underscore it is text. "on it@if(…)" printed the
        // condition on the Connections page, and "</x-slot:actions>@endif" — the tag
        // becomes " @endslot", so the endif was glued to a word — left an @if open:
        // the Backups tab did not compile (the first build of 4.2.0).
        foreach ($this->views() as $view) {
            $compiled = app('blade.compiler')->compileString((string) file_get_contents($view));

            $this->assertSame(
                0,
                preg_match('/(?<!@)@(if|elseif|else|endif|foreach|endforeach|forelse|endforelse|unless|endunless|enderror|isset|endisset|endphp|endslot)\b.{0,40}/', $compiled, $m),
                basename($view).' has a directive Blade did not compile: '.($m[0] ?? '')
            );
        }
    }
}
