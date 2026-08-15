<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('connector_settings', function (Blueprint $table) {
            $table->id();
            $table->boolean('share_health_data')->default(true);
            $table->boolean('share_body_metrics')->default(true);
            $table->boolean('allow_cards')->default(true);
            $table->boolean('allow_insights')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('connector_settings');
    }
};
