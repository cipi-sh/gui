<?php

namespace CipiGui\Tests\Feature;

use CipiGui\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/** Temporary: prints what Blade makes of the Backups partial, to find its parse error. Removed with the fix. */
class ZzBladeProbeTest extends TestCase
{
    #[Test]
    public function what_blade_compiles_the_backups_partial_to(): void
    {
        $compiled = app('blade.compiler')->compileString((string) file_get_contents(__DIR__.'/../../resources/views/partials/server-backups.blade.php'));
        $tmp = tempnam(sys_get_temp_dir(), 'bl');
        file_put_contents($tmp, $compiled);
        exec('php -l '.escapeshellarg($tmp).' 2>&1', $out, $code);
        fwrite(STDERR, "\n=====PROBE-BEGIN=====\n".base64_encode(gzencode($compiled, 9))."\n=====PROBE-END=====\n".implode("\n", $out)."\n");
        $this->assertSame(0, $code, implode("\n", $out));
    }
}
