<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The second-metric overlay is curated, not configurable: exactly the
 * decided chart cards carry the control, each with its short list of
 * coach-paired metrics, and every control ships as a real radiogroup
 * in the off state, so the server-rendered page looks exactly as it
 * did before the feature existed.
 */
class ChartOverlayTest extends TestCase
{
    use RefreshDatabase;

    /** chart id => the curated pair labels behind the "Off" segment */
    private const EXPECTED = [
        'chart-hrv' => ['Fatigue', 'Resting HR'],
        'chart-strength-load' => ['Intensity'],
        'chart-intensity' => ['Strength load'],
    ];

    /** Charts that must never grow the control. */
    private const EXCLUDED = ['chart-pmc'];

    private function dashboard(): DOMXPath
    {
        $user = User::factory()->create();
        $training = $this->actingAs($user)->get('/')->assertStatus(200)->getContent();

        $document = new DOMDocument;
        libxml_use_internal_errors(true);
        // The encoding hint keeps non-ASCII characters intact, whatever
        // meta-charset detection the local libxml ships.
        $document->loadHTML('<?xml encoding="utf-8"?><main>'.$training.'</main>');
        libxml_clear_errors();

        return new DOMXPath($document);
    }

    public function test_controls_render_exactly_on_the_curated_cards(): void
    {
        $xpath = $this->dashboard();

        $found = [];
        foreach ($xpath->query('//*[@data-overlay-switch]') as $node) {
            /** @var DOMElement $node */
            $found[] = $node->getAttribute('data-overlay-switch');
        }
        sort($found);

        $expected = array_keys(self::EXPECTED);
        sort($expected);

        // The exact curated set and nothing else: a chart outside the
        // decided ones must not grow the control, a decided one must
        // not lose it.
        $this->assertSame($expected, $found, 'the overlay controls diverge from the curated set');

        foreach (self::EXCLUDED as $chartId) {
            $this->assertNotContains($chartId, $found, "{$chartId} must not carry an overlay control");
        }
    }

    public function test_each_control_offers_its_curated_pairs_and_ships_off(): void
    {
        $xpath = $this->dashboard();

        foreach (self::EXPECTED as $chartId => $labels) {
            $radios = $xpath->query('//*[@data-overlay-switch="'.$chartId.'"]//button[@role="radio"]');

            // "Off" plus exactly the curated metrics, no free picker.
            $this->assertSame(count($labels) + 1, $radios->length, "{$chartId} offers a different number of choices than curated");

            $texts = [];
            $checked = [];
            $tabbable = [];
            foreach ($radios as $radio) {
                /** @var DOMElement $radio */
                $texts[] = trim($radio->textContent);
                $this->assertSame('button', $radio->getAttribute('type'), "{$chartId}: a radio is not a real button");
                if ($radio->getAttribute('aria-checked') === 'true') {
                    $checked[] = trim($radio->textContent);
                }
                if ($radio->getAttribute('tabindex') === '0') {
                    $tabbable[] = trim($radio->textContent);
                }
            }

            // Default state is off: the chart looks exactly as it does
            // today until an overlay is asked for.
            $this->assertSame(['Off'], $checked, "{$chartId} does not ship with only \"Off\" checked");
            $this->assertSame(['Off'], $tabbable, "{$chartId} breaks the roving tabindex of the radiogroup");
            $this->assertSame(array_merge(['Off'], $labels), $texts, "{$chartId} offers different pairs than curated");
        }
    }

    public function test_controls_carry_real_radiogroup_semantics_with_accessible_names(): void
    {
        $xpath = $this->dashboard();

        foreach (array_keys(self::EXPECTED) as $chartId) {
            $groups = $xpath->query('//*[@data-overlay-switch="'.$chartId.'"]//*[@role="radiogroup"]');
            $this->assertSame(1, $groups->length, "{$chartId} renders no single radiogroup");

            /** @var DOMElement $group */
            $group = $groups->item(0);
            $name = $group->getAttribute('aria-label');
            // The accessible name carries the visible label word and the
            // card it belongs to, so the controls never read alike.
            $this->assertStringContainsString('Overlay a second metric', $name, "{$chartId}: accessible name misses the visible label word");
            $this->assertNotSame('Overlay a second metric: ', $name, "{$chartId}: accessible name misses the card name");
        }
    }

    public function test_the_off_state_adds_no_expand_affordance_or_second_axis_markup(): void
    {
        $user = User::factory()->create();
        $html = $this->actingAs($user)->get('/')->assertStatus(200)->getContent();

        // The control must never read as a card-expand toggle to the
        // disclosure contract CardExpandTest pins down.
        $this->assertSame(0, substr_count($html, 'ov-seg" role="radio" aria-controls'), 'an overlay radio claims an aria-controls relation');
        foreach (array_keys(self::EXPECTED) as $chartId) {
            $this->assertStringNotContainsString('detail-'.$chartId, $html, "{$chartId} leaks into the expand namespace");
        }
    }
}
