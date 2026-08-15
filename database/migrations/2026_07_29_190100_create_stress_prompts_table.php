<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per window the athlete has been asked about, append-only.
 *
 * The dedupe ledger behind app:stress-prompt, and the day's counter for
 * its cap. Both are needed because the windows themselves are derived
 * fresh from the samples on every run: nothing on an episode records that
 * a phone already buzzed about it, and a second buzz for the same stretch
 * is exactly how a helpful nudge turns into something to switch off.
 *
 * Separate from stressor_log rather than a column on it: that table is the
 * athlete's answers, and being asked is not one. A window that was asked
 * about and never answered has to stay unanswered.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stress_prompts', function (Blueprint $table) {
            $table->id();
            // The window as it stood when the notification went out. Stored
            // as a pair rather than a start alone because a later sync can
            // merge two stretches into one longer window, and the overlap
            // test is what keeps that from being asked about again.
            $table->dateTime('window_start');
            $table->dateTime('window_end');
            $table->dateTime('sent_at');
            // How many devices took it. Zero never gets written: the row
            // records a delivery, and nothing was delivered.
            $table->unsignedSmallInteger('devices');
            $table->timestamp('created_at')->nullable();

            $table->index('sent_at');
            $table->index('window_start');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stress_prompts');
    }
};
