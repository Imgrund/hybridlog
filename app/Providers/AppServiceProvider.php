<?php

namespace App\Providers;

use App\Demo\DemoMode;
use App\Push\Vapid;
use DateInterval;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Events\LocaleUpdated;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Laravel\Passport\Passport;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // The push signer reads three config values and nothing else, so it
        // is built here rather than pulled out of config at each of the four
        // places that send or subscribe.
        $this->app->singleton(Vapid::class, function (): Vapid {
            // A public demo must not ring anybody's phone. Everyone there
            // is signed in to the same account, so a subscription is a
            // stranger's device on a shared ledger, and the notification
            // it would carry is somebody else's morning. Withholding the
            // key pair is how that is said once: WebPush and the settings
            // page both already read "no keys" as "push is off here".
            $demo = DemoMode::enabled();

            return new Vapid(
                $demo ? '' : (string) config('push.vapid.public_key'),
                $demo ? '' : (string) config('push.vapid.private_key'),
                (string) config('push.vapid.subject'),
            );
        });

        // Passport registers /oauth/authorize, /oauth/token and the device
        // flow from its own provider, so the demo guard cannot be named on
        // them the way routes/web.php names it. This is the hook Passport
        // offers: a middleware group it reads while registering, which it
        // does in its boot(), after every provider's register() has run.
        // Added whatever the mode, because the middleware is a pass-through
        // when demo mode is off, and a route cache built on a normal
        // installation should still carry the guard.
        config(['passport.middleware' => array_merge(
            (array) config('passport.middleware', []), ['not-demo']
        )]);
    }

    public function boot(): void
    {
        // Dates speak the interface language too: month names, "3 days ago".
        // Laravel does not push its locale into Carbon, so the two are tied
        // together here, once at boot for the console and the MCP server,
        // and again whenever a request picks a different language.
        Carbon::setLocale(config('app.locale'));
        Event::listen(LocaleUpdated::class, fn (LocaleUpdated $event) => Carbon::setLocale($event->locale));

        Passport::authorizationView('auth.oauth.authorize');

        Passport::tokensCan([
            'mcp:use' => __('Access to the Garmin health MCP tools (read data, manage dashboard cards and insights)'),
        ]);

        // The host is on the public internet, so a leaked access token must go
        // stale fast. Connectors stay signed in via the refresh token, which
        // Passport rotates on every use. (Passport's default is a full year.)
        Passport::tokensExpireIn(new DateInterval('PT1H'));
        Passport::refreshTokensExpireIn(new DateInterval('P30D'));

        // A single athlete's connector: a real conversation makes a handful
        // of calls, and query-health-data may cost up to 10 s of Postgres
        // per call, so a generous limit here is a self-DoS envelope rather
        // than headroom.
        RateLimiter::for('mcp', function (Request $request) {
            return Limit::perMinute(30)->by($request->user()?->id ?? $request->ip());
        });

        // The app is reachable from the public internet, so the login is
        // brute-force bait. Two limits: a burst limit per credential pair and a
        // slower per-IP limit that also catches low-and-slow attempts.
        RateLimiter::for('login', function (Request $request) {
            $credential = Str::transliterate(Str::lower((string) $request->input('email')));

            return [
                Limit::perMinute(5)->by($credential.'|'.$request->ip()),
                Limit::perHour(20)->by($request->ip()),
            ];
        });

        // The invitation endpoint is the one public surface whose whole
        // purpose is to be tried with a secret, so it is the one worth
        // counting. A real holder needs one attempt, or two if they
        // mistype the confirmation.
        RateLimiter::for('invite', function (Request $request) {
            return [
                Limit::perMinute(10)->by($request->ip()),
                Limit::perHour(60)->by($request->ip()),
            ];
        });

        // Dynamic client registration is unauthenticated by spec; one connector
        // setup needs a single call, so a tight limit costs nothing legitimate.
        RateLimiter::for('oauth-register', function (Request $request) {
            return Limit::perHour(10)->by($request->ip());
        });
    }
}
