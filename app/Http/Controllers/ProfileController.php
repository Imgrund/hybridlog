<?php

namespace App\Http\Controllers;

use App\Garmin\PlaceLookup;
use App\Models\AthleteProfile;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** The profile page: the interface language and where the athlete trains. */
class ProfileController extends Controller
{
    public function profile(Request $request): View
    {
        return view('profile', [
            'profile' => AthleteProfile::for($request->user()),
        ]);
    }

    /**
     * The reader's interface language. An empty answer clears the column,
     * and that is a real choice rather than a missing one: it hands the
     * decision back to the browser's Accept-Language header instead of
     * freezing today's language into the database.
     */
    public function updateLocale(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'locale' => ['nullable', Rule::in(AthleteProfile::LOCALES)],
        ], [
            'locale.in' => __('The interface is not translated into that language.'),
        ]);

        AthleteProfile::for($request->user())->update(['locale' => $validated['locale'] ?? null]);

        return redirect()->route('profile')->with('locale_saved', true);
    }

    /**
     * Where this athlete trains, which is what their weather is read at.
     *
     * An empty answer clears the place and hands the athlete back to the
     * installation's own location, the same way an empty language hands
     * them back to the browser. A name that resolves to nothing is a
     * different case and says so, because silently keeping the old place
     * would leave the page claiming a location the athlete just replaced.
     */
    public function updateLocation(Request $request, PlaceLookup $places): RedirectResponse
    {
        $validated = $request->validate([
            'place' => ['nullable', 'string', 'max:120'],
        ]);

        $profile = AthleteProfile::for($request->user());
        $query = trim((string) ($validated['place'] ?? ''));

        if ($query === '') {
            $profile->update(['latitude' => null, 'longitude' => null, 'location_name' => null]);

            return redirect()->route('profile')->with('location_cleared', true);
        }

        $hit = $places->search($query, app()->getLocale());

        if ($hit === null) {
            return redirect()->route('profile')
                ->withInput()
                ->withErrors(['place' => __('No place found by that name. Try the town rather than the street.')]);
        }

        $profile->update([
            'latitude' => $hit['latitude'],
            'longitude' => $hit['longitude'],
            'location_name' => $hit['name'],
        ]);

        return redirect()->route('profile')->with('location_saved', $hit['name']);
    }
}
