<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\View\Components\BodyMap;
use Tests\TestCase;

/**
 * The card promises both silhouettes at one scale, and the caption pills
 * have to stay inside the box that is drawn. Both are geometry the browser
 * derives from the viewBox and the column widths, so the cases below pin
 * down the two numbers that decide it: every side's viewBox is exactly as
 * wide as the unit width the template sizes its column with, and only the
 * anterior pays for the caption margin.
 */
class BodyMapScaleTest extends TestCase
{
    /** @return array{x: float, y: float, w: float, h: float} */
    private function viewBox(BodyMap $map, string $side, string $key = 'viewBox'): array
    {
        [$x, $y, $w, $h] = array_map(floatval(...), explode(' ', $map->sides[$side][$key]));

        return ['x' => $x, 'y' => $y, 'w' => $w, 'h' => $h];
    }

    public function test_column_width_matches_view_box_width_on_both_sides(): void
    {
        $map = new BodyMap;

        // Equal scale is exactly this: the CSS box and the viewBox in the
        // same ratio on both sides. The template hands `unitWidth` to CSS,
        // so a viewBox that drifts from it would silently shrink one side.
        foreach (['anterior', 'posterior'] as $side) {
            $this->assertSame(
                $map->sides[$side]['unitWidth'],
                $this->viewBox($map, $side)['w'],
                "viewBox width and column width disagree on the {$side} side",
            );
        }

        $this->assertSame(
            $this->viewBox($map, 'anterior')['h'],
            $this->viewBox($map, 'posterior')['h'],
            'both sides need one unit height, or the shared scale breaks',
        );
    }

    public function test_only_the_anterior_reserves_room_for_captions(): void
    {
        $map = new BodyMap;

        // The posterior carries no pills. Giving it the margin anyway is
        // what used to scale the whole map down.
        $this->assertGreaterThan(
            $map->sides['posterior']['unitWidth'],
            $map->sides['anterior']['unitWidth'],
        );
    }

    public function test_the_thumbnail_box_keeps_the_figure_centred_at_one_scale(): void
    {
        $map = new BodyMap;

        // The thumbnail in the sheet head draws no pills, so it drops the
        // caption margin. What it must not drop is the shared scale or the
        // centre: the marker dots are placed relative to the figure, and a
        // box that sits off centre would park them beside the body.
        $this->assertSame(
            $this->viewBox($map, 'anterior', 'figureBox')['w'],
            $this->viewBox($map, 'posterior', 'figureBox')['w'],
            'both thumbnails need one width, or one silhouette comes out larger',
        );

        foreach (['anterior', 'posterior'] as $side) {
            $full = $this->viewBox($map, $side);
            $mini = $this->viewBox($map, $side, 'figureBox');

            $this->assertLessThanOrEqual($full['w'], $mini['w'], "the {$side} thumbnail still pays for captions");
            $this->assertSame($full['y'], $mini['y'], "the {$side} thumbnail sits at another height");
            $this->assertSame($full['h'], $mini['h'], "the {$side} thumbnail uses another scale");
            $this->assertEqualsWithDelta(
                $full['x'] + $full['w'] / 2,
                $mini['x'] + $mini['w'] / 2,
                0.1,
                "the {$side} thumbnail is off centre",
            );
        }
    }

    public function test_every_caption_pill_fits_inside_the_anterior_view_box(): void
    {
        $map = new BodyMap;
        $box = $this->viewBox($map, 'anterior');
        $longest = 'Critical';   // widest status word, see BodyMap::$statusLabels

        $this->assertContains($longest, $map->statusLabels);

        foreach ($map->findings as $key => $finding) {
            $width = $map->captionWidth($finding['name'], $longest);
            $x = $map->captionX($finding, $width);

            $this->assertGreaterThanOrEqual($box['x'], $x, "caption {$key} starts left of the viewBox");
            $this->assertLessThanOrEqual($box['x'] + $box['w'], $x + $width, "caption {$key} runs past the viewBox");
        }
    }
}
