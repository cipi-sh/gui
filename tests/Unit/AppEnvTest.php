<?php

namespace CipiGui\Tests\Unit;

use CipiGui\Support\AppEnv;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class AppEnvTest extends TestCase
{
    #[Test]
    public function it_accepts_the_api_rule_and_nothing_else(): void
    {
        foreach (['production', 'staging', 'development', 'testing', 'qa', 'pr-42', 'eu_west', '1', str_repeat('a', 32)] as $ok) {
            $this->assertTrue(AppEnv::valid($ok), $ok);
        }

        foreach (['', 'Production', 'pre prod', '-dev', '_dev', 'a.b', 'stag/ing', 'été', str_repeat('a', 33)] as $bad) {
            $this->assertFalse(AppEnv::valid($bad), $bad);
        }

        $this->assertSame('regex:'.AppEnv::PATTERN, AppEnv::rule());
        $this->assertContains(AppEnv::DEFAULT, AppEnv::SUGGESTIONS);
    }

    #[Test]
    public function missing_labels_are_production_and_only_production_is_muted(): void
    {
        $this->assertSame('production', AppEnv::normalize(null));
        $this->assertSame('production', AppEnv::normalize(''));
        $this->assertSame('production', AppEnv::normalize('  '));
        $this->assertSame('production', AppEnv::normalize(42));
        $this->assertSame('staging', AppEnv::normalize(' staging '));

        $this->assertTrue(AppEnv::isProduction('production'));
        $this->assertFalse(AppEnv::isProduction('staging'));
        $this->assertSame('badge-gray', AppEnv::badgeClass('production'));
        $this->assertSame('badge-env', AppEnv::badgeClass('staging'));
        $this->assertSame('badge-env', AppEnv::badgeClass('qa'));
    }

    #[Test]
    public function the_environment_file_is_read_before_the_generic_one(): void
    {
        $this->assertSame(['cipi-staging.yml', 'cipi.yml'], AppEnv::ymlFiles('staging'));
        $this->assertSame(['cipi-production.yml', 'cipi.yml'], AppEnv::ymlFiles('production'));
    }
}
