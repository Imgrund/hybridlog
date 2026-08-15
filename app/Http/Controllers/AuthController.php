<?php

namespace App\Http\Controllers;

use App\Demo\DemoMode;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class AuthController extends Controller
{
    public function showLogin(): View
    {
        return view('auth.login', [
            /*
             * The one place in this application that writes a password
             * into a page, and it is deliberate.
             *
             * On the public demo there is nobody to ask for the account:
             * the visitor is a stranger who arrived at a URL, and a
             * password box in front of a shop window is a closed shop.
             * So the demo's own credentials stand on the page and go
             * into the fields, and signing in is one press.
             *
             * Nothing is being leaked by that. This value comes from
             * DEMO_PASSWORD and is public by definition: it is the
             * password to an account a hundred strangers share, on an
             * installation whose every reaching-out surface is closed
             * (App\Http\Middleware\EnsureNotDemo) and whose entire
             * contents are generated and wiped nightly. A demo whose
             * password were a secret would be no demo.
             *
             * The value is resolved here rather than in the template,
             * and null rather than a flag on a normal installation, so
             * that there is simply nothing for the page to print unless
             * this installation is the shop window. Getting that wrong
             * would put somebody's real password on their sign-in page.
             */
            'demoAccount' => DemoMode::enabled() ? [
                'email' => (string) config('demo.account.email'),
                'password' => (string) config('demo.account.password'),
            ] : null,
        ]);
    }

    public function login(Request $request): RedirectResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        if (! Auth::attempt($credentials, remember: true)) {
            throw ValidationException::withMessages([
                'email' => __('These credentials are not correct.'),
            ]);
        }

        $request->session()->regenerate();

        return redirect()->intended(route('dashboard'));
    }

    public function logout(Request $request): RedirectResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}
