<?php

namespace App\Garmin;

use App\Tenancy\ActingUser;
use InvalidArgumentException;
use PDO;
use RuntimeException;

/**
 * Executes AI-provided SQL against the Garmin mirror strictly read-only.
 *
 * The load-bearing defense is not in this file. It is the database role the
 * statement runs as: garmin_reader_t{id} holds SELECT on one athlete's
 * mirror schema and nothing else anywhere, so it cannot read the app's
 * users, cannot see the Garmin session tokens, cannot reach another
 * athlete's mirror even by naming its schema in full, cannot write, and
 * cannot reach the host filesystem (see database/postgres/tenant.sql and
 * roles.sql, which are verified against those attacks). Everything below is
 * the second line: it exists so a misconfigured deployment that reuses one
 * superuser connection string is still not trivially exploitable.
 *
 * Since the mirror became per athlete this class no longer takes the role
 * on trust. App\Garmin\Mirror switches into it before every statement, and
 * run() asks the server who it now is before handing over model-written
 * SQL.
 *
 * Layers here: a single statement starting with SELECT/WITH, a blocklist,
 * an explicit READ ONLY transaction with its own statement_timeout, and
 * three budgets on the result (rows, bytes, wall clock).
 *
 * The byte budget is the one that matters in daily use. The row cap alone
 * says nothing about size: sleep.score_components_json averages 880 bytes,
 * so 500 rows of it would be roughly 110k tokens in a single answer. That
 * costs nothing today at 59 nights of history, and breaks a conversation
 * once a year or two has accumulated.
 *
 * The old SQLite version documented a hole here: its clock was only checked
 * between rows, so a query that spent all its time inside a join before
 * returning the first row ran unbounded. Postgres closes that one, because
 * statement_timeout is enforced by the server rather than by this loop.
 */
class ReadOnlyGarminQuery
{
    public const MAX_ROWS = 500;

    /** Roughly 50k tokens, well inside a conversation's budget. */
    public const MAX_BYTES = 200_000;

    /** Wall clock granted to the statement, server-side and here. */
    public const MAX_SECONDS = 10;

    /**
     * Postgres constructs that must not appear, even though the role should
     * already refuse them.
     *
     * Grouped by what they would buy an attacker:
     *  - copy .................. writes/reads host files, runs programs
     *  - pg_read_*, pg_ls_* .... reads host files and directory listings
     *  - lo_* .................. large objects, another file read/write path
     *  - dblink, *_fdw ......... outbound connections from the database host
     *  - pg_sleep .............. wall-clock denial of service
     *  - set_config ............ the one that is genuinely SELECT-shaped:
     *    "select set_config('statement_timeout','0',false)" is a valid,
     *    single, semicolon-free SELECT that would switch off the timeout
     *    this class just set.
     *  - *_to_xml .............. takes a query as a string and runs it, so
     *    it walks straight past every other entry in this list
     *  - pg_terminate/cancel ... reaches other sessions
     *  - pg_authid, pg_shadow .. credential tables
     *  - u& .................... Postgres' unicode-escaped identifier form,
     *    U&"\0070g_read_file", which spells any name above without ever
     *    writing its letters
     *
     * The suffix patterns sit outside the \b group deliberately. An
     * underscore is a word character, so \b never matches between the "s"
     * and the "_" of postgres_fdw, and writing \b_fdw would have produced
     * an entry that can never fire.
     */
    private const FORBIDDEN = '/\b(?:copy|pg_read_|pg_stat_file|pg_ls_|lo_import|lo_export|lo_get'
        .'|dblink|pg_sleep|set_config|pg_terminate_backend|pg_cancel_backend'
        .'|pg_reload_conf|pg_rotate_logfile|pg_logical_emit_message|pg_authid|pg_shadow)'
        .'|_fdw|_to_xml|u&["\']/i';

    public function __construct(
        private readonly int $maxBytes = self::MAX_BYTES,
        private readonly int $maxSeconds = self::MAX_SECONDS,
    ) {}

