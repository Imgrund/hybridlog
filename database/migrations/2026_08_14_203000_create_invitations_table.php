<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The one way an account comes into being without somebody at a terminal.
 *
 * This installation has no sign-up page on purpose: a login nobody can
 * register at has no surface to attack. That is still true after this
 * table, because nothing here creates an account by itself. A row is a
 * standing permission for one address, issued by the owner with
 * `app:invite`, spent once and then dead. Without a row and the token
 * that matches it there is no route to an account at all.
 *
 * The token is kept as a hash, so the table is not a list of working
 * keys. It is looked up by that hash rather than by email and verified
 * afterwards, which is safe here in a way it would not be for a
 * password: this value is forty-eight random characters, not something
 * a person chose.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invitations', function (Blueprint $table) {
            $table->id();
            // One standing invitation per address. Inviting the same
            // person twice replaces the first, which is also how a link
            // that never arrived is reissued.
            $table->string('email')->unique();
            $table->string('name')->nullable();
            $table->string('token_hash', 64)->unique();
            $table->timestamp('expires_at');
            // Kept rather than deleted on redemption: a spent invitation
            // is the record of how an account came to exist, and the row
            // is what makes a second attempt fail.
            $table->timestamp('accepted_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invitations');
    }
};
