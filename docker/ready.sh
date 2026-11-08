#!/bin/sh
# Answers one question before a worker container may replace the one that is
# running: can this container reach the database?
#
# On Railway this is the worker's pre-deploy command. A service without a
# healthcheck counts as deployed the moment its process starts, and the
# previous container is removed on the spot. A worker that then fails on
# the database restarts until the platform gives up, and by that time the
# worker that could reach it is gone, with it the scheduled fetches. A
# failing pre-deploy command ends the deploy before any of that, the old
# container stays, and the log says why.
#
# migrate:status rather than a bare connection, because the worker needs the
# tables too: it is read-only, and on a first deploy it fails until the web
# service's boot has run the migrations, which is exactly what to wait for.
# Retried for the same reason boot.sh retries: "the database is running" and
# "it accepts connections" are two moments.

set -eu

cd /app

attempt=1
# artisan prints the exception to both streams, so one capture of both.
until php artisan migrate:status --no-interaction > /tmp/ready.out 2>&1; do
    if [ "$attempt" -ge 24 ]; then
        echo "ready: the database did not become reachable" >&2
        grep -m1 -E 'SQLSTATE|FATAL|could not|refused|not found' /tmp/ready.out | sed 's/^ *//' >&2 || true
        exit 1
    fi
    echo "ready: database not ready, retrying ($attempt/24)"
    attempt=$((attempt + 1))
    sleep 5
done

rm -f /tmp/ready.out
echo "ready: database reachable"
