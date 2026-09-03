# Installing without Docker, deploying, backup

The quickstart in the [README](../README.md) covers Docker. This is
everything underneath it: a manual install, what a platform needs, and
what to copy before anything breaks.

## Setup without Docker

Needs PHP 8.3+, Composer, Node 20+, Python 3.12+ and a PostgreSQL 14+ you
can create a database in.

```bash
createdb garmin
cp .env.example .env
# .env: DB_DATABASE/DB_USERNAME/DB_PASSWORD, or a single DB_URL

composer install
npm ci && npm run build
php artisan key:generate
php artisan migrate

# The mirror's tables live outside Laravel's migrations, because the
# fetcher has to be able to create them without a PHP runtime present:
python3 -m venv fetcher/venv
fetcher/venv/bin/pip install -r fetcher/requirements.txt
fetcher/venv/bin/python fetcher/fetch.py --schema-only

fetcher/venv/bin/python fetcher/seed_demo.py      # or login.py for real data
php artisan serve
```

`--schema-only` builds the first athlete's mirror and the shared
`garmin_private` schema and returns without calling Garmin at all. It is
idempotent, and it is what the container runs on every boot. Another
athlete's mirror is `--tenant 2`, and the dashboard builds it by itself
the first time that account looks at it.

Serve the app for real use with `php artisan serve --no-reload` and
`PHP_CLI_SERVER_WORKERS=4` in `.env`. Only `--no-reload` hands the port to
PHP's built-in server with several workers; with the file watcher active
one slow MCP call blocks the dashboard page.

### One database, one schema per athlete

Everything lives in one PostgreSQL database, split by schema:

| Schema           | Holds                                               |
|------------------|-----------------------------------------------------|
| `public`         | Laravel: users, symptom log, settings, OAuth tokens |
| `garmin_t{id}`   | one athlete's mirror of Garmin Connect              |
| `garmin_private` | every athlete's Garmin session token, by user id    |

An athlete's mirror is named after their `users.id`, so the owner's is
`garmin_t1`. No query in the app or in the MCP tools names it: the
connection's `search_path` does that (`App\Garmin\Mirror`), which is why
the same statement returns a different athlete's numbers depending on who
asked.

`search_path` decides what an unqualified name means and nothing more,
though, and free SQL may always name a schema in full. What isolates is a
role per athlete: `garmin_reader_t{id}` holds `USAGE` on one schema and
`SELECT` on its tables, the connection switches into it before every read,
and reaching another mirror fails in the server rather than in the
application. That switch is a real drop in privilege even from a
superuser, which is what makes it work on a platform that hands out a
single connection string. `database/postgres/tenant.sql` is the file, and
the application runs it when it provisions an athlete.

The default configuration uses one login role, which is what a local
installation and a managed platform both hand you. Where the dashboard is
reachable from outside, run `database/postgres/roles.sql` once to split it
into three:

```bash
psql -v ON_ERROR_STOP=1 \
     -v app_password="$APP_PW" \
     -v fetch_password="$FETCH_PW" \
     -v reader_password="$READER_PW" \
     -d garmin -f database/postgres/roles.sql
database/postgres/verify_roles.sh          # asserts the grants actually hold
```

`garmin_app` owns `public` and has no rights in any mirror, `garmin_fetch`
is the only writer of the mirrors and has no rights in `public`,
`garmin_reader` is the login role the dashboard reads through and holds no
data privileges at all: it is a member of every athlete's reader role and
switches into one before each read. That last one is the point: the MCP
server lets a language model write its own SQL, and under a reader role no
statement it can compose reaches the users table, the OAuth tokens or
another athlete's mirror, whatever it says. The statement blocklist in
`app/Garmin/ReadOnlyGarminQuery` is the second line of defence, not the
first. Point `GARMIN_DB_URL` and `GARMIN_FETCH_DSN` at the respective
roles; both fall back to `DB_URL` and then to `DATABASE_URL`, so on a
one-role installation there is nothing to set.

