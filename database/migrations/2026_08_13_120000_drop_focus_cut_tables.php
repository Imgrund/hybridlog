<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The focus cut (2026-08-13): the app keeps the body map, the load view
 * and the symptom layer; everything an earlier surface stored on its own
 * (nutrition, water, stressors, calendar mirrors, goals, cards, insights)
 * leaves the database with the features that read it. Capture happens in
 * Garmin, analysis happens in the chat against the mirror.
 *
 * Deliberately irreversible: the data these tables held was dumped before
 * this migration shipped, and re-created empty tables would only fake a
 * rollback. Restore that dump if the past is ever needed.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Children before parents: water_log and nutrition_log_items
        // both reference nutrition_log.
        Schema::dropIfExists('water_log');
        Schema::dropIfExists('nutrition_log_items');
        Schema::dropIfExists('nutrition_log');
        Schema::dropIfExists('nutrition_day_marks');
        Schema::dropIfExists('pinned_foods');
        Schema::dropIfExists('food_preferences');
        Schema::dropIfExists('stressor_log');
        Schema::dropIfExists('stress_prompts');
        Schema::dropIfExists('calendar_events');
        Schema::dropIfExists('calendar_days');
        Schema::dropIfExists('calendar_connections');
        Schema::dropIfExists('athlete_goals');
        Schema::dropIfExists('dashboard_cards');
        Schema::dropIfExists('insights');

        // The interface language is the one thing the app still asks the
        // athlete; body and cohort facts come from the watch.
        Schema::table('athlete_profiles', function (Blueprint $table) {
            $table->dropColumn([
                'sex', 'birth_year', 'weight_kg',
                'protein_min_g_per_kg', 'protein_max_g_per_kg',
            ]);
        });

        // Connector flags whose tools left in cuts A-D.
        Schema::table('connector_settings', function (Blueprint $table) {
            $table->dropColumn([
                'allow_cards', 'allow_insights', 'allow_nutrition',
                'allow_stressors', 'share_calendar_load', 'share_calendar_titles',
            ]);
        });
    }

    public function down(): void
    {
        throw new RuntimeException(
            'The focus cut is not reversible by migration; restore the pre-cut pg_dump instead.',
        );
    }
};
