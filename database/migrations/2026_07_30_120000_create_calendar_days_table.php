<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The numbers of a day of appointments, kept after the appointments are
 * gone.
 *
 * The mirror next door holds a fortnight and then deletes itself, which is
 * right for titles and useless for a finding. Asking whether a season of
 * full weeks cost the athlete their sessions takes a season of days, and by
 * the time the season is over the diary that made it has been overwritten
 * fourteen times. So the arithmetic outlives its input: hours, counts,
 * first and last appointment, nothing else.
 *
 * Nothing here can name a person, a customer or a project. That is what
 * makes keeping it for a year defensible where keeping the titles would not
 * be, and it is a property of the table rather than a habit of the code
 * that writes it: there is no column a title could go in.
 *
 * A row means "this day was counted". Its absence means nobody counted it,
 * which is not the same as a day with nothing in it and must never be read
 * as one: a quiet day is evidence, an unknown day is not. Empty days
 * therefore get a row of zeroes like any other.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('calendar_days', function (Blueprint $table) {
            $table->id();
            $table->date('date')->unique();
            // Overlapping appointments counted once, so this is minutes of
            // somebody's life rather than a sum of invitations. A day holds
            // 1440 of them, which is why every minute column here is small.
            $table->unsignedSmallInteger('meeting_minutes')->default(0);
            $table->unsignedSmallInteger('meeting_count')->default(0);
            // Wall clock as the athlete read it, 'HH:MM'. Stored as written
            // rather than as a time, because that is the shape every reader
            // wants and re-deriving it would mean carrying a timezone into
            // a table that has no other use for one.
            $table->string('first_start', 5)->nullable();
            $table->string('last_end', 5)->nullable();
            $table->unsignedSmallInteger('longest_run_minutes')->default(0);
            $table->unsignedSmallInteger('back_to_back_count')->default(0);
            $table->unsignedSmallInteger('evening_minutes')->default(0);
            $table->unsignedSmallInteger('online_minutes')->default(0);
            // The roomiest opening of the working day. The list of openings
            // is not kept: it answers "where does a session fit", which is
            // only ever asked about days that have not happened yet.
            $table->unsignedSmallInteger('longest_free_gap')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('calendar_days');
    }
};
