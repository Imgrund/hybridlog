<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A drink knows its water share; storing it on the entry lets the
        // usuals propose the pair (kcal + water) as one tap later on.
        Schema::table('nutrition_log', function (Blueprint $table) {
            $table->unsignedInteger('water_ml')->nullable()->after('fat_g');
        });

        // The hydration row a drink created: deleting the drink must take
        // its water back too (and tell Garmin), so the rows are linked.
        // The cascade is only the net under the code path that deletes
        // explicitly in order to push the negative amount first.
        Schema::table('water_log', function (Blueprint $table) {
            $table->foreignId('nutrition_log_id')->nullable()
                ->constrained('nutrition_log')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('water_log', function (Blueprint $table) {
            $table->dropConstrainedForeignId('nutrition_log_id');
        });
        Schema::table('nutrition_log', function (Blueprint $table) {
            $table->dropColumn('water_ml');
        });
    }
};