    /**
     * The statement checks, callable on their own. Anything that wants to
     * inspect a statement before running it (QueryTables sends it through
     * EXPLAIN) calls this first, so unvetted SQL never reaches the server
     * on a side path the blocklist was written to close.
     *
     * @return string the trimmed statement run() expects
     */
    public function guard(string $sql): string
    {
        $sql = rtrim(trim($sql), ';');

        if (! preg_match('/^\s*(select|with)\b/i', $sql)) {
            throw new InvalidArgumentException('Only a single SELECT (or WITH ... SELECT) statement is allowed.');
        }
        if (str_contains($sql, ';')) {
            throw new InvalidArgumentException('Multiple SQL statements are not allowed.');
        }
        if (preg_match(self::FORBIDDEN, $sql)) {
            throw new InvalidArgumentException('Statement contains a forbidden keyword.');
        }

        return $sql;
    }

    /** @return array{columns: list<string>, rows: list<array<string, mixed>>, truncated: bool, truncated_by?: string} */
    public function run(string $sql, int $maxRows = self::MAX_ROWS): array
    {
        $sql = $this->guard($sql);

        $connection = Mirror::connection();
        $tenant = ActingUser::require()->id;

        // The one place that checks rather than trusts. Everywhere else the
        // SQL was written in this repository and search_path is enough to
        // send it to the right mirror; here it was written by a language
        // model, so the question is not which schema it means but which it
        // may reach, and only the role answers that. An installation whose
        // per-tenant roles were never created would run this query with the
        // privileges of the login role, which on a single-connection-string
        // platform is every athlete's data at once. It refuses instead.
        if (! Mirror::isIsolated($connection, $tenant)) {
            throw new RuntimeException(
                'This installation cannot confine a query to one athlete yet. '
                .'Run database/postgres/roles.sql to create the per-tenant reader roles.'
            );
        }

        $pdo = $connection->getPdo();

        // READ ONLY on the transaction rather than on the session: it cannot
        // be switched off from inside the statement it wraps, and it is gone
        // again on rollback, so the connection the dashboard's own cards
        // share is handed back exactly as it was found.
        $pdo->exec('BEGIN ISOLATION LEVEL READ COMMITTED, READ ONLY');

        try {
            // SET LOCAL dies with the transaction. The value is an int
            // property, never user input, so interpolating it is safe;
            // Postgres takes no bind parameters in SET anyway.
            $pdo->exec(sprintf('SET LOCAL statement_timeout = %d', $this->maxSeconds * 1000));

            $stmt = $pdo->prepare($sql);
            $stmt->execute();

            $rows = [];
            $bytes = 0;
            $deadline = hrtime(true) + $this->maxSeconds * 1_000_000_000;
            $truncatedBy = null;

            while (($row = $stmt->fetch(PDO::FETCH_ASSOC)) !== false) {
                $bytes += strlen((string) json_encode($row));

                // Checked before appending, so an oversized row is reported
                // rather than silently pushing the answer over the budget.
                if ($bytes > $this->maxBytes && $rows !== []) {
                    $truncatedBy = 'bytes';
                    break;
                }

                $rows[] = $row;

                if (count($rows) >= $maxRows) {
                    $truncatedBy = $stmt->fetch() !== false ? 'rows' : null;
                    break;
                }
                if (hrtime(true) > $deadline) {
                    $truncatedBy = 'time';
                    break;
                }
            }
        } finally {
            // Always a rollback, never a commit: a read-only transaction has
            // nothing to commit, and this runs on the error path too, where
            // leaving the transaction open would poison the shared
            // connection for every later query.
            $pdo->exec('ROLLBACK');
        }

        $result = [
            'columns' => $rows === [] ? [] : array_keys($rows[0]),
            'rows' => $rows,
            'truncated' => $truncatedBy !== null,
        ];

        if ($truncatedBy !== null) {
            $result['truncated_by'] = $truncatedBy;
        }

        return $result;
    }
}
