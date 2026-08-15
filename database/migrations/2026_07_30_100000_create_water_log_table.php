<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Its own table rather than a nutrition_log row: water carries no
        // calories and no meal, and a shared table would force every
        // derived number to filter it back out.
        Schema::create('water_log', function (Blueprint $table) {
            $table->id();
            $table->string('date', 10)->index();
            $table->dateTime('logged_at');
            $table->unsignedInteger('volume_ml');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('water_log');
    }
};
