<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    @include('partials.head', ['title' => __('Profile')])
</head>
<body class="viz-root min-h-screen antialiased">
    <main class="mx-auto max-w-3xl px-4 py-6 sm:px-6 sm:py-9">
        <header class="mb-5 flex flex-wrap items-center gap-x-3 gap-y-1">
            <h1 class="text-xl font-bold tracking-tight">{{ __('Profile') }}</h1>
            <div class="ml-auto"><a href="{{ route('dashboard') }}" class="text-xs text-muted hover:text-secondary">← {{ __('Back to the dashboard') }}</a></div>
        </header>

        {{-- ================================================== language --}}
        {{-- English is the source language, German a translation. The
             switch lives here rather than in the header: it is a decision
             one makes once, not a control one reaches for daily. --}}
        <section class="card">
            <p class="card-title">{{ __('Language') }}</p>
            <p class="mt-2 text-sm text-secondary leading-relaxed">
                {{ __('Without a choice here the dashboard follows your browser. The setting applies to this account, on every device.') }}
            </p>

            @if (session('locale_saved'))
                <p class="pill mt-3" data-status="good">{{ __('Saved') }}</p>
            @endif

            <form method="POST" action="{{ route('profile.locale') }}" class="mt-3 space-y-1.5">
                @csrf
                @foreach ([
                    ['', __('Follow my browser')],
                    ['en', 'English'],
                    ['de', 'Deutsch'],
                ] as [$value, $label])
                    <label class="flex items-center gap-3">
                        <input type="radio" name="locale" value="{{ $value }}"
                               @checked(old('locale', $profile->locale ?? '') === $value)
                               class="h-4 w-4 accent-current">
                        <span class="text-sm text-primary">{{ $label }}</span>
                    </label>
                @endforeach
                <button type="submit" class="btn btn-primary mt-1.5">{{ __('Save') }}</button>
            </form>
        </section>

        {{-- =================================================== location --}}
        {{-- The weather in the mirror is read at one point on earth, and
             an installation with two athletes cannot read both at the
             installation's. Left empty this stays as it was, at whatever
             the deployment configured, which is the right answer for the
             athlete who set it. --}}
        <section class="card mt-3">
            <p class="card-title">{{ __('Where you train') }}</p>
            <p class="mt-2 text-sm text-secondary leading-relaxed">
                {{ __('The weather beside your sleep and your sessions is read here. Name the town, not the street: a weather model is coarser than a postcode anyway.') }}
            </p>

            @if (session('location_saved'))
                <p class="pill mt-3" data-status="good">{{ __('Saved:') }} {{ session('location_saved') }}</p>
            @elseif (session('location_cleared'))
                <p class="pill mt-3" data-status="neutral">{{ __('Place cleared, back to this installation\'s own location') }}</p>
            @endif

            @error('place')
                <p class="pill mt-3" data-status="serious">{{ $message }}</p>
            @enderror

            <form method="POST" action="{{ route('profile.location') }}" class="mt-3">
                @csrf
                <label class="block max-w-sm">
                    <span class="block text-xs text-muted">{{ __('Town') }}</span>
                    <input type="text" name="place" maxlength="120"
                           value="{{ old('place', $profile->location_name ?? '') }}"
                           placeholder="{{ __('e.g. Berlin') }}"
                           @if ($errors->has('place')) aria-invalid="true" @endif
                           class="field mt-1">
                </label>
                {{-- Not the primary style: the page's one primary sits on
                     the language above, and two saves competing in one
                     viewport would rank neither. --}}
                <button type="submit" class="btn btn-sm mt-2">{{ __('Save place') }}</button>
                <p class="mt-2 text-xs text-muted leading-relaxed">
                    @if ($profile?->hasLocation())
                        {{ __('Weather is read at :place. Clear the field and save to hand it back to this installation\'s location.', ['place' => $profile->location_name]) }}
                    @else
                        {{ __('No place of your own yet, so the weather comes from wherever this installation is configured for.') }}
                    @endif
                </p>
            </form>
        </section>

        {{-- =============================================== what else --}}
        <section class="card mt-3">
            <p class="card-title">{{ __('What else is stored about you') }}</p>
            {{-- One sentence, because there is one answer. A second
                 paragraph used to stand here saying where the athlete's
                 own entries were kept; those entries are gone, and what
                 was left of it said nothing the language card above does
                 not already say. --}}
            <p class="mt-2 text-sm text-secondary leading-relaxed">
                {{ __('Everything about the athlete comes from the watch and is not maintained twice here: body composition, fitness age, chronological age, heart-rate zones. The language above is the only thing this page needs from you.') }}
            </p>
        </section>
    </main>
</body>
</html>
