<?php

/*
 * Demo mode: what a copy of this dashboard is allowed to do once it is
 * standing on the open internet with its password written next to it.
 *
 * A demo is one account that everybody signs in to. That is the whole
 * point of it and also the whole problem: the visitor at the keyboard is
 * a stranger, and every surface that reaches out of the installation
 * would reach out on somebody else's behalf. Nobody must be able to type
 * their real Garmin password into a page a stranger set up; nobody must
 * be able to hand an AI client a token to a shared account, subscribe
 * their phone to another visitor's ledger, or change the password the
 * next visitor needs. So those surfaces are closed rather than merely
 * discouraged (App\Http\Middleware\EnsureNotDemo lists them).
 *
 * What stays open is everything that only reads, plus the two things a
 * visitor is meant to try: the symptom log and the interface language.
 * Both are wiped by `php artisan demo:reset` overnight anyway.
 *
 * Off by default, and it belongs off on any installation that is one
 * person's own dashboard: this is not a hardening switch, it is a
 * shop-window switch, and it takes away things an owner wants.
 */

return [

    'enabled' => (bool) env('DEMO_MODE', false),

    /*
     * The shared account, which `demo:reset` creates if it is missing and
     * puts back the way it found it if it is not. Defaults that are
     * obviously a demo's, because a public one announces them anyway; an
     * installation that runs a demo of its own sets both.
     *
     * The password is written back on every reset, so a visitor who
     * changes it (there is no page for that, but a future one would be a
     * lockout waiting to happen) has locked nobody out past midnight.
     */
    'account' => [
        'email' => env('DEMO_EMAIL', 'demo@example.com'),
        'password' => env('DEMO_PASSWORD', 'demo-demo-demo'),
    ],

    /*
     * How `demo:reset` runs fetcher/seed_demo.py. Empty derives it from
     * the fetch command the same way the Garmin sign-in derives its own
     * (see config/garmin.php): one interpreter, one script over. Only a
     * layout that keeps the two scripts apart needs its own value here.
     */
    'seed_command' => env('DEMO_SEED_COMMAND'),

];
