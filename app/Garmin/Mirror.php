<?php

declare(strict_types=1);

namespace App\Garmin;

use App\Models\User;
use App\Tenancy\ActingUser;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use PDO;
use RuntimeException;
use Throwable;
use WeakMap;

/**
 * One athlete's Garmin mirror: which schema it is, and how a connection is
 * pointed at it.
 *
 * Until multi-tenancy the answer was a constant. There was one schema named
 * `garmin`, the connection's search_path was set to it in config, and no
 * query in the app or in the MCP tools ever named a schema. That is still
 * true of the queries. What changed is that the schema is now the acting
 * user's, `garmin_t{id}`, and something has to say so before the first
 * statement goes out.
 *
 * Two settings do it, and they are not interchangeable:
 *
 *   search_path  decides what an unqualified name means. It is what keeps
 *                every existing query working unchanged.
 *   SET ROLE     decides what the connection may reach at all. It is the
 *                only one of the two that isolates, because a query may
 *                always name a schema in full, and the two MCP tools that
 *                hand a language model a SQL prompt can do exactly that.
 *
 * The role is a per-tenant NOLOGIN role holding USAGE on one schema and
 * SELECT on its tables (database/postgres/tenant.sql). Switching into it is
 * a real drop in privilege even from a superuser, which is what makes this
 * work on a platform that hands out a single connection string; reaching
 * another tenant then fails in the server, before the query is planned.
 *
 * Nothing here falls back to a default tenant. A caller with no resolvable
 * user gets an exception, never somebody else's mirror.
 */
final class Mirror
{
    /**
     * Which tenant each open PDO is currently pinned to.
     *
     * A WeakMap rather than an id-keyed array: the entry belongs to that one
     * PDO and disappears with it, so a reconnect (a new PDO behind the same
     * Connection object) is a cache miss and gets pinned again instead of
     * inheriting the old one's state. The plan's warning about SET ROLE not
     * surviving a reconnect is answered here rather than by hoping.
     *
     * @var WeakMap<PDO, int>|null
     */
    private static ?WeakMap $pinned = null;

    /**
     * Tenants whose schema has been confirmed to exist in this process.
     *
     * @var array<int, true>
     */
    private static array $provisioned = [];

    /** The schema holding one athlete's mirror. */
    public static function schema(int $tenant): string
    {
        return 'garmin_t'.self::validate($tenant);
    }

    /** The role that may read it, and nothing else. */
    public static function reader(int $tenant): string
    {
        return self::readerPrefix().self::validate($tenant);
    }

    /**
     * What every reader role of this installation is called, before the id.
     *
     * Configurable for one reason, and it is not cosmetic: roles are
     * cluster-wide where schemas are per database, so a test database
     * beside a real one shares these names with it. See config/garmin.php.
     */
    public static function readerPrefix(): string
    {
        $prefix = (string) config('garmin.reader_prefix', 'garmin_reader_t');

        if (! preg_match('/^[a-z][a-z0-9_]{0,40}$/', $prefix)) {
            throw new RuntimeException('garmin.reader_prefix is not a usable role name prefix: '.$prefix);
        }

        return $prefix;
    }

    /**
     * The mirror connection, pointed at the acting user's schema.
     *
     * Every read of Garmin data goes through here rather than through
     * DB::connection('garmin'), because the connection on its own is not
     * pointed anywhere: since the mirror became per tenant, the reader role
     * carries an empty search_path and no privileges of its own.
     */
    public static function connection(): Connection
    {
        return self::forTenant(ActingUser::require()->id);
    }

    /**
     * The same, for a tenant named outright.
     *
     * For the console and the queue, where there is no request to read the
     * acting user off. The scheduled senders and the fetcher name the
     * athlete they are working for.
     */
    public static function forTenant(User|int $tenant): Connection
    {
        $id = $tenant instanceof User ? $tenant->id : $tenant;

        $connection = DB::connection('garmin');

        self::ensure($id);
        self::pin($connection, $id);

        return $connection;
    }

