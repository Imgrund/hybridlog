<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Lives in the app's own schema next to cards and insights: the
        // Garmin mirror stays untouched by anything the AI writes.
        Schema::create('nutrition_log', function (Blueprint $table) {
            $table->id();
            $table->string('date', 10)->index();
            $table->dateTime('logged_at');
            $table->string('meal_type', 20)->nullable();
            $table->string('description');
            $table->unsignedInteger('calories_kcal');
            $table->unsignedInteger('protein_g')->nullable();
            $table->unsignedInteger('carbs_g')->nullable();
            $table->unsignedInteger('fat_g')->nullable();
            $table->string('source_note')->nullable();
            $table->timestamps();
        });

        Schema::table('connector_settings', function (Blueprint $table) {
            $table->boolean('allow_nutrition')->default(true);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('nutrition_log');
        Schema::table('connector_settings', function (Blueprint $table) {
            $table->dropColumn('allow_nutrition');
        });
    }
};
