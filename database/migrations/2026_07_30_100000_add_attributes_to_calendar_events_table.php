<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Three things about an appointment that change what it cost.
 *
 * An appointment accepted only tentatively probably happened and is worth
 * keeping apart from one that was confirmed, rather than guessed about
 * later. Whether it was a call or a room is the difference between a day
 * of screens and a day of driving. A series that repeats every week is a
 * standing cost rather than an event.
 *
 * A declined appointment has no row at all: the providers drop it on the
 * way in, next to the cancelled ones, so "was not there" is decided in one
 * place instead of having to be remembered by everything that counts.
 *
 * Flags only, deliberately: no organiser, no attendee names, no location,
 * no join URL. What the mirror holds stays what it held before, one title
 * and a shape, and the reason for that is written in the migration that
 * created the table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('calendar_events', function (Blueprint $table) {
            // accepted, tentative, declined, organizer, none. A string
            // rather than an enum because Google and Microsoft each have
            // their own vocabulary and a new value in either should not
            // need a migration to arrive.
            $table->string('response', 20)->nullable()->after('attendees');
            // Null where the provider did not say, which is not the same as
            // false: a diary that predates online meetings has neither.
            $table->boolean('online')->nullable()->after('response');
            $table->boolean('recurring')->nullable()->after('online');
        });
    }

    public function down(): void
    {
        Schema::table('calendar_events', function (Blueprint $table) {
            $table->dropColumn(['response', 'online', 'recurring']);
        });
    }
};
