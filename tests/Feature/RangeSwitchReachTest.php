<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The range switch offers four windows and the mirror is not always old
 * enough for all of them. What it does then is a decision, not a detail: a
 * stage short of history is disabled and says so, never dropped, because the
 * shortfall cures itself as days accumulate and a removed stage would still
 * be gone the year it finally had something to show.
 *
 * Asserted on /training rather than /, because the today surface carries no
 * range switch at all: its cards answer for the day and have no window to set.
 */
class RangeSwitchReachTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Fills the mirror with one row per day, ending today.
     *
     * Through seedMirror rather than a plain insert, so the next test gets
     * the schema rebuilt under it: the mirror sits outside the transaction
     * RefreshDatabase rolls back, and rows left behind would decide the
     * following test's answer.
     */
    private function mirrorDays(int $count): void
    {
        $rows = [];

        for ($i = 0; $i < $count; $i++) {
            $rows[] = [
                'date' => now()->subDays($i)->toDateString(),
                'steps' => 8000,
            ];
        }

        $this->seedMirror('days', $rows);
    }

    private function training(): string
    {
        return $this->actingAs($this->athlete())->get('/')
            ->assertOk()
            ->getContent();
    }

    public function test_a_stage_the_mirror_cannot_fill_is_disabled_and_names_the_reason(): void
    {
        // Enough for 7, 30 and 90, short of a year.
        $this->mirrorDays(125);

        $html = $this->training();

        $this->assertMatchesRegularExpression(
            '/id="range-365"[^>]*\sdisabled/',
            $html,
            'The year stage should be disabled on a mirror that holds 125 days.'
        );
        $this->assertStringContainsString('the mirror holds 125 days', $html);

        foreach ([7, 30, 90] as $reachable) {
            $this->assertDoesNotMatchRegularExpression(
                '/id="range-'.$reachable.'"[^>]*\sdisabled/',
                $html,
                "The $reachable day stage fits in 125 days and should stay selectable."
            );
        }
    }

    public function test_the_stage_is_offered_rather_than_removed(): void
    {
        $this->mirrorDays(125);

        $html = $this->training();

        // Disabled, but still on the page and still in the allowlist the
        // client validates against: it comes back by itself.
        $this->assertStringContainsString('id="range-365"', $html);
        $this->assertStringContainsString('"rangeOptions":[7,30,90,365]', $html);
    }

    public function test_the_dashboard_opens_on_a_window_it_can_draw(): void
    {
        // Past 30, short of the usual 90 default.
        $this->mirrorDays(45);

        $html = $this->training();

        $this->assertStringContainsString('"range":30', $html);
        $this->assertMatchesRegularExpression('/id="range-90"[^>]*\sdisabled/', $html);
    }

    public function test_a_mirror_too_young_for_any_stage_gates_nothing(): void
    {
        // Three days cannot fill even the shortest window. Disabling every
        // stage would leave a control nobody can operate, so none are.
        $this->mirrorDays(3);

        $html = $this->training();

        foreach ([7, 30, 90, 365] as $stage) {
            $this->assertDoesNotMatchRegularExpression(
                '/id="range-'.$stage.'"[^>]*\sdisabled/',
                $html,
                "Nothing is gated while no stage is within reach, including $stage."
            );
        }
    }

    public function test_an_empty_mirror_gates_nothing_either(): void
    {
        $html = $this->training();

        $this->assertDoesNotMatchRegularExpression('/id="range-365"[^>]*\sdisabled/', $html);
        $this->assertStringContainsString('"range":90', $html);
    }
}
