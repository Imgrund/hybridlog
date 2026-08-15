<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Two switches for the diary, and both of them start off.
 *
 * Every other switch in this table defaults to on, because the athlete
 * connected a watch in order to be asked about the watch. The calendar is
 * different in kind: it was connected so the dashboard could explain a
 * stress window, and nobody agreed to it becoming part of what an AI
 * assistant reads. Off by default means connecting a calendar changes
 * nothing about the connector until somebody decides it should.
 *
 * Two switches rather than one because the two things being shared are not
 * the same thing. How many hours of appointments a Tuesday held is a
 * number about the athlete. What those appointments were called is a set of
 * names, customers and projects, most of which belong to other people. The
 * first is useful on its own, so it must be possible to grant without the
 * second.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('connector_settings', function (Blueprint $table) {
            $table->boolean('share_calendar_load')->default(false);
            $table->boolean('share_calendar_titles')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('connector_settings', function (Blueprint $table) {
            $table->dropColumn(['share_calendar_load', 'share_calendar_titles']);
        });
    }
};
