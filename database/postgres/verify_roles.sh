#!/bin/bash
# verify_roles.sh - proves that the read-only role really is read-only.
#
# The MCP server hands model-written SQL to Postgres under the garmin_reader
# role. Everything that keeps that from becoming a problem lives in grants,
# not in application code, and grants are easy to get subtly wrong: one
# GRANT ALL during a debugging session and the boundary is gone with nothing
# failing to show it. This script tries the attacks and fails loudly.
#
# Run it after database/postgres/roles.sql, after any grant change, and on
# the production database once after the first deploy.
#
# Since multi-tenancy there are two boundaries to prove, not one. The old
# question was what garmin_reader may do; the new one is what it may do
# before it has named a tenant, and what one tenant's reader may reach of
# another's. So the checks below come in three parts: the empty-handed
# connection, the connection switched into tenant 1, and the attack across.
#
# Usage:
#   GARMIN_READER_PASSWORD=... database/postgres/verify_roles.sh [database]
#
# PGHOST/PGPORT are honoured if set; database defaults to $PGDATABASE or
# "garmin". GARMIN_OTHER_TENANT names a second tenant to attack, default 2;
# the cross-tenant checks are skipped when that schema does not exist.
# Exits non-zero if any check fails.
set -uo pipefail

DB="${1:-${PGDATABASE:-garmin}}"
USER_NAME="${GARMIN_READER_USER:-garmin_reader}"
TENANT="${GARMIN_TENANT:-1}"
OTHER="${GARMIN_OTHER_TENANT:-2}"

# Every check that is meant to succeed runs as the tenant's reader, the way
# the application does it: connect as garmin_reader, then switch.
AS_TENANT="set role garmin_reader_t${TENANT}; set search_path = garmin_t${TENANT};"

if [ -z "${GARMIN_READER_PASSWORD:-}" ]; then
    echo "ERROR: set GARMIN_READER_PASSWORD to the password of $USER_NAME" >&2
    exit 2
fi

PASS=0
FAIL=0

# run <allow|deny> <label> <sql>
run() {
    local expect="$1" label="$2" sql="$3" out rc
    out=$(PGPASSWORD="$GARMIN_READER_PASSWORD" psql -qtAX -U "$USER_NAME" -d "$DB" -c "$sql" 2>&1)
    rc=$?

    if [ "$expect" = allow ]; then
        if [ $rc -eq 0 ]; then
            printf '  ok    %-44s\n' "$label"
            PASS=$((PASS + 1))
        else
            printf '  FAIL  %-44s expected success: %s\n' "$label" "$(echo "$out" | head -1)"
            FAIL=$((FAIL + 1))
        fi
    else
        if [ $rc -ne 0 ]; then
            printf '  ok    %-44s blocked\n' "$label"
            PASS=$((PASS + 1))
        else
            printf '  FAIL  %-44s ALLOWED, returned: %s\n' "$label" "$(echo "$out" | head -1)"
            FAIL=$((FAIL + 1))
        fi
    fi
}

echo "Verifying $USER_NAME on database $DB, as tenant $TENANT"
echo
echo "before a tenant is named, nothing is readable"
# The fail-closed half: a connection whose bootstrap did not run holds no
# data privileges at all, so it cannot fall back on somebody's mirror.
run deny  "read a mirror without switching"     "select count(*) from garmin_t${TENANT}.days;"
run deny  "read a mirror by search_path"        "set search_path = garmin_t${TENANT}; select count(*) from days;"

echo
echo "what the tenant's reader must be able to do"
run allow "read the mirror"                     "$AS_TENANT select count(*) from days;"
run allow "introspect the mirror"               "$AS_TENANT select count(*) from information_schema.columns where table_schema='garmin_t${TENANT}';"

echo
echo "application data must be unreachable"
run deny  "read the users table"                "$AS_TENANT select * from public.users;"
run deny  "read the oauth tokens"               "$AS_TENANT select * from public.oauth_access_tokens;"
run deny  "reach public via search_path"        "$AS_TENANT set search_path=public; select * from users;"

echo
echo "the garmin session tokens must be unreachable"
run deny  "read the session tokens"             "$AS_TENANT select tokens from garmin_private.garmin_session;"

echo
echo "another athlete's mirror must be unreachable"
if psql -qtAX -d "$DB" -c "select 1 from pg_namespace where nspname='garmin_t${OTHER}'" 2>/dev/null | grep -q 1; then
    run deny "read the other mirror by name"    "$AS_TENANT select count(*) from garmin_t${OTHER}.days;"
    run deny "introspect the other mirror"      "$AS_TENANT select * from garmin_t${OTHER}.fetch_log;"
    run deny "become the other tenant's reader" "$AS_TENANT set role garmin_reader_t${OTHER};"
else
    printf '  skip  %-44s no schema garmin_t%s on this database\n' "cross-tenant checks" "$OTHER"
fi

echo
echo "nothing may be written"
# Each write runs in its own statement after switching the read-only flag
# off, because that flag is a session setting the role may change itself.
# What must stop these is the missing grant underneath it.
run deny  "insert into the mirror"              "$AS_TENANT set default_transaction_read_only=off; insert into days(date) values ('1999-01-01');"
run deny  "update the mirror"                   "$AS_TENANT set default_transaction_read_only=off; update days set steps=0;"
run deny  "delete from the mirror"              "$AS_TENANT set default_transaction_read_only=off; delete from days;"
run deny  "drop a table"                        "$AS_TENANT set default_transaction_read_only=off; drop table days;"
run deny  "create a table"                      "$AS_TENANT set default_transaction_read_only=off; create table garmin_t${TENANT}.evil(x int);"
run deny  "create a temp table"                 "$AS_TENANT set default_transaction_read_only=off; create temp table evil(x int);"

echo
echo "the host must be out of reach"
run deny  "run a program via COPY"               "$AS_TENANT copy days from program 'id';"
run deny  "write a file via COPY"                "$AS_TENANT copy (select 1) to '/tmp/pwned.csv';"
run deny  "read a host file"                     "$AS_TENANT select pg_read_file('/etc/passwd');"
run deny  "list a directory"                     "$AS_TENANT select pg_ls_dir('/');"
run deny  "import a large object"                "$AS_TENANT select lo_import('/etc/passwd');"
run deny  "install an extension"                 "$AS_TENANT create extension dblink;"
run deny  "sleep the connection"                 "$AS_TENANT select pg_sleep(30);"

echo
echo "credentials and other roles"
run deny  "read password hashes"                 "$AS_TENANT select rolname, rolpassword from pg_authid;"
run deny  "become the app role"                  "$AS_TENANT set role garmin_app;"

echo
echo "passed: $PASS   failed: $FAIL"
if [ "$FAIL" -ne 0 ]; then
    echo
    echo "The read-only boundary is NOT intact. Re-run database/postgres/roles.sql" >&2
    echo "and check for grants added by hand since." >&2
    exit 1
fi
