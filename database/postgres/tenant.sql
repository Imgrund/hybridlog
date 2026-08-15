-- One athlete's mirror privileges: the reader role that model-written SQL
-- runs as, and nothing else.
--
-- Executed by App\Garmin\Mirror::provision() with the four names below
-- substituted, the way fetcher/schema.sql is executed with {mirror}. Not a
-- psql script: it is run by the application, on the same connection that
-- just created the schema, so it carries no \set and no psql variables.
--
--   {mirror}   the tenant's schema, garmin_t{id}
--   {reader}   the tenant's reader role, garmin_reader_t{id}
--   {owner}    who owns {mirror} and therefore creates its tables
--   {login}    the role the app's mirror connection logs in as
--
-- Idempotent, and rerun after every schema change: fetcher/schema.sql adds
-- tables over time, and a table created after the grants were written would
-- otherwise be invisible to the reader until someone noticed.
--
-- Why a role per tenant rather than one reader and a search_path
-- --------------------------------------------------------------
-- search_path decides what an unqualified name means. It decides nothing
-- about what a qualified one may reach, and the two MCP tools that hand a
-- language model a SQL prompt (query-health-data, describe-schema) can
-- write "select * from garmin_t1.days" as easily as "select * from days".
-- Only privileges answer that, so each athlete gets a role that holds
-- USAGE on one schema and SELECT on its tables, and holds nothing anywhere
-- else. Reaching another tenant then fails in the server with "permission
-- denied for schema", before the query is planned.
--
-- The role is NOLOGIN and has no password: nobody connects as it. The app
-- connects as {login} and switches with SET ROLE, which is a real drop in
-- privilege even from a superuser (verified on PostgreSQL 17 and 18, and
-- what makes this work on a platform that hands out one connection string).

DO $$
BEGIN
    IF NOT EXISTS (SELECT FROM pg_roles WHERE rolname = '{reader}') THEN
        -- NOINHERIT so that this role, too, only ever holds what it is
        -- granted directly. It is granted nothing else today; the attribute
        -- is what keeps that true if it ever is.
        EXECUTE 'CREATE ROLE {reader} NOLOGIN NOINHERIT NOSUPERUSER NOCREATEDB NOCREATEROLE NOREPLICATION NOBYPASSRLS';
    END IF;
END
$$;

GRANT USAGE ON SCHEMA {mirror} TO {reader};
GRANT SELECT ON ALL TABLES IN SCHEMA {mirror} TO {reader};

-- The tables that do not exist yet. Bound to the schema's owner because
-- default privileges follow whoever creates the table, and that is the
-- fetcher, not whoever ran this file.
ALTER DEFAULT PRIVILEGES FOR ROLE {owner} IN SCHEMA {mirror}
    GRANT SELECT ON TABLES TO {reader};

-- Nothing outside the mirror, said explicitly rather than left to the
-- absence of a grant. garmin_private holds the Garmin session tokens of
-- every tenant, which is the one place where a missing REVOKE would be
-- expensive.
--
-- Conditional because this file may run before that schema exists: the
-- grants of a mirror have to be in place before its tables are created
-- (see ALTER DEFAULT PRIVILEGES above), and fetcher/schema.sql, which
-- creates garmin_private, is what creates those tables. A schema made
-- afterwards is not a hole: unlike `public`, a newly created schema grants
-- nothing to PUBLIC and therefore nothing to this role. The REVOKE is the
-- second lock, not the first.
DO $$
BEGIN
    IF EXISTS (SELECT FROM pg_namespace WHERE nspname = 'garmin_private') THEN
        EXECUTE 'REVOKE ALL ON SCHEMA garmin_private FROM {reader}';
    END IF;
END
$$;

-- Membership, so that {login} may SET ROLE into this. WITH INHERIT FALSE
-- is the whole point of the grant: membership alone would hand {login} the
-- privileges of every tenant's reader at once, and a connection that
-- forgot to switch would quietly see all of them. With inheritance off,
-- {login} holds nothing until it names a tenant, which is the fail-closed
-- half of the arrangement.
--
-- Servers before 16 have no INHERIT option on GRANT. There the plain form
-- is used and the isolation rests on the SET ROLE in the connection
-- bootstrap alone, which App\Garmin\Mirror verifies on every free-SQL call
-- either way.
DO $$
BEGIN
    IF current_setting('server_version_num')::int >= 160000 THEN
        EXECUTE 'GRANT {reader} TO {login} WITH INHERIT FALSE';
    ELSE
        EXECUTE 'GRANT {reader} TO {login}';
    END IF;
END
$$;
