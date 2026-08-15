<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mcp_tool_calls', function (Blueprint $table): void {
            $table->id();
            $table->string('tool')->index();
            // Whatever the model passed in, minus anything the tool marks as sensitive.
            // For query tools this holds the SQL, which is the whole point of the log.
            $table->text('arguments')->nullable();
            // 'stdio' = Claude Code/Desktop on this machine, 'web' = claude.ai/ChatGPT connector.
            $table->string('transport', 16)->index();
            // OAuth client name on the web transport, null on stdio (no client identity there).
            $table->string('client')->nullable();
            // Groups calls that belong to the same conversation, so a sequence of
            // tools can be read as one exchange rather than isolated events.
            $table->string('session_id')->nullable()->index();
            $table->unsignedInteger('duration_ms');
            $table->boolean('ok')->index();
            // Validation message, exception message or the tool's own error response text.
            $table->text('error')->nullable();
            $table->timestamp('created_at')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mcp_tool_calls');
    }
};
