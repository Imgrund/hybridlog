<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Single-row profile of the one athlete this dashboard belongs to.
 *
 * It carries the two facts the published cohort norms (FRIEND, NHANES)
 * need and the Garmin mirror does not reliably supply. Both are entered
 * in the dashboard itself and stored here: what belongs to the athlete
 * is theirs to edit, not a deployment setting.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('athlete_profiles', function (Blueprint $table) {
            $table->id();

            // 'male' or 'female'. Null is a real answer, not a missing
            // one: the sources publish these two bands only, so anything
            // else has to switch the cohort rows off instead of being
            // pressed into a band that is not the athlete's.
            $table->string('sex')->nullable();

            // Fallback for the age only. Garmin's own chronological age
            // wins whenever the mirror carries one.
            $table->unsignedSmallInteger('birth_year')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('athlete_profiles');
    }
};
