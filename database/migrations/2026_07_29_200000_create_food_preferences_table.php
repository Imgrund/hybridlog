<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The corrections the athlete makes to the taste profile the log derives.
 *
 * Only the corrections are stored. What the athlete likes, how often they
 * eat it and which macro slot it fills is read off the nutrition log on
 * every request, so a profile can never contradict the entries printed
 * beside it. This table holds the three things the log cannot know: a food
 * the athlete wants suggested even though it is rare, a food they never
 * want suggested again, and a diet the suggestions have to respect.
 *
 * A term is one word or short phrase, matched against an entry
 * description, because that is the only handle the log offers: it stores
 * a sentence the AI wrote, not an ingredient list.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('food_preferences', function (Blueprint $table) {
            $table->id();
            $table->string('kind', 10);
            $table->string('term', 60);
            $table->timestamps();
            // One row per statement. Saying "no liver" twice is the same
            // statement, and a second row would only make the list longer
            // to read and harder to take back.
            $table->unique(['kind', 'term']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('food_preferences');
    }
};
