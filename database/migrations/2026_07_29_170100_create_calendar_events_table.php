<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A local copy of what was in the diary, kept only to explain stress.
 *
 * The watch records that stress was high, never why. A meeting that ran
 * across the same half hour is the cheapest answer there is, and it is
 * one the athlete would otherwise have to type. Nothing here is shown to
 * an AI connector: the title only ever reaches the coach if the athlete
 * takes the suggestion, at which point it is a stressor_log row like any
 * other and covered by that switch.
 *
 * Times are stored naive in the app timezone, the same way the mirror
 * stores intraday.ts_local, so a window and a meeting can be compared
 * without either side carrying an offset the other does not.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('calendar_events', function (Blueprint $table) {
            $table->id();
            $table->string('provider', 20);
            // A hash of the provider's own ID rather than the ID itself.
            // Graph hands out base64 identifiers long enough to push a
            // composite unique index past what MySQL indexes by default,
            // and nothing here ever writes back, so the only thing the
            // column has to do is tell two meetings apart.
            $table->string('external_id', 64);
            $table->string('subject', 255)->nullable();
            $table->dateTime('starts_at');
            $table->dateTime('ends_at');
            $table->boolean('all_day')->default(false);
            $table->unsignedSmallInteger('attendees')->nullable();
            // "Free" in the diary is a marker, not an appointment, and a
            // marker did not raise anybody's pulse. Kept rather than
            // dropped at sync time so the reason stays visible in the row.
            $table->boolean('busy')->default(true);
            $table->dateTime('synced_at');
            $table->timestamps();

            $table->unique(['provider', 'external_id']);
            $table->index('starts_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('calendar_events');
    }
};
