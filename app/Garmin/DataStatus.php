<?php

namespace App\Garmin;

use Carbon\Carbon;

/**
 * One verdict on how trustworthy the mirror currently is, shared by the
 * dashboard header and the MCP tools so both tell the same story.
 *
 * Four distinct reasons make the data look old, and they need different
 * fixes: nobody has connected the installation to Garmin yet (sign in
 * once), the stored Garmin session expired (sign in again, a refresh
 * cannot help), the scheduled fetch stopped running (a refresh is the
 * right first step), or the watch simply has not uploaded to Garmin
 * Connect lately (sync the watch first).
 */
class DataStatus
{
    /**
     * The fetch job runs three times a day; the longest regular gap
     * (21:00 to 09:30) stays under this, so beyond it the job itself
     * is in trouble, not the schedule.
     */
    private const FETCH_STALE_AFTER_HOURS = 14;

    /**
     * How fetcher/fetch.py marks "there has never been a session" as
     * opposed to "the session stopped working". Both land in fetch_log
     * as a failed login, and only the class name tells them apart.
     */
    private const NEVER_CONNECTED_MARKER = 'NotConnected';

    private function __construct(
        public readonly string $state, // fresh|watch_stale|fetch_stale|auth_broken|not_connected
        public readonly ?string $hint, // user-facing, in the interface language
        public readonly ?string $lastFetch,
        public readonly ?Carbon $watchSyncedAt,
        public readonly ?string $authError,
        public readonly ?string $authFailedAt,
    ) {}

    public static function evaluate(
        ?string $lastFetch,
        ?Carbon $watchSyncedAt,
        ?object $authFailure,
        ?Carbon $now = null,
        bool $seeded = false,
    ): self {
        $now ??= Carbon::now();
        $fetchAt = $lastFetch !== null ? Carbon::parse($lastFetch) : null;

        if ($authFailure !== null) {
            $error = (string) ($authFailure->error ?? '');

            // Never connected is its own answer. Telling someone their
            // login "expired" when they have not made one yet sends them
            // looking for a password problem that does not exist.
            if (str_starts_with($error, self::NEVER_CONNECTED_MARKER)) {
                return new self(
                    'not_connected',
                    __('This installation is not connected to Garmin yet. Sign in to Garmin once on the connection page, then the fetch can run.'),
                    $lastFetch,
                    $watchSyncedAt,
                    $authFailure->error ?? null,
                    $authFailure->fetched_at,
                );
            }

            $since = Carbon::parse($authFailure->fetched_at);

            return new self(
                'auth_broken',
                __('The Garmin login has expired (since :since).', ['since' => $since->isoFormat(__('MMM D, HH:mm'))])
                .' '
                .($fetchAt !== null
                    ? __('Until you sign in to Garmin again on the connection page, every value stays as it was on :when.', [
                        'when' => $fetchAt->isoFormat(__('MMM D, HH:mm')),
                    ])
                    : __('Until you sign in to Garmin again on the connection page, no value will change.')),
                $lastFetch,
                $watchSyncedAt,
                $authFailure->error ?? null,
                $authFailure->fetched_at,
            );
        }

        // A mirror somebody filled with fetcher/seed_demo.py. Its
        // bookkeeping is deliberately shaped like a real run's, so that a
        // complete set of days does not sit under a stale-data warning,
        // and that is exactly what made a fresh Quickstart installation
        // announce "Garmin connected" over an account it had never seen:
        // the log is the seed's, and every reading below derives from the
        // log. The days carry the seed's own mark, so the question can be
        // asked of the data instead, and the answer is the same one a
        // first fetch attempt would have written a moment later.
        //
        // Ranked below a login failure on purpose. A stored session that
        // stopped working is news; that the numbers under it were seeded
        // is not, and "sign in again" is the more useful of the two.
        if ($seeded) {
            return new self(
                'not_connected',
                __('The numbers here come from the demo seed rather than from Garmin. Sign in to Garmin once on the connection page, and the first fetch replaces them with your own.'),
                $lastFetch,
                $watchSyncedAt,
                null,
                null,
            );
        }

        if ($fetchAt === null || $fetchAt->diffInHours($now) >= self::FETCH_STALE_AFTER_HOURS) {
            return new self(
                'fetch_stale',
                $fetchAt === null
                    ? __('No Garmin fetch yet: the fetch job has never written any data.')
                    : __('Last Garmin fetch :when: the scheduled fetch appears to have stopped running, so the values are out of date.', [
                        'when' => $fetchAt->isoFormat(__('MMM D, HH:mm')),
                    ]),
                $lastFetch,
                $watchSyncedAt,
                null,
                null,
            );
        }

        $watch = WatchSync::describe($watchSyncedAt, $now);
        if ($watch !== null && $watch['stale']) {
            return new self(
                'watch_stale',
                __('The watch has not synced with Garmin for :duration. New values only arrive after a sync from the Garmin Connect app.', [
                    'duration' => $watch['label'],
                ]),
                $lastFetch,
                $watchSyncedAt,
                null,
                null,
            );
        }

        return new self('fresh', null, $lastFetch, $watchSyncedAt, null, null);
    }

    /** The two states a Garmin sign-in is the fix for. */
    public function needsSignIn(): bool
    {
        return in_array($this->state, ['not_connected', 'auth_broken'], true);
    }

    /**
     * The same fact as hint, in one clause, for a line next to a button.
     *
     * hint is the standing explanation and names the page to go to. Put
     * word for word under the header line that already carries it, it
     * reads as a second, different problem. This says what stopped the
     * fetch and leaves the instruction to the button beside it.
     */
    public function reason(): ?string
    {
        return match ($this->state) {
            'not_connected' => __('No Garmin session is stored yet.'),
            'auth_broken' => __('The stored Garmin session no longer works.'),
            default => null,
        };
    }

    /** What the button next to reason() should say, where there is one. */
    public function signInLabel(): ?string
    {
        return match ($this->state) {
            'not_connected' => __('Sign in to Garmin'),
            'auth_broken' => __('Sign in again'),
            default => null,
        };
    }

    /** Compact, null-free form for the MCP payload. */
    public function toMcpArray(): array
    {
        return array_filter([
            'state' => $this->state,
            'hint' => $this->hint,
            'last_fetch' => $this->lastFetch,
            'watch_last_sync' => $this->watchSyncedAt?->toIso8601String(),
            'auth_failed_at' => $this->authFailedAt,
            'auth_error' => $this->authError,
            // Carried only where it is the answer, so the model can hand
            // over a link instead of a shell command the athlete cannot
            // run from a chat on their phone. It is a page behind the
            // dashboard login: nothing here signs anyone in.
            'sign_in_url' => $this->needsSignIn() ? route('connect.garmin') : null,
        ], fn ($v) => $v !== null);
    }
}
