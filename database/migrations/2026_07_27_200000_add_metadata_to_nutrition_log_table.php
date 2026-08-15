<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Two facts the macros cannot carry: what kind of food it was
        // (tags, set by the AI while it estimates) and how firm the
        // estimate is. The macro profile (high carb, low carb, ...) is
        // NOT stored: it is derived from the macros on read, so it can
        // never contradict the grams standing next to it.
        Schema::table('nutrition_log', function (Blueprint $table) {
            $table->json('tags')->nullable();
            $table->string('confidence', 12)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('nutrition_log', function (Blueprint $table) {
            $table->dropColumn(['tags', 'confidence']);
        });
    }
};
