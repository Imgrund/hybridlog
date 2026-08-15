<?php

use Illuminate\Support\Str;

return [

    /*
    |--------------------------------------------------------------------------
    | Default Database Connection Name
    |--------------------------------------------------------------------------
    |
    | Here you may specify which of the database connections below you wish
    | to use as your default connection for database operations. This is
    | the connection which will be utilized unless another connection
    | is explicitly specified when you execute a query / statement.
    |
    */

    'default' => env('DB_CONNECTION', 'pgsql'),

    /*
    |--------------------------------------------------------------------------
    | Database Connections
    |--------------------------------------------------------------------------
    |
    | Postgres only, and the list says so. The mirror is a schema per
    | athlete with a role per schema, the SQL guard plans statements with
    | EXPLAIN and the suite asserts Postgres behaviour, so a connection
    | block for any other driver would only invite a broken install.
    |
    */

    'connections' => [

        // Read-only view onto the mirrors written by fetcher/fetch.py (or
        // fetcher/seed_demo.py while in demo mode). Same database as the app,
        // different schemas and, when it matters, a different login.
        //
        // One connection, many mirrors: there is a schema per athlete now,
        // and which one this connection is looking at is decided per use by
        // App\Garmin\Mirror rather than here. That class also switches the
        // connection into the tenant's reader role, which is what makes the
        // separation a privilege rather than a convention.
        //
        // GARMIN_DB_URL is what turns the full privilege split on: point it
        // at the garmin_reader role from database/postgres/roles.sql and
        // model-written SQL physically cannot reach the users table. Left
        // unset, this falls back to the app's own credentials, which is fine
        // for local work and for a demo, and is exactly the configuration
        // the SQL guard in App\Garmin\ReadOnlyGarminQuery is the last line
        // of defense for.
        //
        // search_path is why no query in the app carries a schema name: for
        // this connection "days" resolves to the acting athlete's mirror,
        // for the app's own connection "users" resolves to public.users.
        'garmin' => [
            'driver' => 'pgsql',
            // Same fallback as the app's own connection, for the same
            // reason: a platform that hands out one connection string
            // hands out exactly one. Where that is all there is, the
            // privilege split is simply off, and search_path below is
            // what still keeps the app's tables out of reach of a query
            // written on this connection.
            'url' => env('GARMIN_DB_URL') ?: (env('DB_URL') ?: env('DATABASE_URL')),
            'host' => env('GARMIN_DB_HOST', env('DB_HOST', '127.0.0.1')),
            'port' => env('GARMIN_DB_PORT', env('DB_PORT', '5432')),
            'database' => env('GARMIN_DB_DATABASE', env('DB_DATABASE', 'garmin')),
            // garmin_reader as the last resort, not the app user: a deploy
            // that forgets this variable should end up with too few rights
            // rather than too many. Empty counts as unset here too.
            'username' => env('GARMIN_DB_USERNAME', env('DB_USERNAME', 'garmin_reader')) ?: null,
            'password' => env('GARMIN_DB_PASSWORD', env('DB_PASSWORD')) ?: null,
            'charset' => env('DB_CHARSET', 'utf8'),
            'prefix' => '',
            'prefix_indexes' => true,
            // Deliberately a schema that does not exist. The mirror is per
            // athlete now (garmin_t{user id}), so there is no value here
            // that would be right for every connection, and App\Garmin\Mirror
            // sets the real one after connecting. What a schema name is
            // still good for is the failure: a query that reaches the mirror
            // without naming a tenant ends in "relation days does not exist"
            // rather than in whoever happens to be first.
            'search_path' => env('GARMIN_DB_SCHEMA', 'garmin_no_tenant'),
            'sslmode' => env('DB_SSLMODE', 'prefer'),
        ],

        'pgsql' => [
            'driver' => 'pgsql',
            // DATABASE_URL as the fallback because that is the name every
            // managed platform uses for the database it provisioned, and
            // fetcher/fetch.py already reads it. Setting one variable is
            // then enough to point the whole application at a database,
            // which is what "attach a Postgres" leaves you with.
            'url' => env('DB_URL') ?: env('DATABASE_URL'),
            'host' => env('DB_HOST', '127.0.0.1'),
            'port' => env('DB_PORT', '5432'),
            'database' => env('DB_DATABASE', 'laravel'),
            // No default user on purpose. Laravel ships "root" here, which is
            // a MySQL name that no Postgres installation creates, so it can
            // only ever produce "role root does not exist". Handing libpq a
            // null instead lets it use PGUSER or the operating system account,
            // which is how a Homebrew or Docker Postgres is reachable without
            // any configuration at all. Empty counts as unset so a test run
            // can ask for that fallback explicitly.
            'username' => env('DB_USERNAME') ?: null,
            'password' => env('DB_PASSWORD') ?: null,
            'charset' => env('DB_CHARSET', 'utf8'),
            'prefix' => '',
            'prefix_indexes' => true,
            'search_path' => 'public',
            'sslmode' => env('DB_SSLMODE', 'prefer'),
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Migration Repository Table
    |--------------------------------------------------------------------------
    |
    | This table keeps track of all the migrations that have already run for
    | your application. Using this information, we can determine which of
    | the migrations on disk haven't actually been run on the database.
    |
    */

    'migrations' => [
        'table' => 'migrations',
        'update_date_on_publish' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Redis Databases
    |--------------------------------------------------------------------------
    |
    | Redis is an open source, fast, and advanced key-value store that also
    | provides a richer body of commands than a typical key-value system
    | such as Memcached. You may define your connection settings here.
    |
    */

    'redis' => [

        'client' => env('REDIS_CLIENT', 'phpredis'),

        'options' => [
            'cluster' => env('REDIS_CLUSTER', 'redis'),
            'prefix' => env('REDIS_PREFIX', Str::slug((string) env('APP_NAME', 'laravel')).'-database-'),
            'persistent' => env('REDIS_PERSISTENT', false),
        ],

        'default' => [
            'url' => env('REDIS_URL'),
            'host' => env('REDIS_HOST', '127.0.0.1'),
            'username' => env('REDIS_USERNAME'),
            'password' => env('REDIS_PASSWORD'),
            'port' => env('REDIS_PORT', '6379'),
            'database' => env('REDIS_DB', '0'),
            'max_retries' => env('REDIS_MAX_RETRIES', 3),
            'backoff_algorithm' => env('REDIS_BACKOFF_ALGORITHM', 'decorrelated_jitter'),
            'backoff_base' => env('REDIS_BACKOFF_BASE', 100),
            'backoff_cap' => env('REDIS_BACKOFF_CAP', 1000),
        ],

        'cache' => [
            'url' => env('REDIS_URL'),
            'host' => env('REDIS_HOST', '127.0.0.1'),
            'username' => env('REDIS_USERNAME'),
            'password' => env('REDIS_PASSWORD'),
            'port' => env('REDIS_PORT', '6379'),
            'database' => env('REDIS_CACHE_DB', '1'),
            'max_retries' => env('REDIS_MAX_RETRIES', 3),
            'backoff_algorithm' => env('REDIS_BACKOFF_ALGORITHM', 'decorrelated_jitter'),
            'backoff_base' => env('REDIS_BACKOFF_BASE', 100),
            'backoff_cap' => env('REDIS_BACKOFF_CAP', 1000),
        ],

    ],

];