    /**
     * Whether this connection is currently confined to one tenant's mirror.
     *
     * The free-SQL tools ask before they run: they are the flank the role
     * exists for, so they refuse rather than fall back on search_path when
     * the switch did not happen. Every other query in the app is written
     * here in the repository and needs no such proof.
     */
    public static function isIsolated(Connection $connection, int $tenant): bool
    {
        return $connection->selectOne('select current_user as role')->role === self::reader($tenant);
    }

    /**
     * Create a tenant's mirror if it is not there: schema, tables, reader
     * role, grants.
     *
     * Idempotent and cheap to ask: the check is one catalog lookup, and a
     * confirmed tenant is remembered for the rest of the process.
     *
     * All of it happens on the mirror connection rather than the app's, and
     * that is not a detail. Creating a schema takes a lock, the app's
     * connection is the one a test holds a transaction open on, and a mirror
     * connection then reading through that lock does not fail, it waits: the
     * suite would stop rather than go red. Doing the work on the connection
     * that is about to use it keeps the DDL out of anybody's transaction.
     *
     * An installation that runs the full role split has no role here that
     * may create schemas, deliberately. There a mirror comes from
     * database/postgres/roles.sql, so the failure names it.
     */
    public static function ensure(int $tenant): void
    {
        $id = self::validate($tenant);

        if (isset(self::$provisioned[$id])) {
            return;
        }

        if (self::schemaExists($id)) {
            // A schema whose reader cannot read it is what a renamed mirror
            // looks like: the tables came across from before tenancy and
            // nothing ever granted them to anybody. Left alone the dashboard
            // would work (the login role reads it) and free SQL would refuse
            // forever, which is a confusing pair of symptoms for one missing
            // GRANT.
            //
            // The question is asked of the privilege rather than of the
            // role's existence, because those two come apart: dropping a
            // schema takes its grants with it and leaves the role behind,
            // holding nothing.
            if (! self::readerCanRead($id)) {
                try {
                    self::grant($id);
                } catch (Throwable) {
                    // An installation running the full role split cannot
                    // create roles from here, by design. roles.sql does it
                    // there, and until it has, the free-SQL tools say so.
                }
            }

            self::$provisioned[$id] = true;

            return;
        }

        try {
            self::provision($id);
        } catch (Throwable $exception) {
            throw new RuntimeException(
                'The mirror schema '.self::schema($id).' does not exist and could not be created: '
                .$exception->getMessage()
                .' Run database/postgres/roles.sql to provision this installation\'s tenants.',
                previous: $exception
            );
        }

        self::$provisioned[$id] = true;
    }

    /**
     * Build one tenant's mirror from the two files that define it.
     *
     * fetcher/schema.sql is the same file the fetcher executes on every run,
     * so a column added there reaches a new athlete without a second edit
     * here. database/postgres/tenant.sql is the privilege half.
     */
    public static function provision(int $tenant): void
    {
        $id = self::validate($tenant);
        $schema = self::schema($id);
        $connection = DB::connection('garmin');

        // Whoever asked may have been switched into some tenant's reader,
        // which is a role that may create nothing. The login role is the one
        // that provisions.
        self::unpin();

        $connection->unprepared(
            "create schema if not exists {$schema};"
            .str_replace('{mirror}', $schema, (string) file_get_contents(base_path('fetcher/schema.sql')))
        );

        self::grant($id);

        self::$provisioned[$id] = true;
    }

    /**
     * The privilege half on its own: the reader role, its grants, and the
     * default privileges that cover the tables which do not exist yet.
     *
     * Separate from provision() because the two run at different moments.
     * The tables of a mirror grow over time, written by the fetcher, and
     * what keeps them readable is not this call but the ALTER DEFAULT
     * PRIVILEGES inside it, which has to be in place *before* they are
     * created. So a mirror rebuilt from scratch (a test fixture, a restored
     * dump) asks for the grants first and fills the schema afterwards.
     */
    public static function grant(int $tenant): void
    {
        $id = self::validate($tenant);
        $schema = self::schema($id);

        self::unpin();

        DB::connection('garmin')->unprepared(strtr(
            (string) file_get_contents(base_path('database/postgres/tenant.sql')),
            [
                '{mirror}' => $schema,
                '{reader}' => self::reader($id),
                '{owner}' => self::owner($schema),
                '{login}' => self::login(),
            ]
        ));
    }

