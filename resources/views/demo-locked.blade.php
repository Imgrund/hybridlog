{{-- What every door that is closed on the public demo opens onto.

     One page for all of them rather than a sentence added to each: the
     answer is the same everywhere, and it is about the installation
     rather than about the button that was pressed. See
     App\Http\Middleware\EnsureNotDemo. --}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    @include('partials.head', ['title' => __('Not part of the demo')])
</head>
<body class="viz-root min-h-screen antialiased">
    <main class="mx-auto max-w-2xl px-4 py-6 sm:px-6 sm:py-9">
        <header class="mb-5 flex flex-wrap items-center gap-x-3 gap-y-1">
            <h1 class="text-xl font-bold tracking-tight">{{ __('Not part of the demo') }}</h1>
            <div class="ml-auto"><a href="{{ route('dashboard') }}" class="text-xs text-muted hover:text-secondary">← {{ __('Back to the dashboard') }}</a></div>
        </header>

        <section class="card">
            <div class="flex flex-wrap items-center gap-3">
                <span class="pill" data-status="neutral">{{ __('Public demo') }}</span>
            </div>
            <p class="mt-3 text-sm text-secondary leading-relaxed">
                {{ __('Everybody who visits this dashboard signs in to the same account, so the parts that would reach out of it are switched off.') }}
            </p>
            <ul class="mt-3 list-disc space-y-1 pl-5 text-sm text-secondary leading-relaxed">
                <li>{{ __('Signing in to Garmin. Nobody should type their real watch password into a dashboard a stranger put on the internet.') }}</li>
                <li>{{ __('Connecting an AI, which would hand a chat client a token to an account that is not yours.') }}</li>
                <li>{{ __('Notifications, which would ring your phone from somebody else\'s ledger.') }}</li>
                <li>{{ __('Fetching from Garmin, which has nothing to fetch: every number here is generated.') }}</li>
            </ul>
            <p class="mt-3 text-sm text-secondary leading-relaxed">
                {{ __('Everything else works, and everything you leave behind is reset every night.') }}
            </p>
        </section>

        <section class="card mt-3">
            <p class="card-title">{{ __('To use these, run your own copy') }}</p>
            <p class="mt-2 text-sm text-secondary leading-relaxed">
                {{ __('It is self-hosted and the readme opens with a quickstart: one clone, one compose file, your own watch.') }}
            </p>
        </section>
    </main>
</body>
</html>
