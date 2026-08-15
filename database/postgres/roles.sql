-- Role setup for the Garmin dashboard. Run once per database, as a user
-- that may create roles (the cluster owner locally, the default superuser
-- on Fly/Railway/Neon):
--
--   psql -v ON_ERROR_STOP=1 \
--        -v app_password="$APP_PW" \
--        -v fetch_password="$FETCH_PW" \
--        -v reader_password="$READER_PW" \
--        -d garmin -f database/postgres/roles.sql
--
-- Idempotent: rerunning it resets the passwords to what you pass in and
-- leaves everything else as it is.
--
-- Why three roles instead of one connection string
-- ------------------------------------------------
-- The MCP server lets a language model write its own SQL against the Garmin
-- mirror. On SQLite that was contained by physics: the mirror was a
-- different file from the app database, so no query could reach the users
-- table no matter what it said. One Postgres database loses that boundary,
-- and privileges have to re-create it:
--
--   garmin_app     owns public: users, cards, insights, oauth tokens.
--                  No rights on any mirror schema at all.
--   garmin_fetch   owns the mirror schemas + garmin_private and is the
--                  only writer. No rights on public.
--   garmin_reader  the role the app's mirror connection logs in as. Holds
--                  no data privileges of its own: it is a member of one
--                  reader role per tenant and switches into the right one
--                  with SET ROLE. Model-written SQL runs as that role.
--
-- Since multi-tenancy the mirror is not one schema but one per athlete,
-- garmin_t{user id}, each with its own reader role garmin_reader_t{id}.
-- What a tenant needs is in database/postgres/tenant.sql, which the
-- application runs when it provisions one. This file sets up the three
-- roles they hang off, and provisions the first athlete so a fresh
-- installation has a mirror to fetch into.
--
-- The reader being an ordinary NOSUPERUSER role is what disarms the
-- Postgres-specific escapes: COPY ... TO PROGRAM needs membership in
-- pg_execute_server_program, pg_read_file needs pg_read_server_files, and
-- lo_import/lo_export are superuser-only. None of that is granted below.
-- The statement blocklist in the application is the second line, not the
-- first: if it ever fails, this file still holds.

\set ON_ERROR_STOP on

-- Roles. LOGIN only, no inheritance of anything, and explicitly not
-- superusers: a superuser silently ignores every grant below.
DO $$
BEGIN
    IF NOT EXISTS (SELECT FROM pg_roles WHERE rolname = 'garmin_app') THEN
        CREATE ROLE garmin_app LOGIN;
    END IF;
    IF NOT EXISTS (SELECT FROM pg_roles WHERE rolname = 'garmin_fetch') THEN
        CREATE ROLE garmin_fetch LOGIN;
    END IF;
    IF NOT EXISTS (SELECT FROM pg_roles WHERE rolname = 'garmin_reader') THEN
        CREATE ROLE garmin_reader LOGIN;
    END IF;
END
$$;

ALTER ROLE garmin_app    WITH PASSWORD :'app_password'    NOSUPERUSER NOCREATEDB NOCREATEROLE NOREPLICATION NOBYPASSRLS;
ALTER ROLE garmin_fetch  WITH PASSWORD :'fetch_password'  NOSUPERUSER NOCREATEDB NOCREATEROLE NOREPLICATION NOBYPASSRLS;
ALTER ROLE garmin_reader WITH PASSWORD :'reader_password' NOSUPERUSER NOCREATEDB NOCREATEROLE NOREPLICATION NOBYPASSRLS;

-- Schemas. Created here rather than by whoever connects first, so the
-- ownership is deliberate instead of accidental.
--
-- garmin_private is the shared one: it holds one Garmin session row per
-- tenant, reached by id. The mirrors themselves are per tenant and are
-- created below.
CREATE SCHEMA IF NOT EXISTS garmin_private AUTHORIZATION garmin_fetch;
ALTER SCHEMA garmin_private OWNER TO garmin_fetch;

-- The first athlete's mirror. Every installation has one, and without a
-- schema to fetch into the first run of fetch.py would have to create it,
-- which is a privilege the writer deliberately does not have.
--
-- A second athlete's schema is created the same way, by the application
-- when their account is provisioned, or here by hand:
--
--   CREATE SCHEMA IF NOT EXISTS garmin_t2 AUTHORIZATION garmin_fetch;
--
-- followed by database/postgres/tenant.sql with the names substituted.
CREATE SCHEMA IF NOT EXISTS garmin_t1 AUTHORIZATION garmin_fetch;
ALTER SCHEMA garmin_t1 OWNER TO garmin_fetch;

-- Database-level. Every role connects; nobody gets to create schemas or
-- temp tables (a temp table is a write, and none of these roles has a
-- reason to make one).
GRANT CONNECT ON DATABASE :"DBNAME" TO garmin_app, garmin_fetch, garmin_reader;
REVOKE TEMPORARY ON DATABASE :"DBNAME" FROM PUBLIC, garmin_app, garmin_fetch, garmin_reader;
REVOKE CREATE ON DATABASE :"DBNAME" FROM PUBLIC;

-- public belongs to the app alone. Since Postgres 15 the PUBLIC pseudo-role
-- no longer gets CREATE here, but it still gets USAGE, so the revoke below
-- is what actually keeps the other two out.
ALTER SCHEMA public OWNER TO garmin_app;
REVOKE ALL ON SCHEMA public FROM PUBLIC;
GRANT ALL ON SCHEMA public TO garmin_app;
REVOKE ALL ON ALL TABLES    IN SCHEMA public FROM garmin_fetch, garmin_reader;
REVOKE ALL ON ALL SEQUENCES IN SCHEMA public FROM garmin_fetch, garmin_reader;
REVOKE ALL ON ALL FUNCTIONS IN SCHEMA public FROM garmin_fetch, garmin_reader;
ALTER DEFAULT PRIVILEGES FOR ROLE garmin_app IN SCHEMA public
    REVOKE ALL ON TABLES FROM garmin_fetch, garmin_reader;

