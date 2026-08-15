<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One switch for the whole feedback loop: saving guidelines and filing
 * improvement issues. Its own toggle because this is the only pair of
 * tools that changes how the connector itself behaves, which the user
 * must be able to switch off without silencing the health data.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('connector_settings', function (Blueprint $table) {
            $table->boolean('allow_feedback')->default(true);
        });
    }

    public function down(): void
    {
        Schema::table('connector_settings', function (Blueprint $table) {
            $table->dropColumn('allow_feedback');
        });
    }
};
