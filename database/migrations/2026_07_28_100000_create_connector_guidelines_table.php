<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Standing behaviour rules the athlete gave the connector as feedback
 * ("keep insights shorter", "always name protein next to calories").
 *
 * The server appends the active rows to its MCP instructions on every
 * handshake, so feedback saved in one conversation shapes the next one
 * without a deploy. Retired rows stay for traceability: the guideline
 * text plus the verbatim feedback it came from tell later readers why
 * the connector behaves the way it does.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('connector_guidelines', function (Blueprint $table) {
            $table->id();
            $table->string('guideline', 300);
            $table->text('source_feedback')->nullable();
            $table->timestamp('retired_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('connector_guidelines');
    }
};
