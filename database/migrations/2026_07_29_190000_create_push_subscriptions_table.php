<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per device that agreed to be asked about a stress window.
 *
 * The endpoint is a URL the browser's push service hands out, and it is
 * the whole address: anyone holding it, and a valid signature from this
 * installation's key pair, can wake that device. It is a per-device secret
 * and treated as one: never shown, never handed to a connector.
 *
 * What is deliberately not here: the p256dh and auth keys a browser also
 * offers. Those exist to encrypt a payload, and no payload is sent (see
 * App\Push\WebPush). Storing them would be keeping the means to a thing
 * this dashboard does not do.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('push_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->text('endpoint');
            // Hashed for the unique index: the endpoints run to several
            // hundred characters, past what a database will index whole,
            // and re-subscribing the same browser has to land on the same
            // row rather than pile up a device per visit.
            $table->string('endpoint_hash', 64)->unique();
            // What the athlete sees on the notifications page, so a device
            // that no longer exists can be told apart from the one in hand.
            $table->string('device', 80)->nullable();
            // Cleared on every successful send: a subscription that has not
            // carried anything for months is the first suspect when a phone
            // stops ringing.
            $table->dateTime('last_push_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('push_subscriptions');
    }
};
