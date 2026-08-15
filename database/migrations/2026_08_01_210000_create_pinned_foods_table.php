<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // What the athlete chose to keep one tap away, as opposed to what
        // the log's median happened to rank highest. The numbers are
        // copied rather than referenced: a pin is the portion as it was
        // pinned, and a later entry correcting one meal must not silently
        // change the shortcut the athlete relies on every morning.
        Schema::create('pinned_foods', function (Blueprint $table) {
            $table->id();
            $table->string('meal_type', 20)->index();
            $table->string('description');
            $table->unsignedInteger('calories_kcal');
            $table->unsignedInteger('protein_g')->nullable();
            $table->unsignedInteger('carbs_g')->nullable();
            $table->unsignedInteger('fat_g')->nullable();
            $table->unsignedInteger('water_ml')->nullable();
            $table->timestamps();

            // One pin per dish and slot: pinning the same breakfast twice
            // is the same statement, not two shortcuts.
            $table->unique(['meal_type', 'description']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pinned_foods');
    }
};
