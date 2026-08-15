#!/usr/bin/env bash
# The queue worker and the scheduler, in one container.
#
# They are two processes because Laravel splits them that way, not because
# either is busy: the scheduler spends its day counting minutes and the
# worker sleeps until a fetch is due. Where a container costs money, giving
# each of them their own doubles the bill for that.
#
# The rule that makes sharing one safe is below: if either process ends, the
# container ends with it. A scheduler that quietly died while the worker
# kept running is a dashboard that stops updating with nothing in it looking
# broken, and that is the failure worth designing against.
#
# bash rather than sh, for `wait -n`. compose keeps the two apart, where a
# container costs nothing and two log streams are easier to read than one.

set -uo pipefail

cd /app

# --tries=1 because a fetch that failed on a broken Garmin session fails the
# same way three times, and each attempt is a few minutes of API calls. The
# --timeout here does not govern the fetch: RunGarminFetch carries its own
# (GARMIN_FETCH_TIMEOUT plus a margin), and a job's own timeout takes
# precedence over the worker's. What this flag bounds is every job without
# one of its own (the notification senders), none of which has business
# running for twenty minutes.
php artisan queue:work --tries=1 --timeout=1200 &
worker=$!

php artisan schedule:work &
scheduler=$!

# A deploy sends SIGTERM. Passed on rather than swallowed, because
# queue:work uses it to finish the job it is currently holding before it
# exits. Killed outright, a fetch in flight is lost halfway through.
shutdown() {
    trap - TERM INT
    kill -TERM "$worker" "$scheduler" 2>/dev/null || true
    wait
    exit 0
}
trap shutdown TERM INT

# Returns as soon as the first of the two ends, whichever it is and for
# whatever reason.
wait -n
status=$?

echo "work: one of the two processes ended (exit $status), stopping the container" >&2

kill -TERM "$worker" "$scheduler" 2>/dev/null || true
wait 2>/dev/null || true

exit "$status"
