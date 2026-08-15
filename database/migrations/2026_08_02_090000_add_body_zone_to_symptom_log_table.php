<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('symptom_log', function (Blueprint $table) {
            // Where it hurts, in the athlete's own terms: a joint region
            // ("knee") as readily as a muscle zone ("hamstring"). The body
            // map draws only muscle polygons, so config/muscle_map.php
            // carries the region-to-zone approximation for display while
            // the log keeps what was actually reported. The validated
            // instrument behind the region list is the OSTRC overuse
            // questionnaire, which registers complaints per body region.
            $table->string('body_zone', 32)->nullable()->index();
            // The map cannot draw a side (the polygons are not split left
            // and right, and Garmin reports no side either), but a history
            // an athlete takes to a physio is worth much less without it.
            $table->string('side', 8)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('symptom_log', function (Blueprint $table) {
            $table->dropColumn(['body_zone', 'side']);
        });
    }
};