    /** Forget what this process learned; for tests that rebuild the mirror. */
    public static function forget(): void
    {
        self::$provisioned = [];
        self::$pinned = null;
    }

    /**
     * Hand the connection back to the role it logged in as, pinned to
     * nobody.
     *
     * Anything that writes to a mirror needs this first: a connection
     * switched into a tenant's reader may select and nothing else, which is
     * the whole point of the reader. Provisioning uses it, and so does a
     * test building a fixture, because both are writers.
     */
    public static function unpin(): void
    {
        $pdo = DB::connection('garmin')->getPdo();
        $pdo->exec('reset role');

        self::$pinned ??= new WeakMap;
        unset(self::$pinned[$pdo]);
    }

    /**
     * Point one connection at one tenant, if it is not already.
     *
     * RESET ROLE first: a connection already switched into another tenant's
     * reader is not a member of anything and could not switch again, so the
     * way back to the login role is part of the move rather than an
     * afterthought.
     */
    private static function pin(Connection $connection, int $tenant): void
    {
        $pdo = $connection->getPdo();
        self::$pinned ??= new WeakMap;

        if ((self::$pinned[$pdo] ?? null) === $tenant) {
            return;
        }

        // Both names are built from an integer this class validated, never
        // from anything a request carried, which is why they can be written
        // into the statement: SET takes no bind parameters.
        $pdo->exec('reset role');

        if (self::readerExists($tenant)) {
            $pdo->exec('set role '.self::reader($tenant));
        }

        $pdo->exec('set search_path = '.self::schema($tenant));

        self::$pinned[$pdo] = $tenant;
    }

    /**
     * Whether this tenant's reader may actually reach its schema.
     *
     * A privilege, not a role: those two come apart, because dropping a
     * schema takes its grants with it and leaves the role standing, holding
     * nothing. Asked of the server, which is the only party that knows.
     */
    private static function readerCanRead(int $tenant): bool
    {
        $row = DB::connection('garmin')->selectOne(
            'select has_schema_privilege(rolname, ?, \'usage\') as ok from pg_roles where rolname = ?',
            [self::schema($tenant), self::reader($tenant)]
        );

        return (bool) ($row->ok ?? false);
    }

    /**
     * Whether this tenant has a reader role to switch into.
     *
     * Asked rather than assumed because provisioning can only create roles
     * where the connecting user may create roles. Where it could not, the
     * dashboard still works off search_path and the free-SQL tools refuse,
     * which is the honest split: the app's own queries are written here,
     * the model's are not.
     */
    private static function readerExists(int $tenant): bool
    {
        return DB::connection('garmin')
            ->selectOne('select 1 as found from pg_roles where rolname = ?', [self::reader($tenant)]) !== null;
    }

    private static function schemaExists(int $tenant): bool
    {
        return DB::connection('garmin')
            ->selectOne('select 1 as found from pg_namespace where nspname = ?', [self::schema($tenant)]) !== null;
    }

    /** Who owns the schema, and therefore whose new tables need granting. */
    private static function owner(string $schema): string
    {
        $row = DB::connection('garmin')->selectOne(
            'select nspowner::regrole::text as owner from pg_namespace where nspname = ?',
            [$schema]
        );

        return (string) ($row->owner ?? 'current_user');
    }

    /** The role the mirror connection logs in as, asked of the server. */
    private static function login(): string
    {
        return (string) DB::connection('garmin')->selectOne('select session_user as role')->role;
    }

    /**
     * A tenant id is a users.id and nothing else.
     *
     * The guard is not decoration: both names built from it are written into
     * SQL that takes no bind parameters, so this is the place where that
     * stays safe.
     */
    private static function validate(int $tenant): int
    {
        if ($tenant < 1) {
            throw new RuntimeException("Not a tenant id: {$tenant}.");
        }

        return $tenant;
    }
}
