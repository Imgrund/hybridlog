<?php

namespace App\Garmin;

use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * A place name turned into the two numbers the weather needs.
 *
 * Asking an athlete for decimal degrees would be asking them to leave
 * the page and come back with homework. Open-Meteo, which already
 * serves this installation's weather, answers a name with coordinates
 * and needs no key for it, so the question on the page can stay "where
 * do you train" instead of "what is your latitude".
 *
 * The lookup happens once, when the profile is saved. Nothing in the
 * fetch path calls this: what is stored is the pair of numbers, so a
 * geocoder that is down or gone cannot stop a weather fetch.
 */
class PlaceLookup
{
    private const URL = 'https://geocoding-api.open-meteo.com/v1/search';

    /** Kept short: somebody is waiting on a form submit for this. */
    private const TIMEOUT = 6;

    /**
     * The best match for what was typed, or null.
     *
     * Null covers everything the caller has to say the same thing about:
     * no match, a malformed answer, a service that is down. The page
     * cannot act differently on those, so they do not arrive differently.
     *
     * @return array{name: string, latitude: float, longitude: float}|null
     */
    public function search(string $query, string $language = 'en'): ?array
    {
        $query = trim($query);

        if ($query === '') {
            return null;
        }

        try {
            $response = Http::timeout(self::TIMEOUT)
                ->acceptJson()
                ->get(self::URL, [
                    'name' => $query,
                    // One, because the page shows what was found and lets
                    // the athlete type something narrower if it is wrong.
                    // A disambiguation step for a field one fills once is
                    // more interface than the problem deserves.
                    'count' => 1,
                    'language' => $language,
                    'format' => 'json',
                ]);
        } catch (Throwable) {
            return null;
        }

        if (! $response->successful()) {
            return null;
        }

        $hit = $response->json('results.0');

        if (! is_array($hit) || ! isset($hit['latitude'], $hit['longitude'])) {
            return null;
        }

        return [
            'name' => self::label($hit),
            'latitude' => round((float) $hit['latitude'], 5),
            'longitude' => round((float) $hit['longitude'], 5),
        ];
    }

    /**
     * "Frankfurt, Hessen, Deutschland" rather than "Frankfurt".
     *
     * The region and the country are what let a reader see at a glance
     * that the geocoder found the Frankfurt they meant, and not the one
     * on the Oder.
     *
     * @param  array<string, mixed>  $hit
     */
    private static function label(array $hit): string
    {
        $parts = array_filter([
            $hit['name'] ?? null,
            $hit['admin1'] ?? null,
            $hit['country'] ?? null,
        ], fn ($part) => is_string($part) && trim($part) !== '');

        return implode(', ', array_unique($parts));
    }
}
