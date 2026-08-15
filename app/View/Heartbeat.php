<?php

namespace App\View;

/**
 * The two numbers a drawn heart beats to: how long a beat lasts and how
 * much that length swings.
 *
 * A heart that ticks evenly shows a pulse, and a pulse is not what HRV
 * measures. HRV is the jitter between beats, so the beat is deliberately
 * uneven: the interval swings over a breathing cycle, the way a resting
 * heart actually does, and how far it swings is the reading. A
 * well-recovered morning visibly speeds up and slows down; a flat one runs
 * like a metronome. That is the direction the number moves in too, so the
 * picture and the figure cannot disagree.
 *
 * It lives here rather than in the scene that draws it (body3d.js)
 * because the drawing must not disagree with the printed number: the page
 * resolves it once beside the HRV card's reading and hands the stage two
 * data attributes, so the organ beats to the measurement the card shows.
 */
final class Heartbeat
{
    /** A beat this long when no resting pulse is on record: 60 bpm. */
    private const FALLBACK_MS = 1000;

    /** Below this a resting pulse is a bad reading, not a slow heart. */
    private const PULSE_FLOOR = 30;

    /**
     * The swing as a fraction of the interval, from the floor of the
     * personal band to its ceiling. The floor is not zero: a heart that
     * never varies at all reads as a stopped animation rather than as a
     * low reading.
     *
     * Both are exaggerated. Beat to beat the real spread is a few per cent
     * of the interval and would be invisible at any size either surface
     * draws. What is kept honest is the order, not the scale.
     */
    private const SWAY_FLOOR = 0.07;

    private const SWAY_REACH = 0.27;

    private function __construct(
        /** Mean length of one beat, in milliseconds. */
        public readonly int $interval,
        /** How far that length swings over the breathing cycle, 0 to 1. */
        public readonly float $sway,
    ) {}

    /**
     * Null without a value and without a band to read it against, for the
     * same reason the sparkline draws nothing below three points: an
     * animation running on a missing measurement invents one.
     */
    public static function from(
        ?float $hrv,
        ?float $low,
        ?float $high,
        ?float $restingHr,
    ): ?self {
        if ($hrv === null || $low === null || $high === null || $high <= $low) {
            return null;
        }

        // Where the value sits in the personal band, 0 at the floor and 1
        // at the ceiling. Outside it the beat stops changing rather than
        // running away: past the ceiling the surface's job is the status
        // word, not a livelier heart.
        $place = max(0.0, min(1.0, ($hrv - $low) / ($high - $low)));

        return new self(
            $restingHr !== null && $restingHr > self::PULSE_FLOOR
                ? (int) round(60000 / $restingHr)
                : self::FALLBACK_MS,
            round(self::SWAY_FLOOR + self::SWAY_REACH * $place, 3),
        );
    }
}
