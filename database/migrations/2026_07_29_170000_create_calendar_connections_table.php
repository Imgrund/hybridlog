<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per calendar provider the athlete has connected.
 *
 * Everything the connection needs lives here rather than in the
 * environment, because the app registration is the athlete's own: a
 * self-hosted copy of this dashboard has no shared client to fall back
 * on, so the sign-in page asks for the ID once and keeps it. Tokens and
 * secrets are encrypted at rest through the model's casts.
 *
 * The pending_* columns hold whichever handshake is currently in flight.
 * Microsoft signs in by device code (a code the athlete types on
 * microsoft.com, so no redirect URI has to match the deployment's
 * domain); Google needs a redirect and passes a state through it. Both
 * are short-lived and both are cleared the moment the sign-in resolves,
 * which is why they share the columns instead of each getting a pair.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('calendar_connections', function (Blueprint $table) {
            $table->id();
            $table->string('provider', 20)->unique();
            $table->string('client_id', 191)->nullable();
            $table->text('client_secret')->nullable();
            // Microsoft only: "common" covers work and personal accounts,
            // a directory ID restricts the sign-in to one organisation.
            $table->string('tenant', 100)->nullable();
            $table->text('access_token')->nullable();
            $table->text('refresh_token')->nullable();
            $table->dateTime('token_expires_at')->nullable();
            $table->string('account', 191)->nullable();
            $table->string('status', 20)->default('disconnected');
            $table->text('error')->nullable();
            $table->text('pending_secret')->nullable();
            $table->string('user_code', 40)->nullable();
            $table->string('verification_uri', 500)->nullable();
            $table->dateTime('pending_expires_at')->nullable();
            $table->unsignedSmallInteger('poll_interval')->default(5);
            $table->dateTime('last_synced_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('calendar_connections');
    }
};
