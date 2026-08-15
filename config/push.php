<?php

/*
 * Web push: the notifications that shorten the distance between the
 * data and the moment it is worth a glance.
 *
 * Off until the installation has a key pair. `php artisan push:keys`
 * makes one and prints the two lines below; there is no shared pair,
 * because the public half is the identity every browser subscription on
 * this installation is bound to.
 */
return [

    'vapid' => [

        // The applicationServerKey the browser subscribes with, base64url.
        'public_key' => env('VAPID_PUBLIC_KEY', ''),

        'private_key' => env('VAPID_PRIVATE_KEY', ''),

        /*
         * Who the push service can complain to about this sender, as
         * RFC 8292 asks for. A mailto: or an https: URL; the address is
         * seen by the push service, not by anybody else.
         */
        'subject' => env('VAPID_SUBJECT', 'mailto:admin@localhost'),
    ],

];
