<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dashboard_cards', function (Blueprint $table) {
            $table->id();
            $table->string('title', 80);
            $table->string('annot')->nullable();
            $table->string('section')->default('coach');
            $table->string('card_type');
            $table->string('chart_type')->nullable();
            $table->text('sql');
            $table->string('x_column')->nullable();
            $table->json('series')->nullable();
            $table->string('unit', 20)->nullable();
            $table->float('reference_value')->nullable();
            $table->unsignedInteger('position')->default(0);
            $table->boolean('is_visible')->default(true);
            $table->string('created_note')->nullable();
            $table->timestamps();
        });

        Schema::create('insights', function (Blueprint $table) {
            $table->id();
            $table->string('title', 120);
            $table->text('body');
            $table->string('category')->default('allgemein');
            $table->boolean('pinned')->default(false);
            $table->boolean('is_archived')->default(false);
            $table->string('source_note')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dashboard_cards');
        Schema::dropIfExists('insights');
    }
};
