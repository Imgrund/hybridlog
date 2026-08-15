<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use DOMDocument;
use DOMXPath;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * A card may only offer the expand affordance when the mirror holds a
 * second layer the card itself cannot show; a card without one must not
 * render a toggle. The expectations are read back out of the mirror with
 * the same rules the controller applies, so a rule that changes on one
 * side and not the other shows up here rather than in the browser.
 *
 * The mirror is a fixture, not the developer's own: read from a live one
 * these tests asserted whatever that machine happened to hold, which on
 * a fresh clone is nothing at all, and two of them skipped outright.
 */
class CardExpandTest extends TestCase
{
    use RefreshDatabase;

    private function garmin(): ConnectionInterface
    {
        return DB::connection('garmin');
    }

    /**
     * Enough of a mirror for both detail layers to have something to
     * show. Intensity is optional so that one test can still assert the
     * other half of the rule: a card whose layer has no data renders no
     * toggle, while the one beside it does.
     */
    private function seedDetailLayers(bool $withIntensity = true): void
    {
        $this->seedMirror('sleep', [[
            'date' => now()->subDay()->toDateString(),
            'duration_s' => 27000,
            'deep_s' => 5400,
            'light_s' => 16200,
            'rem_s' => 5400,
            'awake_s' => 900,
            'score' => 81,
        ]]);

        // Two sessions inside one ISO week, with loads that only round the
        // same way per session as in the sum when the controller rounds
        // before it adds. That is what the week-total test is about.
        $this->seedMirror('activities', [
            [
                'id' => 9001,
                'date' => now()->startOfWeek()->toDateString(),
                'type_key' => 'hiit',
                'name' => 'Metcon',
                'duration_s' => 3600.0,
                'avg_hr' => 148,
                'training_load' => 120.5,
            ],
            [
                'id' => 9002,
                'date' => now()->startOfWeek()->addDay()->toDateString(),
                'type_key' => 'strength_training',
                'name' => 'Kraft',
                'duration_s' => 2700.0,
                'avg_hr' => 121,
                'training_load' => 90.5,
            ],
        ]);

        if ($withIntensity) {
            $this->seedMirror('days', [[
                'date' => now()->toDateString(),
                'intensity_moderate_min' => 30,
                'intensity_vigorous_min' => 45,
            ]]);
        }
    }

    /** @return array<string, bool> detail id => the mirror supports it */
    private function expectedDetails(): array
    {
        $weekWindow = now()->startOfWeek()->subWeeks(3)->toDateString();

        $strengthSessions = $this->garmin()->table('activities')
            ->whereIn('type_key', ['hiit', 'strength_training', 'indoor_cardio', 'fitness_equipment'])
            ->where('date', '>=', $weekWindow)
            ->whereNotNull('training_load')
            ->where('training_load', '>', 0)
            ->exists();

        $intensityWeeks = $this->garmin()->table('days')
            ->where('date', '>=', $weekWindow)
            ->exists();

        return [
            'strength-sessions' => $strengthSessions,
            'intensity-split' => $intensityWeeks,
        ];
    }

    private function surfaceHtml(User $user): string
    {
        return $this->actingAs($user)->get('/')->assertStatus(200)->getContent();
    }

    public function test_expand_affordances_render_exactly_where_the_mirror_has_a_second_layer(): void
    {
        // Without intensity days, so both halves of the rule are under
        // test in the same render: one card grows a toggle, one does not.
        $this->seedDetailLayers(withIntensity: false);

        $expected = $this->expectedDetails();
        $this->assertFalse($expected['intensity-split']);
        $this->assertSame(1, count(array_filter($expected)));

        $html = $this->surfaceHtml($this->athlete());

        foreach ($expected as $id => $present) {
            if ($present) {
                $this->assertStringContainsString('aria-controls="detail-'.$id.'"', $html, "missing expand affordance for {$id}");
                $this->assertStringContainsString('id="detail-'.$id.'"', $html, "missing detail panel for {$id}");
            } else {
                $this->assertStringNotContainsString('detail-'.$id, $html, "unexpected expand affordance for {$id}");
            }
        }

        // No card outside the two decided ones may grow a toggle: the
        // total affordance count equals the expected count.
        $this->assertSame(
            count(array_filter($expected)),
            substr_count($html, 'aria-controls="detail-'),
            'a card outside the decided set renders an expand affordance'
        );
    }

    public function test_expanded_layers_show_information_the_cards_do_not(): void
    {
        $this->seedDetailLayers();

        $expected = $this->expectedDetails();
        $this->assertSame(2, count(array_filter($expected)));

        $html = $this->surfaceHtml($this->athlete());

        if ($expected['strength-sessions']) {
            $this->assertStringContainsString('Show Sessions', $html);
            $this->assertStringContainsString('Avg HR', $html);
        }
        if ($expected['intensity-split']) {
            $this->assertStringContainsString('Show Breakdown', $html);
            $this->assertStringContainsString('Counted', $html);
            $this->assertStringContainsString('WHO count', $html);
        }
    }

    public function test_a_week_total_equals_the_sessions_printed_under_it(): void
    {
        $this->seedDetailLayers();

        $response = $this->actingAs($this->athlete())->get('/');
        $response->assertStatus(200);

        $document = new DOMDocument;
        libxml_use_internal_errors(true);
        $document->loadHTML($response->getContent());
        libxml_clear_errors();

        $panel = (new DOMXPath($document))->query('//*[@id="detail-strength-sessions"]//tbody');
        $this->assertGreaterThan(0, $panel->length, 'the sessions panel renders no week');

        foreach ($panel as $week) {
            $rows = $week->getElementsByTagName('tr');
            $header = $rows->item(0)->textContent;
            $stated = (int) str_replace('.', '', explode('Load ', $header)[1]);

            $listed = 0;
            for ($i = 1; $i < $rows->length; $i++) {
                $cells = $rows->item($i)->getElementsByTagName('td');
                $listed += (int) str_replace('.', '', $cells->item(2)->textContent);
            }

            // The panel exists to show the sessions behind a bar, so a
            // week header that disagrees with its own rows is the one
            // failure it cannot survive. Rounding therefore happens per
            // session, before the sum, in the detail and the chart alike.
            $this->assertSame($stated, $listed, "week header \"{$header}\" disagrees with the sessions under it");
        }
    }

    public function test_toggles_ship_collapsed_with_real_disclosure_semantics(): void
    {
        $this->seedDetailLayers();

        $expected = array_filter($this->expectedDetails());

        $html = $this->surfaceHtml($this->athlete());

        // Every toggle is a real button that ships collapsed server-side;
        // every panel is a named region the button points at.
        $this->assertSame(
            count($expected),
            substr_count($html, 'class="expand-toggle" x-ref="toggle"'),
            'toggle count and detail count diverge'
        );
        $this->assertGreaterThanOrEqual(
            count($expected),
            substr_count($html, 'aria-expanded="false"'),
            'a toggle ships without a collapsed aria-expanded default'
        );
        $this->assertSame(
            count($expected),
            substr_count($html, 'class="card-detail"'),
            'panel count and detail count diverge'
        );
    }
}
