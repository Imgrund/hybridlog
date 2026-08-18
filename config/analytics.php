<?php

/*
 * Umami, for an installation that is a shop window rather than one
 * person's own dashboard.
 *
 * A public demo that nobody counts is a demo nobody can improve: whether
 * anyone arrives at all, which page a first-time visitor stops at,
 * whether they ever get as far as the tour. Umami answers that from the
 * request itself. It sets no cookie and keeps no identifier that follows
 * a visitor off this site, which is the whole reason it is the one
 * outside script this app is willing to carry.
 *
 * Off unless both lines are filled, and off is the default, because a
 * dashboard holding one person's health record has no business phoning
 * anywhere. Empty here means not a byte of third-party JavaScript in any
 * page, no request to any host but this one, and nothing to explain to
 * anybody. Half a pair would count into the void, so it counts as off
 * as well.
 *
 * Nothing here is tied to DEMO_MODE. The demo is the reason this exists,
 * but the decision is the operator's, and an installation that serves
 * more than its owner may well want the same answer.
 */

return [

    'umami' => [

        /*
         * The full URL of the tracker script on the operator's own Umami,
         * e.g. https://analytics.example.com/script.js. That host is also
         * where the browser reports to, so a self-hosted instance keeps
         * the visits on infrastructure the operator answers for instead
         * of handing them to somebody else's analytics business.
         */
        'script_url' => env('UMAMI_SCRIPT_URL', ''),

        /*
         * The site Umami files these page views under, which it hands out
         * when the site is added there. Public by nature: it travels in
         * the source of every page it counts, and it unlocks nothing.
         */
        'website_id' => env('UMAMI_WEBSITE_ID', ''),
    ],

];
