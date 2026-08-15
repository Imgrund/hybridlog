<?php

/*
 * Maps Garmin Connect strength-exercise categories (47 enums, see
 * garminconnect.exercises) onto the body-map muscle zones. Weights express
 * how strongly a set of that category loads the zone (1.0 = primary mover).
 *
 * Zone keys follow the SVG polygon data (resources/data/body-polygons.json,
 * MIT-licensed polygons from the body-highlighter project). SOLEUS zones are
 * folded into CALVES, ABDUCTOR (posterior) into ABDUCTORS.
 */

return [

    'zones' => [
        'CHEST', 'FRONT_DELTOIDS', 'BACK_DELTOIDS', 'BICEPS', 'TRICEPS',
        'FOREARM', 'ABS', 'OBLIQUES', 'QUADRICEPS', 'HAMSTRING', 'GLUTEAL',
        'CALVES', 'TRAPEZIUS', 'UPPER_BACK', 'LOWER_BACK', 'ABDUCTORS',
    ],

    'categories' => [
        'BANDED_EXERCISES' => ['GLUTEAL' => 0.5, 'ABDUCTORS' => 0.5, 'FRONT_DELTOIDS' => 0.3, 'ABS' => 0.3],
        'BATTLE_ROPE' => ['FRONT_DELTOIDS' => 1.0, 'BACK_DELTOIDS' => 0.5, 'FOREARM' => 0.5, 'ABS' => 0.5, 'UPPER_BACK' => 0.4],
        'BENCH_PRESS' => ['CHEST' => 1.0, 'TRICEPS' => 0.6, 'FRONT_DELTOIDS' => 0.5],
        'CALF_RAISE' => ['CALVES' => 1.0],
        'CARRY' => ['FOREARM' => 1.0, 'TRAPEZIUS' => 0.7, 'ABS' => 0.5, 'GLUTEAL' => 0.3, 'QUADRICEPS' => 0.3],
        'CHOP' => ['OBLIQUES' => 1.0, 'ABS' => 0.6, 'FRONT_DELTOIDS' => 0.3],
        'CORE' => ['ABS' => 1.0, 'OBLIQUES' => 0.6, 'LOWER_BACK' => 0.4],
        'CRUNCH' => ['ABS' => 1.0, 'OBLIQUES' => 0.3],
        'CURL' => ['BICEPS' => 1.0, 'FOREARM' => 0.4],
        'DEADLIFT' => ['HAMSTRING' => 1.0, 'GLUTEAL' => 1.0, 'LOWER_BACK' => 0.8, 'TRAPEZIUS' => 0.5, 'FOREARM' => 0.5, 'QUADRICEPS' => 0.4],
        'FLYE' => ['CHEST' => 1.0, 'FRONT_DELTOIDS' => 0.3],
        'HIP_RAISE' => ['GLUTEAL' => 1.0, 'HAMSTRING' => 0.5, 'ABS' => 0.3],
        'HIP_STABILITY' => ['ABDUCTORS' => 1.0, 'GLUTEAL' => 0.6],
        'HIP_SWING' => ['GLUTEAL' => 1.0, 'HAMSTRING' => 0.8, 'LOWER_BACK' => 0.5, 'ABS' => 0.4, 'FRONT_DELTOIDS' => 0.3],
        'HYPEREXTENSION' => ['LOWER_BACK' => 1.0, 'GLUTEAL' => 0.6, 'HAMSTRING' => 0.5],
        'LATERAL_RAISE' => ['FRONT_DELTOIDS' => 1.0, 'BACK_DELTOIDS' => 0.3, 'TRAPEZIUS' => 0.3],
        'LEG_CURL' => ['HAMSTRING' => 1.0, 'CALVES' => 0.3],
        'LEG_RAISE' => ['ABS' => 1.0, 'OBLIQUES' => 0.3, 'QUADRICEPS' => 0.3],
        'LUNGE' => ['QUADRICEPS' => 1.0, 'GLUTEAL' => 0.8, 'HAMSTRING' => 0.4, 'CALVES' => 0.3],
        'OLYMPIC_LIFT' => ['GLUTEAL' => 1.0, 'QUADRICEPS' => 0.8, 'HAMSTRING' => 0.8, 'TRAPEZIUS' => 0.8, 'LOWER_BACK' => 0.6, 'FRONT_DELTOIDS' => 0.6, 'FOREARM' => 0.5, 'CALVES' => 0.4],
        'PLANK' => ['ABS' => 1.0, 'OBLIQUES' => 0.6, 'LOWER_BACK' => 0.3, 'FRONT_DELTOIDS' => 0.3],
        'PLYO' => ['QUADRICEPS' => 1.0, 'GLUTEAL' => 0.8, 'CALVES' => 0.6, 'HAMSTRING' => 0.5],
        'PULL_UP' => ['UPPER_BACK' => 1.0, 'BICEPS' => 0.7, 'FOREARM' => 0.5, 'BACK_DELTOIDS' => 0.4, 'TRAPEZIUS' => 0.4],
        'PUSH_UP' => ['CHEST' => 1.0, 'TRICEPS' => 0.6, 'FRONT_DELTOIDS' => 0.5, 'ABS' => 0.3],
        'ROW' => ['UPPER_BACK' => 1.0, 'BICEPS' => 0.6, 'BACK_DELTOIDS' => 0.6, 'TRAPEZIUS' => 0.5, 'FOREARM' => 0.4, 'LOWER_BACK' => 0.3],
        'SANDBAG' => ['QUADRICEPS' => 0.7, 'GLUTEAL' => 0.7, 'LOWER_BACK' => 0.6, 'FOREARM' => 0.6, 'ABS' => 0.5, 'UPPER_BACK' => 0.4],
        'SHOULDER_PRESS' => ['FRONT_DELTOIDS' => 1.0, 'TRICEPS' => 0.6, 'TRAPEZIUS' => 0.4],
        'SHOULDER_STABILITY' => ['BACK_DELTOIDS' => 0.8, 'FRONT_DELTOIDS' => 0.4, 'TRAPEZIUS' => 0.4],
        'SHRUG' => ['TRAPEZIUS' => 1.0, 'FOREARM' => 0.3],
        'SIT_UP' => ['ABS' => 1.0, 'OBLIQUES' => 0.4],
        'SLED' => ['QUADRICEPS' => 1.0, 'GLUTEAL' => 0.8, 'CALVES' => 0.6, 'CHEST' => 0.3, 'LOWER_BACK' => 0.3],
        'SLEDGE_HAMMER' => ['OBLIQUES' => 1.0, 'ABS' => 0.6, 'FOREARM' => 0.6, 'FRONT_DELTOIDS' => 0.5, 'UPPER_BACK' => 0.4],
        'SQUAT' => ['QUADRICEPS' => 1.0, 'GLUTEAL' => 0.9, 'HAMSTRING' => 0.5, 'LOWER_BACK' => 0.4, 'CALVES' => 0.3, 'ABS' => 0.3],
        'SUSPENSION' => ['ABS' => 0.6, 'UPPER_BACK' => 0.5, 'CHEST' => 0.5, 'FRONT_DELTOIDS' => 0.4],
        'TIRE' => ['GLUTEAL' => 0.8, 'QUADRICEPS' => 0.8, 'LOWER_BACK' => 0.6, 'FOREARM' => 0.5, 'FRONT_DELTOIDS' => 0.4],
        'TOTAL_BODY' => ['QUADRICEPS' => 0.4, 'GLUTEAL' => 0.4, 'CHEST' => 0.4, 'UPPER_BACK' => 0.4, 'ABS' => 0.4, 'FRONT_DELTOIDS' => 0.4],
        'TRICEPS_EXTENSION' => ['TRICEPS' => 1.0],
        'WARM_UP' => [],

        // Cardio-machine categories inside strength workouts: leg-dominant.
        'BIKE_OUTDOOR' => ['QUADRICEPS' => 0.5, 'CALVES' => 0.3, 'GLUTEAL' => 0.3],
        'INDOOR_BIKE' => ['QUADRICEPS' => 0.5, 'CALVES' => 0.3, 'GLUTEAL' => 0.3],
        'ELLIPTICAL' => ['QUADRICEPS' => 0.4, 'GLUTEAL' => 0.3, 'CALVES' => 0.3],
        'STAIR_STEPPER' => ['QUADRICEPS' => 0.6, 'GLUTEAL' => 0.5, 'CALVES' => 0.4],
        'FLOOR_CLIMB' => ['QUADRICEPS' => 0.6, 'GLUTEAL' => 0.5, 'CALVES' => 0.4],
        'LADDER' => ['QUADRICEPS' => 0.4, 'CALVES' => 0.4, 'FOREARM' => 0.4],
        'RUN' => ['QUADRICEPS' => 0.5, 'HAMSTRING' => 0.4, 'CALVES' => 0.5, 'GLUTEAL' => 0.3],
        'RUN_INDOOR' => ['QUADRICEPS' => 0.5, 'HAMSTRING' => 0.4, 'CALVES' => 0.5, 'GLUTEAL' => 0.3],
        'CARDIO' => ['QUADRICEPS' => 0.3, 'CALVES' => 0.3, 'ABS' => 0.2],
    ],

    /*
     * Exercise-level overrides, checked before the category. Garmin stores
     * a specific variant next to the category (strength_sets.exercise_name)
     * and for some pairings the category is simply the wrong profile: an
     * INDOOR_ROW filed under ROW is a rowing machine, which is leg-driven,
     * while the ROW category means a bent-over row. Whatever is not named
     * here keeps its category mapping, so this list is additive and safe
     * to extend one verified pairing at a time.
     *
     * Every entry below was observed in this athlete's own mirror; do not
     * add speculative ones, an override that never fires is dead weight
     * and an override with a guessed profile is worse than the category.
     */
    'exercises' => [
        // Category ROW assumes a bent-over row; the machine is a leg press
        // with a pull attached.
        'INDOOR_ROW' => ['QUADRICEPS' => 0.7, 'UPPER_BACK' => 0.7, 'HAMSTRING' => 0.5, 'BICEPS' => 0.4, 'LOWER_BACK' => 0.4],
        // Category CARDIO spreads thin and misses that this is a calf drill.
        'JUMP_ROPE' => ['CALVES' => 1.0, 'QUADRICEPS' => 0.3, 'FOREARM' => 0.2],
        // Category TOTAL_BODY spreads one flat share over six zones.
        'BURPEE' => ['QUADRICEPS' => 0.7, 'CHEST' => 0.7, 'FRONT_DELTOIDS' => 0.6, 'TRICEPS' => 0.5, 'ABS' => 0.5],
        // Category TRICEPS_EXTENSION ignores that dips are a press.
        'BENCH_DIP' => ['TRICEPS' => 1.0, 'CHEST' => 0.5, 'FRONT_DELTOIDS' => 0.4],
        // Category PLYO is already leg-dominant; this sharpens the landing.
        'BOX_JUMP' => ['QUADRICEPS' => 1.0, 'GLUTEAL' => 0.8, 'CALVES' => 0.6, 'HAMSTRING' => 0.4],
    ],

    /*
     * Whole activities without exercise sets still fatigue muscles. Their
     * training load is spread over these zones (same self-normalizing scale
     * as set volume, so absolute units cancel out).
     */
    'activity_types' => [
        'running' => ['QUADRICEPS' => 0.5, 'HAMSTRING' => 0.4, 'CALVES' => 0.5, 'GLUTEAL' => 0.3],
        'trail_running' => ['QUADRICEPS' => 0.6, 'HAMSTRING' => 0.4, 'CALVES' => 0.6, 'GLUTEAL' => 0.4],
        'treadmill_running' => ['QUADRICEPS' => 0.5, 'HAMSTRING' => 0.4, 'CALVES' => 0.5, 'GLUTEAL' => 0.3],
        'cycling' => ['QUADRICEPS' => 0.6, 'CALVES' => 0.3, 'GLUTEAL' => 0.4],
        'indoor_rowing' => ['UPPER_BACK' => 0.6, 'QUADRICEPS' => 0.5, 'HAMSTRING' => 0.4, 'BICEPS' => 0.4, 'LOWER_BACK' => 0.4],
        'hiit' => ['QUADRICEPS' => 0.5, 'GLUTEAL' => 0.5, 'CHEST' => 0.4, 'FRONT_DELTOIDS' => 0.4, 'ABS' => 0.4, 'UPPER_BACK' => 0.3],
        'indoor_cardio' => ['QUADRICEPS' => 0.4, 'GLUTEAL' => 0.4, 'ABS' => 0.3, 'FRONT_DELTOIDS' => 0.3],
        'strength_training' => ['QUADRICEPS' => 0.4, 'GLUTEAL' => 0.4, 'CHEST' => 0.4, 'UPPER_BACK' => 0.4, 'ABS' => 0.4, 'FRONT_DELTOIDS' => 0.4],
        'pilates' => ['ABS' => 0.6, 'OBLIQUES' => 0.4, 'LOWER_BACK' => 0.3, 'GLUTEAL' => 0.3],
    ],

    /*
     * Freshness decay: accumulated load halves every N hours. The fallback
     * applies to any zone the table below does not name.
     */
    'half_life_hours' => 28,

    /*
     * Per-zone decay. One global constant let the quadriceps and the
     * triceps recover at the same speed, which the literature separates by
     * a factor of two to three: 48 to 72 h for the lower body and for
     * multi-joint work, 24 h or less for small upper-body muscles, driven
     * by eccentric share, muscle length and the amount of muscle recruited
     * rather than by muscle size (review of resistance-training microcycle
     * construction, PMC11057610; 10-RM reproducibility at 24/48/72 h,
     * PMC6719818). A 36 h half-life leaves ~25 % of the load after 72 h,
     * which is the "about three quarters recovered" the 48-to-72 h window
     * describes.
     *
     * These numbers are calibrated heuristics, not measured constants:
     * published per-muscle half-lives do not exist, and the athlete's own
     * data carries no per-zone performance measure to fit them against.
     * Fitting them would be worse, not better: the Banister model's decay
     * constants are famously unidentifiable, with bootstrap intervals
     * spanning the whole plausible range and a near-perfect correlation
     * between the two constants (Hellard et al., PMC1974899). A fixed,
     * documented constant is the honest option; the methodology page says
     * so in as many words.
     */
    'zone_half_life_hours' => [
        // Lower body and trunk extensors: multi-joint, high eccentric share.
        'QUADRICEPS' => 38,
        'GLUTEAL' => 38,
        'HAMSTRING' => 38,
        'LOWER_BACK' => 36,

        // Calves sit above the other small muscles: running loads them
        // eccentrically for hours, not for sets.
        'CALVES' => 28,

        // Torso and shoulder girdle.
        'CHEST' => 26,
        'UPPER_BACK' => 26,
        'TRAPEZIUS' => 26,
        'FRONT_DELTOIDS' => 26,
        'BACK_DELTOIDS' => 24,
        'ABS' => 24,
        'OBLIQUES' => 24,
        'ABDUCTORS' => 26,

        // Small single-joint arm muscles recover fastest.
        'BICEPS' => 22,
        'TRICEPS' => 22,
        'FOREARM' => 20,
    ],

    // Days of history used to self-calibrate "100% loaded" per zone.
    'calibration_days' => 90,

    /*
     * Where a reported symptom lands on the map. Athletes report joints
     * ("knee", "ankle"), the map draws muscles, so a joint region is
     * approximated by the zone that sits closest to it. The log keeps the
     * region that was actually reported; only the drawing approximates,
     * and the panel says so.
     *
     * The region vocabulary follows the OSTRC overuse questionnaire, the
     * validated instrument for registering complaints per body region
     * (Clarsen et al. 2013, updated 2020). Muscle zones map to themselves
     * and are omitted here.
     */
    'symptom_regions' => [
        'KNEE' => 'QUADRICEPS',
        'HIP' => 'GLUTEAL',
        'GROIN' => 'ABDUCTORS',
        'ANKLE' => 'CALVES',
        'FOOT' => 'CALVES',
        'ACHILLES' => 'CALVES',
        'SHIN' => 'CALVES',
        'SHOULDER' => 'FRONT_DELTOIDS',
        'ELBOW' => 'TRICEPS',
        'WRIST' => 'FOREARM',
        'HAND' => 'FOREARM',
        'NECK' => 'TRAPEZIUS',
        'UPPER_ARM' => 'BICEPS',
    ],

    /*
     * Weekly set corridor per zone, the landmark the zone detail reports
     * against. The dose-response meta-regression behind it counts
     * fractional sets (direct work 1.0, indirect 0.5), which is exactly
     * what the weight matrix above expresses (Pelland et al., Sports
     * Medicine, PMID 41343037; Schoenfeld's earlier dose-response work put
     * the useful range at 10+ weekly sets). Hypertrophy keeps rising with
     * volume at a diminishing return, so the upper number is a soft
     * marker, not a ceiling, and the lower one is where the evidence
     * starts calling a zone under-stimulated.
     *
     * MEV/MAV/MRV are deliberately absent: their own authors call the
     * numbers starting points rather than findings, and MRV has never been
     * measured directly.
     */
    'weekly_set_corridor' => ['low' => 10, 'high' => 20],
];
