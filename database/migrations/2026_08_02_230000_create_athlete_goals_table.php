<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Single-row goals of the one athlete this dashboard belongs to, next to
 * the profile row that follows the same pattern.
 *
 * Every column is nullable; null means "no goal", not "zero": a goal
 * nobody set produces no line on Today, no target/actual row in the
 * weekly review and no key in the MCP summary. A default here would put
 * a target on the dashboard that nobody chose, which is the one thing a
 * goals layer must never do.
 *
 * The race columns say "race", not "Hyrox": which race the athlete runs
 * is their business, the layer only needs a name to print, a date to
 * count down to and a finishing time to aim at.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('athlete_goals', function (Blueprint $table) {
            $table->id();

            // Weekly sessions as a band. Either edge may stand alone: a
            // floor without a ceiling ("at least 4") and a ceiling without
            // a floor ("no more than 5", the overtraining guard) are both
            // complete goals.
            $table->unsignedTinyInteger('sessions_min')->nullable();
            $table->unsignedTinyInteger('sessions_max')->nullable();

            // Floor for the share of weekly training load that ran, in
            // percent. A floor only: nobody sets out to run at most.
            $table->unsignedTinyInteger('run_share_min')->nullable();

            $table->string('race_label')->nullable();
            $table->date('race_date')->nullable();
            $table->unsignedSmallInteger('race_target_minutes')->nullable();

            // 5,2 matches the profile's fallback weight column: typed by
            // a human, in kilograms.
            $table->decimal('weight_target_kg', 5, 2)->nullable();

            // One decimal, like Garmin reports the value it is read against.
            $table->decimal('vo2max_target', 4, 1)->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('athlete_goals');
    }
};
