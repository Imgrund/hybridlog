<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The first half of multi-tenancy: every public-schema table that holds
 * per-athlete state learns whose it is.
 *
 * users.is_admin marks the installation owner; the oldest account gets
 * it here, because that account is the one all pre-tenancy rows belong
 * to (verified 2026-08-13: production has exactly one user). Everything
 * else is the user_id column, backfilled to that owner and then made
 * NOT NULL, so a row without a tenant cannot exist from here on.
 */
return new class extends Migration
{
    /**
     * Per-athlete tables in the public schema. The mirror schema is
     * deliberately absent: the migration after this one moves it.
     *
     * @var list<string>
     */
    private const TABLES = [
        'athlete_profiles',
        'connector_settings',
        'connector_guidelines',
        'symptom_log',
        'health_alerts',
        'garmin_login_attempts',
        'mcp_tool_calls',
        'push_subscriptions',
        'push_sends',
    ];

    /** Tables that hold exactly one row per user, enforced from here on. */
    private const ONE_ROW_PER_USER = ['athlete_profiles', 'connector_settings'];

    /**
     * The one table whose user_id stays nullable.
     *
     * mcp_tool_calls is telemetry, and the call worth recording most is
     * the one that resolved no tenant at all: a connector pointed at
     * this installation without an account behind it. NOT NULL would
     * throw that row away, which is the opposite of what a log is for.
     */
    private const NULLABLE = ['mcp_tool_calls'];

    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_admin')->default(false);
        });

        $owner = DB::table('users')->orderBy('id')->value('id');

        if ($owner !== null) {
            DB::table('users')->where('id', $owner)->update(['is_admin' => true]);
        }

        foreach (self::TABLES as $name) {
            // A populated table on an installation without a single user
            // has no owner to give the rows to. Refusing beats guessing
            // and beats deleting: whoever runs this must create the
            // owner account first (app:create-user).
            if ($owner === null && DB::table($name)->exists()) {
                throw new RuntimeException(
                    "Cannot adopt rows in {$name}: no user exists to own them. Create the owner account first."
                );
            }

            // The singletons were kept single by firstOrCreate([]), which
            // only ever matches the first row; any duplicate beyond it is
            // dead weight and would break the per-user unique below.
            if (in_array($name, self::ONE_ROW_PER_USER, true)) {
                DB::table($name)
                    ->whereNot('id', fn ($query) => $query->selectRaw('min(id)')->from($name))
                    ->delete();
            }

            Schema::table($name, function (Blueprint $table) {
                $table->foreignId('user_id')->nullable()->index()
                    ->constrained()->cascadeOnDelete();
            });

            if ($owner !== null) {
                DB::table($name)->update(['user_id' => $owner]);
            }

            if (! in_array($name, self::NULLABLE, true)) {
                Schema::table($name, function (Blueprint $table) {
                    $table->foreignId('user_id')->nullable(false)->change();
                });
            }
        }

        Schema::table('athlete_profiles', function (Blueprint $table) {
            $table->unique('user_id');
        });
        Schema::table('connector_settings', function (Blueprint $table) {
            $table->unique('user_id');
        });

        // The once-per-day ledgers dedupe per tenant now: user B's morning
        // briefing must not be swallowed by the fact that user A already
        // got one.
        Schema::table('health_alerts', function (Blueprint $table) {
            $table->dropUnique(['rule', 'date']);
            $table->unique(['user_id', 'rule', 'date']);
        });
        Schema::table('push_sends', function (Blueprint $table) {
            $table->dropUnique(['kind', 'date']);
            $table->unique(['user_id', 'kind', 'date']);
        });

        // push_subscriptions keeps its global unique endpoint_hash: an
        // endpoint identifies one physical browser, and that browser rings
        // for whoever subscribed it last (PushSubscription::remember).
    }

    public function down(): void
    {
        Schema::table('health_alerts', function (Blueprint $table) {
            $table->dropUnique(['user_id', 'rule', 'date']);
            $table->unique(['rule', 'date']);
        });
        Schema::table('push_sends', function (Blueprint $table) {
            $table->dropUnique(['user_id', 'kind', 'date']);
            $table->unique(['kind', 'date']);
        });

        foreach (self::TABLES as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->dropConstrainedForeignId('user_id');
            });
        }

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('is_admin');
        });
    }
};
