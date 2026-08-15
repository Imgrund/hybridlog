<?php

namespace App\Http\Controllers;

use App\Garmin\GarminData;
use App\Garmin\GarminLogin;
use App\Models\GarminLoginAttempt;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\View\View;

/**
 * The Garmin sign-in flow: without a stored Garmin session there is
 * nothing for the fetcher, the dashboard or the AI to look at.
 */
class GarminLoginController extends Controller
{
    public function __construct(private GarminData $garmin) {}

    /**
     * The Garmin sign-in page.
     *
     * Separate from /connect, which is about the AI connector. This one
     * is about the data itself: without a stored Garmin session there is
     * nothing for the AI or the dashboard to look at.
     */
    public function connectGarmin(Request $request): View
    {
        return view('connect-garmin', [
            'status' => $this->garmin->dataStatus(),
            'attempt' => GarminLoginAttempt::currentFor($request->user()),
            'lastFetch' => $this->garmin->latestFetch(),
        ]);
    }

    /**
     * Starts a sign-in. The password goes straight into the job and is
     * never held by the request beyond this line.
     */
    public function startGarminLogin(Request $request, GarminLogin $login): RedirectResponse
    {
        $data = $request->validate([
            'email' => ['required', 'string', 'max:120'],
            'password' => ['required', 'string', 'max:200'],
        ]);

        // A Garmin account locks after a handful of bad passwords, so the
        // page must not be usable as a place to try them quickly.
        if (! RateLimiter::attempt('garmin-login', 5, fn () => true, 600)) {
            return redirect()->route('connect.garmin')->with('login_throttled', true);
        }

        $login->start($data['email'], $data['password'], $request->user());

        return redirect()->route('connect.garmin');
    }

    /**
     * Hands the MFA code to the login that is waiting for it.
     *
     * Only into an attempt that actually asked: writing a code onto a
     * finished attempt would leave it lying in the table for nothing.
     */
    public function submitGarminMfa(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:20'],
        ]);

        $attempt = GarminLoginAttempt::currentFor($request->user());

        if ($attempt !== null && $attempt->status === GarminLoginAttempt::MFA_REQUIRED) {
            $attempt->update(['mfa_code' => trim($data['code'])]);
        }

        return redirect()->route('connect.garmin');
    }

    /**
     * How far the sign-in has got, for the page that is watching it.
     *
     * The worker is doing the work in another container, so the browser
     * has no other way to learn that Garmin has asked for a code, which
     * it does within seconds and stops waiting for after five minutes.
     */
    public function garminLoginStatus(Request $request): JsonResponse
    {
        $attempt = GarminLoginAttempt::currentFor($request->user());

        return response()->json([
            'status' => $attempt?->status,
            'account' => $attempt?->account,
            'error' => $attempt?->error,
            'finished' => $attempt?->isFinished() ?? true,
        ]);
    }
}
