<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Dedupe ledger for app:health-alerts: one row per fired rule and
     * day, so a re-run never notifies twice.
     */
    public function up(): void
    {
        Schema::create('health_alerts', function (Blueprint $table) {
            $table->id();
            $table->string('rule');
            $table->date('date');
            $table->text('message');
            $table->timestamp('created_at')->nullable();
            $table->unique(['rule', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('health_alerts');
    }
};
