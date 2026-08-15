<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The reader's chosen interface language.
 *
 * Nullable on purpose, and null is not "English": it means the dashboard
 * follows the browser's Accept-Language header. Defaulting the column to
 * a locale would silently make that the answer for an installation whose
 * owner never opened the profile page.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('athlete_profiles', function (Blueprint $table) {
            $table->string('locale', 5)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('athlete_profiles', function (Blueprint $table) {
            $table->dropColumn('locale');
        });
    }
};