One asymmetry is worth knowing if you develop against the same Postgres
you run: schemas belong to a database, roles belong to the whole cluster.
So `garmin_test` and a real `garmin` share every `garmin_reader_t{id}`
between them, and the suite's cleanup, which drops the roles it made,
would drop the one the dashboard reads through.
`GARMIN_READER_ROLE_PREFIX` is why it does not: `phpunit.xml` gives the
suite a prefix of its own. Nothing else has to set it.

### Who starts the fetcher

A fetch is never run inside a request. The refresh button and the MCP
`refresh-data` tool both dispatch a job (the button for the last seven
days, the tool for today and yesterday), a `queue:work` worker runs the
fetcher, and Laravel's scheduler (`schedule:work`) holds the times from
`GARMIN_FETCH_TIMES`. Both processes have to be running, otherwise the
refresh button enqueues a fetch that nothing picks up.

`GARMIN_FETCH_COMMAND` is how the fetcher is invoked; empty means the
virtualenv above. `GARMIN_FETCH_TIMEOUT` (900 s) is when a running fetch
is killed, and the queue worker's own `--timeout` has to stay above it.

## Deploying to a platform

`Dockerfile` builds one image, started two ways: `docker/serve.sh` for the
dashboard, `docker/work.sh` for the queue worker and the scheduler, which
share a container because neither is busy and a container costs money.
Two worked examples carry the same pair: `railway.toml` plus
`railway.worker.toml` with a file per service, `fly.toml` with two process
groups in one. Any platform that builds a Dockerfile works the same way,
and one that would rather deploy a finished image than build one can be
pointed at `ghcr.io/imgrund/hybridlog` instead; the two entrypoints and
everything below are the same either way.

Five things a container needs and a laptop does not:

- **`DATABASE_URL`** is all the database configuration there is. Both
  `config/database.php` and `fetcher/fetch.py` fall back to it, which is
  the variable every managed Postgres sets when it attaches.
- **`TRUSTED_PROXIES`** must name whatever terminates TLS, or Laravel
  reads every request as insecure and builds `http://` OAuth redirects
  that no connector accepts. On a platform the router sits at an address
  that changes with every deploy and is the only way in at all, so `*` is
  the honest answer there. The default is the loopback pair a local
  reverse proxy forwards from. `TRUSTED_HOSTS` pins the accepted host
  names against a forged `Host` header; a name that is missing from it is
  answered with a 400, including the name a platform's own health check
  arrives under.
- **`PASSPORT_PRIVATE_KEY` / `PASSPORT_PUBLIC_KEY`**, because a container
  discards its filesystem on every deploy, and signing keys written into
  `storage/` would sign the claude.ai connector out again with the next
  one. Generate them outside and set them as secrets, see the header of
  `fly.toml`.
- **`GARMIN_RAW_DIR`** empty, so the raw JSON archive is not written into
  a layer that is thrown away anyway.
- **`PORT`**, if the platform assigns one rather than accepting the 80 the
  image listens on. Nothing to set: `docker/serve.sh` reads it and starts
  the server there. It is named here because a container that ignores it
  answers a 502 with an empty log on both sides.

`docker/boot.sh` is the release command: it generates a key if there is
none, migrates with a retry loop while the database finishes starting,
generates the OAuth signing keys only where no secret already carries
them, and builds the mirror's schema. It is safe to run on every deploy,
and it belongs to exactly one service: two copies racing each other
through the same migrations is not a state worth discovering.

The Garmin login is needed about once a year and is done at
`/connect/garmin` on the deployed dashboard, worker included. The session
lives in `garmin_private.garmin_session` rather than in a file, so a
console is only the fallback: `railway ssh` or
`fly ssh console -C "/opt/fetcher/bin/python fetcher/login.py"` inside the
container, or `fetcher/login.py` on your own machine with
`GARMIN_FETCH_DSN` pointing at the database's public URL.

## Backup

What is worth keeping is schema `public` (users, the symptom log,
connector and push settings), and a plain `pg_dump -n public` is the
whole story.
The mirror is deliberately not worth dumping: it can be rebuilt from
Garmin. Neither is `garmin_private`, because a session token belongs in a
backup as little as `.env` does. A managed platform snapshots the whole
database on its own, which for a single-athlete installation is usually
where this ends.
