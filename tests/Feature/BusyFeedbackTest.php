<?php

namespace CipiGui\Tests\Feature;

use CipiGui\Support\Theme;
use CipiGui\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * 4.1.1 — every button that calls the server shows that it is working: one
 * Livewire action interceptor in the app layout (partials/busy-script) and
 * the styles it relies on, shipped inline with the theme.
 */
class BusyFeedbackTest extends TestCase
{
    #[Test]
    public function the_app_layout_ships_the_busy_interceptor_once_with_its_styles(): void
    {
        $html = $this->actingAs($this->admin())->get('/')->assertOk()->getContent();

        $this->assertSame(1, substr_count($html, 'data-cipi-busy-script'), 'busy-script is included exactly once');
        $this->assertStringContainsString('Livewire.interceptAction(', $html);
        $this->assertStringContainsString("if (type !== 'click' && type !== 'submit') return;", $html, 'only clicks and submits, never polls or model updates');
        $this->assertStringContainsString("wire:loading", $html, 'buttons with their own loading state are left to Livewire');
        $this->assertStringContainsString('[data-busy] { pointer-events: none;', $html, 'the stylesheet carries the busy rules');
        $this->assertStringContainsString('.busy-visible > .busy-spinner', $html);
        $this->assertStringContainsString('cipi-gui v'.Theme::VERSION, $html);
    }

    #[Test]
    public function the_guest_layout_does_not_load_it(): void
    {
        $this->get('/login')->assertOk()->assertDontSee('data-cipi-busy-script', false);
    }

    #[Test]
    public function the_version_is_4_1_1(): void
    {
        $this->assertSame('4.1.1', Theme::VERSION);
        $this->assertStringContainsString('## [4.1.1]', file_get_contents(Theme::packageRoot().'/CHANGELOG.md'));
    }
}
