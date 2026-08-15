<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One Garmin sign-in in progress, so that a browser and a queue worker
 * can hold the same conversation.
 *
 * Garmin's MFA cannot be answered in one request: the code is only sent
 * after the password has been accepted, and the half-finished session
 * that receives it lives inside the Python client object. So the worker
 * keeps that process alive and this row is how the two sides talk. The
 * browser writes the code here, the worker reads it, and both watch the
 * status.
 *
 * The password is never in here. It reaches the worker in the job
 * payload, which is encrypted, and goes straight into the fetcher's
 * stdin. The MFA code is, for the seconds between the two requests: six
 * digits, useless without the session waiting for them, and cleared as
 * soon as they have been passed on.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('garmin_login_attempts', function (Blueprint $table) {
            $table->id();
            // starting|mfa_required|completing|succeeded|failed
            $table->string('status', 20);
            $table->string('mfa_code', 20)->nullable();
            // The Garmin account name, read back from the stored session:
            // proof that what was saved actually logs in.
            $table->string('account', 120)->nullable();
            $table->string('error', 500)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('garmin_login_attempts');
    }
};
