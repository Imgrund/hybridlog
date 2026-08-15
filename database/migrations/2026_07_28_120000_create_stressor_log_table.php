<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What the athlete says a high-stress window was about.
 *
 * The watch sees that stress was high, never why. When the mirror shows a
 * sustained high-stress stretch outside training, the chat asks once and
 * the answer lands here, window and stress snapshot included, so the
 * dashboard can total causes without re-deriving episodes. cause stays
 * null when the athlete had no explanation; that row's job is to make
 * sure the same window is not asked about twice.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stressor_log', function (Blueprint $table) {
            $table->id();
            $table->string('date', 10);
            $table->dateTime('window_start');
            $table->dateTime('window_end');
            $table->unsignedTinyInteger('avg_stress')->nullable();
            $table->unsignedTinyInteger('peak_stress')->nullable();
            $table->unsignedSmallInteger('minutes')->nullable();
            $table->string('cause', 120)->nullable();
            $table->string('category', 30)->nullable();
            $table->string('note', 500)->nullable();
            $table->timestamps();
            $table->index('date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stressor_log');
    }
};
