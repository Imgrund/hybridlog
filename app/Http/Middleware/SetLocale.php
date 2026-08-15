<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\AthleteProfile;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Symfony\Component\HttpFoundation\Response;

/**
 * Which language the interface speaks, decided per request in three steps:
 * the reader's stored choice, then what their browser asks for, then the
 * deployment's APP_LOCALE.
 *
 * The browser step sits in the middle on purpose. English is the source
 * language, so an installation that never opened the profile page would
 * otherwise greet a German athlete in English while their browser has been
 * saying "de" all along. A stored choice still wins over the header,
 * because it was made deliberately and travels between devices.
 */
class SetLocale
{
    public function handle(Request $request, Closure $next): Response
    {
        $locale = $this->stored($request) ?? $this->fromBrowser($request) ?? (string) config('app.locale');

        // Carbon follows along: AppServiceProvider ties it to the LocaleUpdated
        // event this fires, so dates never end up in a different language than
        // the words around them.
        App::setLocale($locale);

        return $next($request);
    }

    /**
     * The signed-in reader's stored choice, read without creating a
     * profile row: a middleware has no business writing to the database.
     * The login page has no reader yet, so it follows the browser: a
     * multi-tenant door cannot speak any one tenant's language.
     */
    private function stored(Request $request): ?string
    {
        $locale = $request->user()?->athleteProfile?->locale;

        return in_array($locale, AthleteProfile::LOCALES, true) ? $locale : null;
    }

    /**
     * The first language from Accept-Language the interface is actually
     * translated into. Region matters to nobody here, so "de-AT" and
     * "de_DE" both resolve to "de".
     */
    private function fromBrowser(Request $request): ?string
    {
        foreach ($request->getLanguages() as $language) {
            $primary = strtolower(explode('-', str_replace('_', '-', $language))[0]);

            if (in_array($primary, AthleteProfile::LOCALES, true)) {
                return $primary;
            }
        }

        return null;
    }
}
