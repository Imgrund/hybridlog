<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * refresh-data was the only tool outside the permission toggles, even though
 * it is the only one that starts a process on the host. Its own switch, so
 * turning everything off on /connect really does silence the connector.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('connector_settings', function (Blueprint $table) {
            $table->boolean('allow_refresh')->default(true);
        });
    }

    public function down(): void
    {
        Schema::table('connector_settings', function (Blueprint $table) {
            $table->dropColumn('allow_refresh');
        });
    }
};
