<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The components an entry's estimate was built from, so the athlete
        // can check the estimate and a correction can revise one component
        // instead of delete-and-relog. The entry's totals stay the truth
        // the dashboard sums; items are their itemised evidence, which is
        // why every column here may be sparser than its total.
        Schema::create('nutrition_log_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('nutrition_log_id')->constrained('nutrition_log')->cascadeOnDelete()->index();
            $table->string('name', 120);
            // Free text ("80 g", "1 tbsp"): amounts are assumptions more
            // often than measurements, and a unit column would lend them
            // a precision the estimate does not have.
            $table->string('amount_text', 50)->nullable();
            $table->unsignedInteger('kcal');
            $table->unsignedInteger('protein_g')->nullable();
            $table->unsignedInteger('carbs_g')->nullable();
            $table->unsignedInteger('fat_g')->nullable();
            // Where the amount came from: label | photo | assumed. The
            // default is the honest one, a quantity nobody read anywhere.
            $table->string('source', 10)->default('assumed');
            $table->unsignedSmallInteger('position');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('nutrition_log_items');
    }
};
