<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Garmin\Mirror;
use App\Models\Invitation;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

/**
 * Where an invited person becomes an account holder.
 *
 * The only path to a new account that does not run through a terminal,
 * and it is not a sign-up: it opens for one address, once, and only for
 * somebody holding a token the owner issued.
 *
 * A token that is unknown, spent or out of date gets a 404 rather than
 * an explanation. There is nothing useful to tell the holder of a dead
 * link that would not also tell somebody guessing which of their
 * guesses was closest.
 */
class InvitationController extends Controller
{
    public function show(string $token): View
    {
        return view('invite', ['invitation' => $this->usable($token), 'token' => $token]);
    }

    public function accept(Request $request, string $token): RedirectResponse
    {
        $invitation = $this->usable($token);

        $validated = $request->validate([
            // The same floor app:create-user holds, and confirmed here
            // because this is the one place a password is typed rather
            // than handed over: nobody is around to say it went wrong.
            'password' => ['required', 'string', 'min:10', 'confirmed'],
        ]);

        $user = DB::transaction(function () use ($invitation, $validated): User {
            $user = User::query()->create([
                'name' => $invitation->name ?: Str::before($invitation->email, '@'),
                'email' => $invitation->email,
                'password' => $validated['password'],
            ]);

            // Spent inside the transaction, so a redemption that fails
            // halfway leaves the link alive rather than burning it.
            $invitation->forceFill(['accepted_at' => now()])->save();

            return $user;
        });

        $this->provisionTheirMirror($user);

        Auth::login($user, remember: true);
        $request->session()->regenerate();

        // Straight to the page that says what to do next, rather than to
        // a dashboard with nothing in it yet.
        return redirect()->route('setup');
    }

    private function usable(string $token): Invitation
    {
        return Invitation::findUsable($token) ?? throw new NotFoundHttpException;
    }

    /**
     * Give them their schema now rather than on their first page load.
     *
     * Same reasoning as app:create-user, minus the terminal to report
     * to: the application provisions lazily and would get here by
     * itself, so a failure must not cost somebody the account they just
     * made. They are signed in either way; what they might not have yet
     * is anywhere to keep watch data.
     */
    private function provisionTheirMirror(User $user): void
    {
        try {
            Mirror::ensure($user->id);
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}
