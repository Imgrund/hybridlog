<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    @include('partials.head', ['title' => __('Connect Garmin')])
</head>
<body class="viz-root min-h-screen antialiased">
    <main class="mx-auto max-w-2xl px-4 py-6 sm:px-6 sm:py-9">
        <header class="mb-5 flex flex-wrap items-center gap-x-3 gap-y-1">
            <h1 class="text-xl font-bold tracking-tight">{{ __('Connect Garmin') }}</h1>
            <div class="ml-auto"><a href="{{ route('dashboard') }}" class="text-xs text-muted hover:text-secondary">← {{ __('Back to the dashboard') }}</a></div>
        </header>

        {{-- The page has one job, and which stage of it is showing decides
             everything below: sign in, wait, type the code, or read the
             verdict. Anything not part of the current stage would only be
             something else to read while waiting. --}}
        @php
            $stage = $attempt?->status;
            $justSignedIn = $stage === \App\Models\GarminLoginAttempt::SUCCEEDED;
            // "Connected" is only claimed where something proves it: a
            // sign-in that just worked, or a fetch that came back with data
            // and no login failure since. A mirror that has never fetched
            // proves nothing either way, and saying "connected" there would
            // be the one lie this page cannot afford.
            $connected = $justSignedIn || ($lastFetch !== null && ! $status->needsSignIn());
        @endphp

        {{-- ==================================================== status --}}
        <section class="card mb-3">
            @if ($connected)
                <div class="flex flex-wrap items-center gap-3">
                    <span class="pill" data-status="good">{{ __('Connected') }}</span>
                    <p class="text-sm text-secondary">
                        @if ($justSignedIn && $attempt->account)
                            {{ __('Signed in as :name.', ['name' => $attempt->account]) }}
                        @elseif ($lastFetch)
                            {{ __('Last Garmin fetch :when.', ['when' => \Carbon\Carbon::parse($lastFetch)->diffForHumans()]) }}
                        @else
                            {{ __('No fetch yet.') }}
                        @endif
                    </p>
                </div>
                @if ($justSignedIn)
                    <p class="mt-3 text-sm text-secondary leading-relaxed">
                        {{ __('The scheduled fetch takes it from here. To see data right away, start one now.') }}
                    </p>
                    <form method="POST" action="{{ route('fetch.now') }}" class="mt-3">
                        @csrf
                        <button type="submit" class="btn btn-primary">{{ __('Fetch from Garmin') }}</button>
                    </form>
                @endif
            @elseif ($status->needsSignIn())
                <div class="flex flex-wrap items-center gap-3">
                    <span class="pill" data-status="critical">
                        {{ $status->state === 'not_connected' ? __('Not connected') : __('Sign-in expired') }}
                    </span>
                </div>
                <p class="mt-3 text-sm text-secondary leading-relaxed">{{ $status->hint }}</p>
            @else
                {{-- No session on record and no failure either: a fresh
                     installation, where signing in is simply the first step
                     rather than a repair. --}}
                <div class="flex flex-wrap items-center gap-3">
                    <span class="pill" data-status="neutral">{{ __('Not connected yet') }}</span>
                </div>
                <p class="mt-3 text-sm text-secondary leading-relaxed">
                    {{ __('Nothing has been fetched from Garmin yet. Sign in below, then the fetch can run.') }}
                </p>
            @endif
        </section>

        {{-- ================================================== in flight --}}
        @if ($stage === \App\Models\GarminLoginAttempt::STARTING || $stage === \App\Models\GarminLoginAttempt::COMPLETING)
            {{-- Waiting on a worker in another container, so the page has
                 to ask. Two seconds, because the next stage is a code with
                 a short life and every idle second is spent from it. --}}
            <section class="card"
                     x-data="loginWatch(
                        '{{ route('connect.garmin.status') }}',
                        @js($stage),
                        @js($stage === \App\Models\GarminLoginAttempt::STARTING ? \App\Models\GarminLoginAttempt::WAIT_SECONDS : 0),
                        @js($attempt->secondsWaiting())
                     )"
                     x-init="start()">
                <p class="card-title">{{ __('Signing in to Garmin') }}</p>
                <p class="mt-2 text-sm text-secondary leading-relaxed">
                    {{ $stage === \App\Models\GarminLoginAttempt::STARTING
                        ? __('Garmin is checking the password. If your account uses two-factor authentication, the code field appears here by itself.')
                        : __('The code has been passed on. Garmin is confirming it.') }}
                </p>

                {{-- A wait with no end in sight reads as a hang, and this one
                     is long enough to be mistaken for one: the library works
                     through five sign-in routes, most of the time spent in
                     the pauses it keeps between them. So the page says how
                     long that usually takes and counts it down. It runs over
                     often enough that the overrun gets a sentence of its own
                     rather than a bar stuck at zero. --}}
                @if ($stage === \App\Models\GarminLoginAttempt::STARTING)
                    <div class="mt-3">
                        {{-- Says nothing the sentence below does not, so it
                             stays out of the accessibility tree. It leaves
                             with the estimate rather than sitting at zero,
                             which is how a bar shows that something stopped. --}}
                        <div class="wait-bar" aria-hidden="true" x-show="remaining > 0" x-cloak>
                            <div class="wait-bar-fill" :style="`width: ${barWidth}%`"></div>
                        </div>
                        {{-- No live region on purpose: a figure that changes
                             every second would talk over everything else on
                             the page for the whole minute. --}}
                        <p class="mt-2 text-xs text-muted leading-relaxed">
                            <span x-show="remaining > 0" x-cloak>
                                {{ __('This usually takes under a minute.') }}
                                {{-- The last second gets its own wording: the
                                     counting sentence would read "1 seconds"
                                     there, in every language this is in. --}}
                                <span x-show="remaining > 1" x-cloak
                                      x-text="@js(__('About :seconds seconds left.')).replace(':seconds', remaining)"></span>
                                <span x-show="remaining === 1" x-cloak>{{ __('About one second left.') }}</span>
                            </span>
                            <span x-show="remaining === 0" x-cloak>
                                {{ __('It is taking longer than usual. Garmin is being tried one sign-in route at a time; the code field still appears here by itself.') }}
                            </span>
                        </p>
                    </div>
                @endif

                <p class="mt-3 text-xs text-muted leading-relaxed">
                    {{ __('This needs a running queue worker. Without one the sign-in waits here forever.') }}
                </p>
            </section>

        {{-- ======================================================== MFA --}}
        @elseif ($stage === \App\Models\GarminLoginAttempt::MFA_REQUIRED)
            {{-- The sign-in is being held open on the worker while this
                 form is filled in: the half-finished session lives in that
                 process and nowhere else. Hence the standing time limit. --}}
            <section class="card">
                <p class="card-title">{{ __('Enter the code from Garmin') }}</p>
                {{-- Named rather than guessed: the code goes to whichever
                     second factor the account has, and an authenticator app
                     is not sent anywhere. Being told to check an inbox that
                     will stay empty costs the whole five minutes. --}}
                <p class="mt-2 text-sm text-secondary leading-relaxed">
                    {{ $attempt->mfaHint() }}
                </p>
                <form method="POST" action="{{ route('connect.garmin.mfa') }}" class="mt-3 flex flex-wrap items-end gap-3">
                    @csrf
                    <label class="min-w-40 flex-1">
                        <span class="block text-xs text-muted">{{ __('Code') }}</span>
                        <input type="text" name="code" inputmode="numeric" autocomplete="one-time-code"
                               autofocus required maxlength="20" class="field mt-1">
                    </label>
                    <button type="submit" class="btn btn-primary">{{ __('Confirm') }}</button>
                </form>
            </section>

        {{-- ==================================================== sign in --}}
        @else
            @if ($stage === \App\Models\GarminLoginAttempt::FAILED)
                <section class="card mb-3">
                    <p class="card-title">{{ __('The sign-in failed') }}</p>
                    <p class="mt-2 text-sm" style="color: var(--status-critical-ink)">{{ $attempt->error }}</p>
                </section>
            @endif

            @if (session('login_throttled'))
                <p class="pill mb-3" data-status="warning">{{ __('Too many attempts. Wait ten minutes: Garmin locks an account that is tried too often.') }}</p>
            @endif

            @foreach ($errors->all() as $message)
                <p class="pill mb-3" data-status="critical">{{ $message }}</p>
            @endforeach

            {{-- Folded away once there is a working session: then this is
                 the rare repair, not the thing to do next. --}}
            <details class="card disclosure" @unless ($connected) open @endunless>
                <summary class="card-title">{{ $connected ? __('Sign in again') : __('Sign in to Garmin Connect') }}</summary>
                <p class="mt-2 text-sm text-secondary leading-relaxed">
                    {{ __('Your Garmin account details, the same ones as in the Garmin Connect app. They are used once to obtain a session and are not stored.') }}
                </p>

                <form method="POST" action="{{ route('connect.garmin.start') }}" class="mt-3 space-y-3">
                    @csrf
                    <label class="block">
                        <span class="block text-xs text-muted">{{ __('Email address') }}</span>
                        <input type="email" name="email" required autocomplete="username"
                               maxlength="120" value="{{ old('email') }}" class="field mt-1">
                    </label>
                    <label class="block">
                        <span class="block text-xs text-muted">{{ __('Password') }}</span>
                        <input type="password" name="password" required autocomplete="current-password"
                               maxlength="200" class="field mt-1">
                    </label>
                    <button type="submit" class="btn btn-primary">{{ __('Sign in') }}</button>
                </form>
            </details>
        @endif

        {{-- =================================================== the deal --}}
        <details class="card disclosure mt-3">
            <summary class="card-title">{{ __('What happens to these details') }}</summary>
            <ul class="mt-3 list-disc space-y-1 pl-5 text-sm text-secondary leading-relaxed">
                <li>{{ __('Password and code go straight to Garmin and are never written to the database or a log.') }}</li>
                <li>{{ __('What is stored is the session Garmin returns, in a schema of its own that only the fetcher reads.') }}</li>
                <li>{{ __('This dashboard talks to the Garmin web API that the Garmin Connect app uses. It is not an official interface, and Garmin can change it at any time.') }}</li>
                <li>{{ __('Signing in again replaces the stored session; the old one stops working.') }}</li>
            </ul>
        </details>
    </main>
</body>
</html>
