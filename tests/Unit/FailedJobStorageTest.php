<?php

declare(strict_types=1);

namespace Tests\Unit;

use Illuminate\Support\Env;
use Tests\TestCase;

/**
 * Where a failed job is written down when nothing says otherwise.
 *
 * A queue failure is the only account of a job that died, and it is
 * written by the handler that runs after everything else has already gone
 * wrong. If that write throws, the failure disappears silently: no row,
 * no log, no trace. So the one thing that must hold is that the failure
 * goes to the same database the application itself is using.
 */
class FailedJobStorageTest extends TestCase
{
    public function test_failed_jobs_fall_back_to_the_same_database_as_the_application(): void
    {
        // With DB_CONNECTION unset, because that is the only case where
        // the two defaults can disagree, and it is the normal case on a
        // host that hands over a DATABASE_URL instead. The framework
        // ships 'sqlite' in one file against 'pgsql' in the other, which
        // put failed jobs into a SQLite file that was never created while
        // the app ran on Postgres.
        $repository = Env::getRepository();
        $present = $repository->has('DB_CONNECTION');
        $original = $repository->get('DB_CONNECTION');
        $repository->clear('DB_CONNECTION');

        try {
            // Read from the files rather than the loaded config, which
            // was resolved while the variable was still set.
            $database = require config_path('database.php');
            $queue = require config_path('queue.php');
        } finally {
            if ($present) {
                $repository->set('DB_CONNECTION', (string) $original);
            }
        }

        $this->assertSame($database['default'], $queue['failed']['database']);
        $this->assertSame($database['default'], $queue['batching']['database']);
    }
}
