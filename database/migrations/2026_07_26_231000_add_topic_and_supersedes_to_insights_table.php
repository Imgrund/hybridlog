<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('insights', function (Blueprint $table): void {
            // Groups insights that revisit the same subject, so the dashboard can
            // show one stack per topic instead of one card per conversation.
            $table->string('topic')->nullable()->index()->after('category');
            // Points from the newer insight to the one it replaces. Set on save,
            // when the AI knows which earlier take it is updating.
            $table->foreignId('supersedes_id')->nullable()->after('topic')
                ->constrained('insights')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('insights', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('supersedes_id');
            $table->dropColumn('topic');
        });
    }
};
