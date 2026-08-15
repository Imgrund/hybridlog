<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\ConnectController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\FetchController;
use App\Http\Controllers\GarminLoginController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\InvitationController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\PushController;
use App\Http\Controllers\SetupController;
use Illuminate\Support\Facades\Route;

// The only address open to everyone, and the reason it is not in either
// group below: signed in it is the dashboard it has always been, signed
// out it is the guide. It keeps the name `dashboard` because that is what
// every link home in this app asks for, and both readers are home here.
Route::get('/', HomeController::class)->name('dashboard');

// The address to send an invited athlete: the whole way from a password
// to a working chat connector, in order. Open to everyone and outside
// the guest group, because it is read before the first sign-in and again
// halfway through, when there is a session.
Route::get('/setup', SetupController::class)->name('setup');

Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthController::class, 'showLogin'])->name('login');
    Route::post('/login', [AuthController::class, 'login'])
        ->middleware('throttle:login')
        ->name('login.attempt');
});

/*
 * The only route in this application that brings an account into being,
 * and it opens for one address that the owner named, once.
 *
 * This is still not a sign-up: without a token issued by `app:invite`
 * there is nothing here, and the 404 for a bad one says as much as it
 * would to somebody guessing. Guest, because a reader with a session
 * has an account already. not-demo, because a shop window that mints
 * real accounts has stopped being one. Throttled, because it is the one
 * public endpoint whose whole purpose is to be tried with a secret.
 */
Route::middleware(['guest', 'not-demo', 'throttle:invite'])->group(function () {
    Route::get('/invite/{token}', [InvitationController::class, 'show'])->name('invite.show');
    Route::post('/invite/{token}', [InvitationController::class, 'accept'])->name('invite.accept');
});

Route::middleware('auth')->group(function () {
    Route::get('/api/dashboard/charts', [DashboardController::class, 'charts'])->name('dashboard.charts');
    Route::get('/profile', [ProfileController::class, 'profile'])->name('profile');
    // The interface language survives demo mode below: it changes nothing
    // anybody else has to live with, and the nightly reset puts it back.
    Route::post('/profile/language', [ProfileController::class, 'updateLocale'])->name('profile.locale');
    Route::post('/logout', [AuthController::class, 'logout'])->name('logout');
    Route::get('/fetch/status', [FetchController::class, 'fetchStatus'])->name('fetch.status');

    /*
     * Everything a public demo keeps shut, in one list.
     *
     * All of it reaches out of the installation on the signed-in
     * account's behalf, and on a demo that account belongs to whoever
     * happens to be visiting: a Garmin password, a token for a chat
     * client, a phone subscribed to somebody else's ledger, a fetch
     * against an account that does not exist. Grouped rather than
     * checked inside each controller, so the list can be read here.
     * See config/demo.php and App\Http\Middleware\EnsureNotDemo.
     *
     * What is not in the list because it does not exist: a page that
     * changes the email or the password. Were such a form ever added it
     * belongs in this group, because on a shared account either field
     * locks the next visitor out until the nightly reset.
     *
     * Accounts themselves are made at a terminal with `app:create-user`,
     * or by the invited person at /invite above. That route creates an
     * account and so is closed on a demo too, but it carries its own
     * not-demo rather than sitting here: this group is behind the
     * sign-in, and the whole point of an invitation is that its holder
     * does not have one yet.
     */
    Route::middleware('not-demo')->group(function () {
        // In this list for the reason the list exists, though what it
        // changes is only a line on a profile: saving a town sends a
        // name to Open-Meteo's geocoder, and on a demo that request
        // goes out on the installation's behalf for whoever is passing
        // through. The language switch above stays open because it
        // reaches nobody.
        Route::post('/profile/location', [ProfileController::class, 'updateLocation'])->name('profile.location');
        Route::get('/connect', [ConnectController::class, 'connect'])->name('connect');
        Route::post('/connect/permissions', [ConnectController::class, 'updatePermissions'])->name('connect.permissions');
        Route::post('/connect/disconnect', [ConnectController::class, 'disconnect'])->name('connect.disconnect');
        Route::post('/connect/guidelines/{guideline}/delete', [ConnectController::class, 'deleteGuideline'])->name('connect.guidelines.delete');
        // The Garmin side of "connect": the sign-in that gives the fetcher a
        // session. Separate page from the AI connector above, because these
        // are two different connections that break in two different ways.
        Route::get('/connect/garmin', [GarminLoginController::class, 'connectGarmin'])->name('connect.garmin');
        Route::post('/connect/garmin', [GarminLoginController::class, 'startGarminLogin'])->name('connect.garmin.start');
        Route::post('/connect/garmin/code', [GarminLoginController::class, 'submitGarminMfa'])->name('connect.garmin.mfa');
        Route::get('/connect/garmin/status', [GarminLoginController::class, 'garminLoginStatus'])->name('connect.garmin.status');
        Route::post('/fetch', [FetchController::class, 'fetchNow'])->name('fetch.now');
        // And the short way there: a notification while the window is still in
        // memory, and the one page it opens. /push/next is what the service
        // worker asks before it shows anything, so it stays behind the sign-in
        // like every other route that touches health data.
        Route::get('/connect/notifications', [PushController::class, 'settings'])->name('connect.notifications');
        Route::post('/push/subscribe', [PushController::class, 'subscribe'])->name('push.subscribe');
        Route::post('/push/unsubscribe', [PushController::class, 'unsubscribe'])->name('push.unsubscribe');
        Route::get('/push/next', [PushController::class, 'next'])->name('push.next');
    });
});
