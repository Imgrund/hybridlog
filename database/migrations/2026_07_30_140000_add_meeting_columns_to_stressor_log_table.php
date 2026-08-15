<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What the diary held while the window ran, written down beside the answer.
 *
 * A derived number normally has no business in a table here: the metric
 * classes compute on the fly and nothing about a stress window's
 * circumstances is expensive to look up. Except that it stops being
 * lookable. Appointments are deleted after a fortnight on purpose, and the
 * pattern paragraph on the stressor card reads ninety days, so twelve of
 * those weeks have no diary left to ask. Stamping the four attributes at
 * the moment the window is answered is the only point at which they exist.
 *
 * Four, and no more. Whether an appointment was running at all, how many
 * people were in it, whether it was a screen or a room, and whether it ran
 * into another one. Enough to count a season by and short of a second copy
 * of the calendar: no title, no organiser, no names, and nothing that
 * outlives the answer it belongs to.
 *
 * Nullable throughout, and the null means "nobody looked" rather than "no
 * meeting". Rows written before this existed, rows from a window older than
 * the mirror reaches, rows from an installation with no calendar at all:
 * all three are unknown, none of them are evidence of a quiet afternoon,
 * and the counting has to be able to tell them apart from a false.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stressor_log', function (Blueprint $table) {
            $table->boolean('during_meeting')->nullable();
            $table->unsignedSmallInteger('meeting_attendees')->nullable();
            $table->boolean('meeting_online')->nullable();
            $table->boolean('meeting_back_to_back')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('stressor_log', function (Blueprint $table) {
            $table->dropColumn(['during_meeting', 'meeting_attendees', 'meeting_online', 'meeting_back_to_back']);
        });
    }
};
