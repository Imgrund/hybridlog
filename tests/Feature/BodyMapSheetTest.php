<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * On a phone the body map is taller than the screen, so a detail that opens
 * below it would push the body out of sight and leave the reader guessing
 * which part they just tapped. The detail therefore rides in a sheet over
 * the lower edge, and these tests hold what makes that sheet usable: the
 * map stays on screen, the sheet says what is open and how to close it, the
 * zone list stays walkable underneath, and the small zones keep a named way
 * out to the list further down.
 */
class BodyMapSheetTest extends TestCase
{
    use RefreshDatabase;

    private function render(): string
    {
        return $this->actingAs(User::factory()->create())->get('/')->getContent();
    }

    public function test_the_detail_turns_into_a_sheet_only_where_the_map_needs_the_room(): void
    {
        $html = $this->render();

        // One source of truth for the breakpoint: `narrow` comes from a
        // media query in JS. A second one in CSS would drift, and the sheet
        // would go fixed while the scroll nudge still thought otherwise.
        $this->assertStringContainsString("narrow: window.matchMedia('(max-width: 1023px)').matches", $html);
        $this->assertStringContainsString("x-bind:class=\"{ 'bm-sheet': narrow && sel }\"", $html);
    }

    public function test_the_sheet_names_what_is_open_and_offers_a_way_out(): void
    {
        $html = $this->render();

        $this->assertStringContainsString('class="bm-sheet-head"', $html);
        $this->assertStringContainsString("x-text=\"selSystem?.label ?? selZone?.label ?? ''\"", $html);
        $this->assertStringContainsString('aria-label="'.__('Close the detail').'"', $html);

        // The head repeats the title, so the heading in the detail below it
        // steps aside while the sheet is up.
        $this->assertStringContainsString('x-show="!narrow"', $html);
    }

    public function test_the_thumbnail_shows_both_sides_and_marks_the_selection(): void
    {
        $html = $this->render();

        $this->assertStringContainsString('class="bm-mini"', $html);
        $this->assertSame(2, substr_count($html, 'class="bm-mini-fig"'), 'the thumbnail needs front and back');
        $this->assertStringContainsString("'bm-mini-on': sel === 'QUADRICEPS'", $html);
        $this->assertStringContainsString('class="bm-mini-dot"', $html);
    }

    public function test_the_zone_list_stays_put_while_a_detail_is_open(): void
    {
        $html = $this->render();

        // The list is the navigation between findings. On a phone it is the
        // only way to reach a zone the finger cannot hit, so it may not
        // disappear on the tap that used it.
        $this->assertStringContainsString('x-show="!sel || narrow"', $html);
        $this->assertStringContainsString('x-on:click="toggle(key, $event.currentTarget)"', $html);
        $this->assertStringContainsString("x-bind:aria-pressed=\"sel === key ? 'true' : 'false'\"", $html);
    }

    public function test_the_hint_under_the_map_leads_to_the_zone_list(): void
    {
        $html = $this->render();

        $this->assertStringContainsString('href="#bm-zones"', $html);
        $this->assertStringContainsString('id="bm-zones"', $html);
    }
}
