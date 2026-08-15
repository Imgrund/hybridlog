<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Its own switch, like every self-reported channel: stressor questions
 * and logging can be turned off without silencing the health data or
 * the symptom log, because being asked "was war da los?" is exactly the
 * kind of thing one might want to stop without stopping anything else.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('connector_settings', function (Blueprint $table) {
            $table->boolean('allow_stressors')->default(true);
        });
    }

    public function down(): void
    {
        Schema::table('connector_settings', function (Blueprint $table) {
            $table->dropColumn('allow_stressors');
        });
    }
};
