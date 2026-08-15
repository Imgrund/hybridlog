<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Lives in the app's own schema next to cards, insights and the
        // nutrition log: the Garmin mirror stays untouched by anything
        // the AI writes.
        Schema::create('symptom_log', function (Blueprint $table) {
            $table->id();
            $table->string('date', 10)->index();
            $table->dateTime('logged_at');
            $table->string('symptom');
            $table->unsignedTinyInteger('severity')->nullable(); // 1 mild .. 3 severe
            $table->string('note')->nullable();
            $table->timestamps();
        });

        Schema::table('connector_settings', function (Blueprint $table) {
            $table->boolean('allow_symptoms')->default(true);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('symptom_log');
        Schema::table('connector_settings', function (Blueprint $table) {
            $table->dropColumn('allow_symptoms');
        });
    }
};
