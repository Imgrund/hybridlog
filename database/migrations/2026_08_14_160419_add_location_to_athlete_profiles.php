<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Where the athlete trains, so the weather in their mirror is their own.
 *
 * The location was WEATHER_LAT/WEATHER_LON in the environment, which is
 * a property of the installation. That held while an installation meant
 * one athlete; a second one would otherwise read the first one's sky.
 *
 * The environment stays as the fallback rather than being retired: a
 * single-athlete installation that has set it keeps working untouched,
 * and this column only has to be filled by whoever is not standing
 * where the environment says.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('athlete_profiles', function (Blueprint $table) {
            // Five decimals is about a metre, which is far finer than a
            // weather model's grid and leaves no doubt at the coarse end.
            $table->decimal('latitude', 8, 5)->nullable();
            $table->decimal('longitude', 8, 5)->nullable();
            // What the athlete typed, resolved: the coordinates alone give
            // a reader no way to tell a wrong hit from a right one.
            $table->string('location_name')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('athlete_profiles', function (Blueprint $table) {
            $table->dropColumn(['latitude', 'longitude', 'location_name']);
        });
    }
};
