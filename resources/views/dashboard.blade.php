{{-- A daily coaching path rather than a wall of measurements: answer,
     evidence, detail, on one page. The first viewport is the body map
     and the load area, switched as tabs; the readiness verdict lives in
     the watch, the briefing and the chat, and everything deeper lives in
     the chat. Geometry organizes the information here and never becomes
     decorative striping. --}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    @include('partials.head')
</head>
<body class="viz-root min-h-screen antialiased">
    <main class="app-main">

        @include('partials.app-header')

        {{-- =================================== illness early warning --}}
        {{-- Renders only while the pattern is active: at least two of
             three markers off their personal baseline, resting HR always
             among them (see Insights::illnessWarning). The wording stays
             a pattern hint: the page never plays doctor. --}}
        @if ($illness)
            <section class="card mt-4" aria-label="{{ __('Illness early warning') }}">
                <div class="flex flex-wrap items-center gap-x-3 gap-y-1.5">
                    <span class="pill" data-status="{{ $illness['status'] }}">{{ __('Unusual pattern') }}</span>
                    <p class="text-sm text-secondary">
                        {{ $illness['message'] }} <span class="text-muted">{{ __('Pattern hint, not a diagnosis.') }}</span>
                    </p>
                </div>
                {{-- Symptoms volunteered in the chat (last 48 h) appear only
                     here, as context on the pattern, never as their own card. --}}
                @if ($illnessSymptomLine)
                    <p class="mt-2 text-xs text-muted">{{ $illnessSymptomLine }}</p>
                @endif
            </section>
        @endif

        <div class="mt-6" data-chart-region x-data="dashTabs({{ \Illuminate\Support\Js::from(array_column($tabs, 'id')) }})"
             {{-- Strings, not a boolean: Alpine drops an attribute whose bound
                  value is false, and the stylesheet needs it present to match. --}}
             x-bind:data-range-aware="{{ \Illuminate\Support\Js::from($rangeAware) }}.includes(active) ? 'true' : 'false'"
             x-on:hashchange.window="syncHash()">
            @include('partials.tabbar')

        @include('partials.area-koerperkarte')

        @include('partials.area-belastung')
        </div>

        @include('partials.methodology')
    </main>

    <script>window.__DASH__ = @json($payload);</script>
</body>
</html>
