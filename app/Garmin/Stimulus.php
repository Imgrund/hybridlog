<?php

namespace App\Garmin;

/**
 * Which of the four stimulus kinds an activity type belongs to. One
 * shared answer, because three surfaces read it: the weekly load split,
 * the training-effect map and the goals layer's run share. Two lists
 * drifting apart would let a hint and a chart disagree about what
 * counted as running.
 */
class Stimulus
{
    /**
     * The strength list matches the weekly load card, so the cards
     * cannot disagree about what counts as a strength session.
     */
    public static function bucket(?string $type): string
    {
        return match (true) {
            in_array($type, ['running', 'trail_running', 'treadmill_running'], true) => 'run',
            in_array($type, ['hiit', 'strength_training', 'indoor_cardio', 'fitness_equipment'], true) => 'strength',
            $type === 'multi_sport' => 'combo',
            default => 'other',
        };
    }
}
