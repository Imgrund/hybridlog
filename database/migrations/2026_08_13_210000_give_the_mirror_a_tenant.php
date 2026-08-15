<?php

declare(strict_types=1);

use App\Garmin\Mirror;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The mirror stops being the installation's and becomes an athlete's.
 *
 * The migration before this one gave every table in the public schema an
 * owner. This is the other half: the Garmin mirror, not a table but a schema
 * written by fetcher/fetch.py, moves from the name it had while there was
 * only ever one athlete to the name it has as one tenant's, and gets the
 * reader role that keeps it out of everybody else's reach.
 *
 * Three steps, each of which does nothing when it has nothing to do:
 *
 *  1. schema garmin -> garmin_t{owner}. The one destructive-looking step,
 *     and the reason a pg_dump belongs before this migration on any
 *     installation with data. A rename is atomic and keeps every table,
 *     index, constraint and comment, so nothing is copied and nothing can
 *     half-arrive.
 *  2. the Garmin session row moves from the fixed id 1 to the owner's user
 *     id, because that column is the tenant now (fetcher/schema.sql).
 *  3. every existing user gets their mirror provisioned: schema, tables,
 *     reader role, grants. For the owner that is the schema just renamed,
 *     for anyone invited since, it is an empty one of their own.
 *
 * Step 3 needs a connection that may create schemas and roles. On Railway
 * the app connects as the database owner and it can; on an installation
 * running the full role split it cannot, and provisioning is
 * database/postgres/roles.sql's job there. So a failure is reported and
 * skipped rather than taking the deploy down: the migration's own work is
 * done by then, and a dashboard whose mirror is missing says so on its own.
 *
 * Every statement that touches a schema goes through the mirror connection,
 * and that is load-bearing rather than tidy. Laravel runs a migration inside
 * a transaction wherever the driver supports transactional DDL, which
 * Postgres does. A rename issued on the app's connection would therefore sit
 * uncommitted, holding an exclusive lock on the schema, while step 3 goes
 * looking for it on the mirror connection: that second connection does not
 * fail, it waits, and it waits for a transaction that only ends when the
 * migration does. The deploy hangs instead of failing, which is the worse of
 * the two. One connection for the whole schema half avoids it, at the price
 * of the rename not being rolled back if a later step throws. That price is
 * small: the rename is the step with nothing after it that can fail.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Nothing here is a tenant's read, and a tenant's reader may not
        // rename a schema. Hand the connection back to the role it logged
        // in as before asking it to change anything.
        Mirror::unpin();

        $owner = User::owner();

        // The rename is the owner's alone. Without an owner there is nobody
        // whose mirror this was, and inventing tenant 1 for a schema that
        // may hold somebody's health history is not a guess worth making.
        if ($owner !== null && $this->schemaExists('garmin')) {
            $target = Mirror::schema($owner->id);

            if ($this->schemaExists($target)) {
                throw new RuntimeException(
                    "Both garmin and {$target} exist. One of them is this installation's mirror "
                    .'and this migration cannot tell which, so it stops rather than merge them.'
                );
            }

            DB::connection('garmin')->statement("alter schema garmin rename to {$target}");
        }

        // Nothing to move on Railway, where the owner is user 1 and the row
        // already carries that id. Written for the installation where they
        // are not the same number, and skipped when the owner already has a
        // session of their own, which is the only case where moving the old
        // row would overwrite something.
        if ($owner !== null && $owner->id !== 1 && $this->sessionTableExists()) {
            $sessions = DB::connection('garmin')->table('garmin_private.garmin_session');

            if ($sessions->clone()->where('id', $owner->id)->doesntExist()) {
                $sessions->where('id', 1)->update(['id' => $owner->id]);
            }
        }

        foreach (User::query()->orderBy('id')->pluck('id') as $id) {
            try {
                Mirror::ensure((int) $id);
            } catch (Throwable $exception) {
                // Reported, not swallowed: an installation that cannot
                // provision from here has to hear it once, at the moment it
                // would have happened.
                echo "  could not provision the mirror for user {$id}: {$exception->getMessage()}\n";
            }
        }
    }

    public function down(): void
    {
        Mirror::unpin();

        $owner = User::owner();

        foreach (User::query()->orderBy('id')->pluck('id') as $id) {
            $reader = Mirror::reader((int) $id);

            if (DB::connection('garmin')->selectOne('select 1 as found from pg_roles where rolname = ?', [$reader]) === null) {
                continue;
            }

            // The schemas stay. They hold months of health history that this
            // migration did not create, and dropping them to undo a rename
            // would be a trade nobody asked for. Only the roles go, which is
            // what the up() side actually added. DROP OWNED BY first: it is
            // what takes the grants back, and a role still holding one
            // cannot be dropped.
            DB::connection('garmin')->statement("drop owned by {$reader}");
            DB::connection('garmin')->statement("drop role {$reader}");
        }

        if ($owner !== null && $this->schemaExists(Mirror::schema($owner->id)) && ! $this->schemaExists('garmin')) {
            DB::connection('garmin')->statement('alter schema '.Mirror::schema($owner->id).' rename to garmin');
        }

        if ($owner !== null && $this->sessionTableExists()) {
            DB::connection('garmin')->table('garmin_private.garmin_session')
                ->where('id', $owner->id)
                ->update(['id' => 1]);
        }

        Mirror::forget();
    }

    private function schemaExists(string $schema): bool
    {
        return DB::connection('garmin')->selectOne('select 1 as found from pg_namespace where nspname = ?', [$schema]) !== null;
    }

    private function sessionTableExists(): bool
    {
        return DB::connection('garmin')->selectOne(
            'select 1 as found from pg_tables where schemaname = ? and tablename = ?',
            ['garmin_private', 'garmin_session']
        ) !== null;
    }
};
