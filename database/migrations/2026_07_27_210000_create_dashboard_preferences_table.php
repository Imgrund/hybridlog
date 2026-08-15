<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Which detail areas this dashboard shows. A single row, like the
        // athlete profile: the page has one reader. Stored as the hidden
        // list rather than the visible one, so an area added later shows
        // up by default instead of silently staying off.
        Schema::create('dashboard_preferences', function (Blueprint $table) {
            $table->id();
            $table->json('hidden_sections')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dashboard_preferences');
    }
};
