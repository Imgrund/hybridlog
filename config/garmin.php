<?php

/*
 * Deployment-specific settings of the Garmin side: how the fetcher is run
 * and the hook that delivers health alerts. Everything here describes the
 * machine the dashboard runs on, not the athlete using it.
 *
 * A fetch is always dispatched onto the queue and picked up by a worker,
 * so nothing in the request waits for the minutes a fetch takes. That
 * needs a running worker: without one the job is enqueued and sits there.
 * docker/work.sh starts one next to the scheduler.
 */

/*
 * How to run fetcher/fetch.py. The default is the virtualenv the README
 * tells a local installation to create; an image that installs the
 * requirements system-wide sets this to "python3 /app/fetcher/fetch.py"
 * instead. Run through a shell, so an interpreter plus its script is one
 * string.
 */
$fetchCommand = env('GARMIN_FETCH_COMMAND')
    ?: base_path('fetcher/venv/bin/python').' '.base_path('fetcher/fetch.py');

return [

    'fetch' => [

        'command' => $fetchCommand,

        /*
         * Seconds before the fetcher is killed. Sized by the slowest run
         * that is still healthy, which is the first fetch of a newly
         * connected athlete: ninety days at fourteen throttled calls each
         * (CALL_DELAY_S plus Garmin's own latency) plus the activity
         * details is twenty minutes and more, and the old 900 killed
         * exactly that run. A regular run needs about a minute and never
         * comes near this ceiling; a fetcher that has actually hung is
         * caught by the stall notice on the page long before the kill.
         *
         * The queue job carries this value plus a margin as its own
         * timeout (RunGarminFetch), which takes precedence over the
         * worker's --timeout, so nothing else has to move with it.
         */
        'timeout' => (int) env('GARMIN_FETCH_TIMEOUT', 2700),

        /*
         * Seconds the MCP refresh tool waits for the running fetch before
         * answering "still running". One tool call has to finish inside
         * the connector's own timeout AND inside whatever proxy sits in
         * front of the app (Railway's edge, for one), so this stays well
         * below both: the model is told it can call again to resume the
         * wait, and the follow-up calls cover the rest of the minute. It
         * also bounds how long one blocked FrankenPHP worker is tied up.
         */
        'wait_seconds' => (int) env('GARMIN_FETCH_WAIT_SECONDS', 25),

        // Pause between two looks at fetch_log while waiting.
        'wait_poll_seconds' => 2,

        /*
         * When the scheduler runs the fetch, as HH:MM in the app's
         * timezone. Three times: after the morning watch sync, at midday,
         * and late enough that the readiness snapshot reflects the day's
         * training rather than the wake-up state.
         */
        'times' => array_values(array_filter(array_map(
            trim(...),
            explode(',', (string) env('GARMIN_FETCH_TIMES', '09:30,13:00,21:00'))
        ))),

        /*
         * How far back the first fetch of a newly connected athlete
         * reaches. Ninety days because that is what the dashboard opens
         * on: a shorter history would leave the default range visibly
         * short of its left edge on the first day, and the two load
         * models behind the page (CTL over 42 days, the 7:28 ratio) need
         * a quarter of a year before they mean anything.
         *
         * It costs one long run per athlete, once. Garmin serves the
         * daily summaries for all of it; the detailed streams it keeps
         * for a shorter window, so the early weeks fill in thinner than
         * the recent ones. See App\Garmin\GarminLogin.
         */
        'first_connect_days' => (int) env('GARMIN_FIRST_CONNECT_DAYS', 90),
    ],

    /*
     * Signing in to Garmin, which the connection page does through a
     * worker (see App\Garmin\GarminLogin). It runs the same interpreter
     * as the fetch, one script over: an installation that configures the
     * fetch command has the login command for free, and only a layout
     * that puts the two scripts in different places needs its own value.
     */
    'login' => [

        'command' => env('GARMIN_LOGIN_COMMAND')
            ?: str_replace('fetch.py', 'login.py', $fetchCommand),

        /*
         * Seconds before the login process is killed. Generous, because
         * most of it is spent waiting for a person to read an MFA code
         * out of their inbox, and a login killed underneath them is a
         * login they have to start over.
         */
        'timeout' => (int) env('GARMIN_LOGIN_TIMEOUT', 600),

        // Seconds to wait for Garmin to answer one step of the login.
        'step_timeout' => (int) env('GARMIN_LOGIN_STEP_TIMEOUT', 120),

        // Seconds the login holds while the athlete fetches the code.
        'mfa_timeout' => (int) env('GARMIN_LOGIN_MFA_TIMEOUT', 300),
    ],

    /*
     * Command that receives a health alert, called with the message as its
     * single argument (see HealthAlertsCommand). Empty means the alert run
     * still evaluates its rules and logs them, it just delivers nothing,
     * which is the right default for an installation that has no notifier.
     */
    'alert_command' => env('GARMIN_ALERT_COMMAND'),

    /*
     * What an athlete's reader role is called, before the user id.
     *
     * Schemas belong to a database; roles belong to the whole Postgres
     * cluster. So a test database living beside a real one shares this
     * name with it, and the suite's cleanup, which drops every role
     * matching the prefix, would drop the role a real installation reads
     * through. That is a laptop's problem and nobody else's: CI has only
     * the test database, and a deployment has only the real one. The
     * suite therefore sets a prefix of its own (phpunit.xml) and nothing
     * else ever needs to.
     */
    'reader_prefix' => env('GARMIN_READER_ROLE_PREFIX', 'garmin_reader_t'),

];
