#!/bin/sh
# Everything that has to happen once before a container can serve requests.
#
# Once per deploy, not once per process: compose runs it as its own service
# and the others wait for it to finish, on Fly.io it is the release command.
# Running it twice is harmless, running it in parallel with itself is not
# worth finding out, which is why it has one caller in each place.

set -eu

cd /app

# Docker creates a directory when a bind-mounted file is missing on the
# host, and a directory named .env fails in a way nobody guesses from the
# message. Say what happened instead.
if [ -d .env ]; then
    echo "boot: /app/.env is a directory, which means there was no .env on the host." >&2
    echo "boot: run 'rmdir .env && cp .env.example .env', then start again." >&2
    exit 1
fi

# --- The application key -----------------------------------------------
# Without it every encrypted cookie and every session is unreadable, so the
# app refuses to boot. A real environment variable wins (that is how a
# platform holds secrets); the .env file is the local case, where compose
# mounts it from the host and the key written here therefore survives the
# next `docker compose up`.
if [ -z "${APP_KEY:-}" ] && ! grep -qE '^APP_KEY=.+' .env 2>/dev/null; then
    if [ -w .env ]; then
        echo "boot: no application key yet, generating one into .env"
        php artisan key:generate --force --no-interaction
    else
        echo "boot: APP_KEY is not set and .env is not writable." >&2
        echo "boot: generate one with 'php artisan key:generate --show' and set it as a secret." >&2
        exit 1
    fi
fi

# --- The application's own tables ---------------------------------------
# Retried rather than ordered, because "the database container is running"
# and "Postgres is accepting connections" are two different moments, and on
# a platform the database may be a few seconds behind the release command.
attempt=1
until php artisan migrate --force --no-interaction; do
    if [ "$attempt" -ge 12 ]; then
        echo "boot: the database did not become reachable" >&2
        exit 1
    fi
    echo "boot: database not ready, retrying ($attempt/12)"
    attempt=$((attempt + 1))
    sleep 5
done

# --- The OAuth signing keys ---------------------------------------------
# What the claude.ai connector's tokens are signed with, and what the api
# guard builds itself from on every request, connector or not, which is
# why a missing pair is not the connector's problem alone.
#
# They end up in .env rather than in storage/, for the same reason the
# application key above does: this step is a container of its own that is
# gone a second later and takes its storage/ with it, so a key file
# written here would be read by none of the three services that then
# serve. .env is the one thing they all read and the host keeps.
#
# An installation that already holds the pair is left alone, whether it
# carries it as real environment variables (that is how a platform holds
# secrets) or in .env from an earlier boot: a second pair would sign every
# paired connector out again.

# A PEM is several lines and an environment variable is one, so the line
# breaks travel as the two characters \ and n, which is what Laravel's
# dotenv reads back as a break. The carriage returns are dropped rather
# than carried along: the generator writes the pair with CRLF endings, and
# a CR left inside the value ends the line for dotenv a second time, so
# the key arrives with a blank line between every two and openssl refuses
# it. printf rather than echo, because dash's echo would turn the two
# characters into the break here, in the middle of the value.
one_line() {
    awk 'BEGIN { ORS = "" } { sub(/\r$/, ""); print $0 "\\n" }' "$1"
}

if [ -z "${PASSPORT_PRIVATE_KEY:-}" ] && ! grep -qE '^PASSPORT_PRIVATE_KEY=.+' .env 2>/dev/null; then
    # A pair already on disk is published below rather than replaced: an
    # installation that keeps storage/ by itself has connectors paired
    # against exactly those two files. --force reaches no further than a
    # public key left behind without its private half.
    if [ ! -f storage/oauth-private.key ]; then
        php artisan passport:keys --force --no-interaction
    fi

    if [ -w .env ]; then
        echo "boot: writing the OAuth signing keys into .env"
        # Appended rather than rewritten, because compose bind-mounts this
        # one file: a rewrite that replaces it would leave every container
        # holding the file that used to be there.
        {
            printf '\n# Generated on the first boot by docker/boot.sh. Keeping them means\n'
            printf '# the AI connector stays signed in across a restart or a rebuild.\n'
            printf 'PASSPORT_PRIVATE_KEY="%s"\n' "$(one_line storage/oauth-private.key)"
            printf 'PASSPORT_PUBLIC_KEY="%s"\n' "$(one_line storage/oauth-public.key)"
        } >>.env
    else
        echo "boot: .env is not writable, so the keys stay in storage/ and last only as long as this container." >&2
        echo "boot: set PASSPORT_PRIVATE_KEY and PASSPORT_PUBLIC_KEY as secrets to keep the connector signed in." >&2
    fi
fi

# --- The mirror's tables -------------------------------------------------
# The dashboard queries a mirror schema on every page, and a schema that
# does not exist is an error rather than an empty result.
#
# Since the mirror became one schema per athlete there are two ways it gets
# built, and both ran before this line: the migration above provisions every
# account that exists, and the application provisions an account's mirror
# the first time it reads one. What is left for this step is the case
# neither covers, a container that boots before anybody has an account, so
# that the first athlete's schema is there rather than made on the first
# page load. It names tenant 1 because that is who the first account is.
/opt/fetcher/bin/python fetcher/fetch.py --schema-only --tenant 1

echo "boot: ready"
