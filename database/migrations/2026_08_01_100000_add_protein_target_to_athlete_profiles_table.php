<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The athlete's own protein target, and the body weight it is multiplied
 * by when the mirror carries none.
 *
 * All three stay nullable, and null is a decision rather than a gap: with
 * no band entered the published one in App\Nutrition\FuelRequirement keeps
 * deciding, picked per day from the training minutes. A default here would
 * quietly replace a requirement derived from a position stand with a number
 * nobody chose.
 *
 * Grams per kilogram is the stored unit, not grams: the target follows the
 * body when it changes, which a fixed gram figure entered once would not.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('athlete_profiles', function (Blueprint $table) {
            // 3,2 holds 0.00 to 9.99 g/kg, which covers every published
            // band with room above it; the form is what keeps the value
            // inside something sane.
            $table->decimal('protein_min_g_per_kg', 3, 2)->nullable();
            $table->decimal('protein_max_g_per_kg', 3, 2)->nullable();

            // The fallback for a mirror without a body-composition row:
            // scales, not watches, report weight, and not every athlete
            // owns the scale. Stored in kilograms, unlike the mirror's
            // grams, because this one is typed by a human.
            $table->decimal('weight_kg', 5, 2)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('athlete_profiles', function (Blueprint $table) {
            $table->dropColumn(['protein_min_g_per_kg', 'protein_max_g_per_kg', 'weight_kg']);
        });
    }
};
