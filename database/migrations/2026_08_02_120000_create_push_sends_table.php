<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per companion push that reached a device: the morning briefing,
 * the evening nudge and the Sunday weekly-report reminder. The unique pair
 * is the once-per-day ledger the three commands check before they ring.
 *
 * A separate table rather than extra rules in health_alerts on purpose:
 * that table means "a hard threshold was broken today" and carries the
 * broken rule's message, while these rows mean "the scheduled companion
 * push went out". Folding them together would make every consumer of
 * health_alerts filter out rows that are not alerts.
 *
 * No message column. The wording is composed at the moment the service
 * worker shows the notification (the same late-binding the stress prompt
 * uses), so a stored text would only be a second, staler copy of it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('push_sends', function (Blueprint $table) {
            $table->id();
            $table->string('kind');
            $table->date('date');
            $table->dateTime('sent_at');
            // How many devices took it. Zero never gets written: the row
            // records a delivery, and nothing was delivered.
            $table->unsignedSmallInteger('devices');
            $table->timestamp('created_at')->nullable();

            $table->unique(['kind', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('push_sends');
    }
};
