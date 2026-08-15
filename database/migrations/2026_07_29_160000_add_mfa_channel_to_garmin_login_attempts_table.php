<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Where Garmin said it was sending the code.
 *
 * The page used to state that a code had been sent by email or text,
 * which was a guess. Garmin sends it to whichever second factor the
 * account has, and an authenticator app is not sent at all. Someone
 * waiting on an inbox that was never going to receive anything spends the
 * whole five-minute window before finding out, so the fetcher now reports
 * what the login library learned and it is kept here.
 *
 * Free text on purpose: it holds what Garmin returned rather than a
 * vocabulary of our own, including the case where the sign-in came
 * through the HTML widget and no delivery can be confirmed. The page
 * matches on what it recognises and shows the rest as-is.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('garmin_login_attempts', function (Blueprint $table) {
            $table->string('mfa_channel', 120)->nullable()->after('mfa_code');
        });
    }

    public function down(): void
    {
        Schema::table('garmin_login_attempts', function (Blueprint $table) {
            $table->dropColumn('mfa_channel');
        });
    }
};
