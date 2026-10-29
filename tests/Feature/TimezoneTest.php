<?php

namespace CipiGui\Tests\Feature;

use CipiGui\Livewire\Settings;
use CipiGui\Support\Clock;
use CipiGui\Tests\TestCase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;

/**
 * 4.1.1 — every absolute time the panel shows follows the account's time zone
 * (Settings → Profile), UTC until one is chosen.
 */
class TimezoneTest extends TestCase
{
    #[Test]
    public function an_account_starts_on_utc(): void
    {
        $this->actingAs($this->admin());

        $this->assertSame('UTC', Clock::timezone());
        $this->assertSame('2026-10-28 17:32', Clock::format('2026-10-28T18:32:49+0100'));
        $this->assertSame('17:32:49', Clock::format(1793208769, 'H:i:s'));
        $this->assertSame('UTC', Clock::label());
        $this->assertSame('UTC', Clock::abbreviation());
        $this->assertNull(Clock::local(null));
        $this->assertNull(Clock::local('not a time'));
    }

    #[Test]
    public function the_zone_is_chosen_in_the_profile_and_every_time_follows_it(): void
    {
        $user = $this->admin();
        $this->actingAs($user);

        Livewire::test(Settings::class)->set('timezone', 'Europe/Rome')->call('saveProfile')->assertHasNoErrors();

        $this->assertSame('Europe/Rome', $user->fresh()->timezone);
        $this->actingAs($user->fresh());
        $this->assertSame('Europe/Rome', Clock::timezone());
        $this->assertSame('2026-10-28 18:32', Clock::format('2026-10-28T18:32:49+0100'));
        $this->assertSame('2026-10-28 18:32', Clock::format('2026-10-28T17:32:49Z'));
        $this->assertStringStartsWith('Europe/Rome (', Clock::label());
        $this->assertContains(Clock::abbreviation(), ['CET', 'CEST']);
    }

    #[Test]
    public function a_zone_without_an_abbreviation_is_named_by_its_offset(): void
    {
        $user = $this->admin(['timezone' => 'America/Sao_Paulo']);   // PHP knows no letters for it, only -03
        $this->actingAs($user);

        $this->assertSame('UTC-3', Clock::abbreviation());
        $this->assertSame('America/Sao_Paulo (UTC-3)', Clock::label());

        $this->actingAs($this->admin(['email' => 'k@cipi.local', 'timezone' => 'Asia/Kolkata']));
        $this->assertSame('IST', Clock::abbreviation());
        $this->assertSame('Asia/Kolkata (IST, UTC+5:30)', Clock::label());
    }

    #[Test]
    public function an_unknown_zone_is_refused_and_an_unknown_stored_value_reads_as_utc(): void
    {
        $user = $this->admin();
        $this->actingAs($user);

        Livewire::test(Settings::class)->set('timezone', 'Mars/Olympus_Mons')->call('saveProfile')->assertHasErrors(['timezone']);
        $this->assertNull($user->fresh()->timezone);

        $user->forceFill(['timezone' => 'Nowhere/Land'])->save();
        $this->assertSame('UTC', Clock::timezone($user->fresh()));
    }

    #[Test]
    public function the_profile_offers_the_zones_by_region_and_the_browser_shortcut(): void
    {
        $this->actingAs($this->admin());

        $this->get(route('cipi-gui.settings'))->assertOk()
            ->assertSeeHtml('<optgroup label="Europe">')
            ->assertSee('Europe/Rome')
            ->assertSee('Use my browser', false)
            ->assertSee('Accounts start on UTC');

        $zones = Clock::zones();
        $this->assertSame('UTC', array_key_first($zones));
        $this->assertContains('Europe/Rome', $zones['Europe']);
        $this->assertContains('America/New_York', $zones['America']);
    }
}