-- The mirror: the fetcher writes it, a tenant's reader role reads it, the
-- app never touches it directly (it goes through the mirror connection like
-- the MCP server does).
--
-- garmin_reader is granted nothing here on purpose. It reaches a mirror
-- only by switching into that tenant's reader role, so a connection that
-- has not named a tenant holds no data privileges at all. Everything a
-- tenant needs is in tenant.sql; this is the first athlete's run of it.
REVOKE ALL ON SCHEMA garmin_t1 FROM garmin_app;

DO $$
BEGIN
    IF NOT EXISTS (SELECT FROM pg_roles WHERE rolname = 'garmin_reader_t1') THEN
        CREATE ROLE garmin_reader_t1 NOLOGIN NOINHERIT NOSUPERUSER NOCREATEDB NOCREATEROLE NOREPLICATION NOBYPASSRLS;
    END IF;
END
$$;

GRANT USAGE ON SCHEMA garmin_t1 TO garmin_reader_t1;
GRANT SELECT ON ALL TABLES IN SCHEMA garmin_t1 TO garmin_reader_t1;
REVOKE ALL ON SCHEMA garmin_private FROM garmin_reader_t1;

-- schema.sql adds tables over time. Without this, every new table would be
-- invisible to the dashboard until someone remembered to grant it, and the
-- failure mode is a card that returns "permission denied" months later.
ALTER DEFAULT PRIVILEGES FOR ROLE garmin_fetch IN SCHEMA garmin_t1
    GRANT SELECT ON TABLES TO garmin_reader_t1;

-- Membership without inheritance: garmin_reader may become tenant 1's
-- reader, and holds none of its privileges until it does. See tenant.sql
-- for why that distinction is the fail-closed half of the design.
DO $$
BEGIN
    IF current_setting('server_version_num')::int >= 160000 THEN
        EXECUTE 'GRANT garmin_reader_t1 TO garmin_reader WITH INHERIT FALSE';
    ELSE
        EXECUTE 'GRANT garmin_reader_t1 TO garmin_reader';
    END IF;
END
$$;

-- The Garmin session tokens. Nobody but the fetcher, ever.
REVOKE ALL ON SCHEMA garmin_private FROM PUBLIC, garmin_app, garmin_reader;
ALTER DEFAULT PRIVILEGES FOR ROLE garmin_fetch IN SCHEMA garmin_private
    REVOKE ALL ON TABLES FROM PUBLIC, garmin_app, garmin_reader;

-- Belt and braces on the escape hatches. These are superuser-only or
-- role-gated already; revoking from PUBLIC costs nothing and means a future
-- "just add the extension" does not quietly widen the reader.
REVOKE EXECUTE ON FUNCTION pg_read_file(text)            FROM PUBLIC;
REVOKE EXECUTE ON FUNCTION pg_read_binary_file(text)     FROM PUBLIC;
REVOKE EXECUTE ON FUNCTION pg_ls_dir(text)               FROM PUBLIC;
REVOKE EXECUTE ON FUNCTION pg_sleep(double precision)    FROM PUBLIC;
GRANT  EXECUTE ON FUNCTION pg_sleep(double precision)    TO garmin_app;

-- The search_path each role gets on connect. The app's queries name no
-- schema, so this is what makes "select * from users" mean the app tables
-- for the app.
--
-- The reader gets an empty one, which is the point: with the mirror split
-- per tenant there is no schema that is right for every connection, and an
-- empty search_path means an unqualified name resolves to nothing until
-- the connection says whose mirror it is reading. A bootstrap that fails
-- to run therefore ends in "relation days does not exist" rather than in
-- somebody else's numbers.
ALTER ROLE garmin_app    IN DATABASE :"DBNAME" SET search_path = public;
ALTER ROLE garmin_fetch  IN DATABASE :"DBNAME" SET search_path = garmin_private;
ALTER ROLE garmin_reader IN DATABASE :"DBNAME" SET search_path = '';

-- A reader that cannot be talked into running long. 10s matches the
-- application-side limit in ReadOnlyGarminQuery, but the server enforces
-- this one, so it also covers the case the application limit misses: a
-- query that spends all its time inside one expensive join before it
-- returns a first row. Verified 2026-07-28 against a 300k x 300k
-- generate_series join, which the server cancelled.
--
-- Honest about the limit: a role may raise its own statement_timeout with
-- a plain SET, so this is a guard rail, not a cage. It holds because the
-- application only ever sends a single SELECT with no semicolon in it, so
-- there is no room for a SET to ride along. The same is true of
-- default_transaction_read_only below. Neither is load-bearing on its own,
-- which is why every write path is *also* denied by a missing grant:
-- switching the flag off and retrying still ends in "permission denied for
-- table days" rather than a write (verified the same day).
ALTER ROLE garmin_reader IN DATABASE :"DBNAME" SET statement_timeout = '10s';
ALTER ROLE garmin_reader IN DATABASE :"DBNAME" SET default_transaction_read_only = on;
ALTER ROLE garmin_reader IN DATABASE :"DBNAME" SET idle_in_transaction_session_timeout = '30s';
