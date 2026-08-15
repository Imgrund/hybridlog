<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The one-tap answer for a day nobody logged: how full it felt,
        // in the athlete's own words. Deliberately no calorie column and
        // no numeric shadow of one, because the mark is a self-report
        // that labels the gap; the moment it fed a balance it would be
        // the invented number the empty day was protected from.
        Schema::create('nutrition_day_marks', function (Blueprint $table) {
            $table->id();
            // One answer per day: marking a day again is a correction,
            // not a second opinion.
            $table->string('date', 10)->unique();
            $table->string('mark', 10);
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('nutrition_day_marks');
    }
};
