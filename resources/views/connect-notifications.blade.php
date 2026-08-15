<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    @include('partials.head', ['title' => __('Notifications')])
</head>
<body class="viz-root min-h-screen antialiased">
    <main class="mx-auto max-w-2xl px-4 py-6 sm:px-6 sm:py-9">
        <header class="mb-5 flex flex-wrap items-center gap-x-3 gap-y-1">
            <h1 class="text-xl font-bold tracking-tight">{{ __('Notifications') }}</h1>
            <div class="ml-auto"><a href="{{ route('dashboard') }}" class="text-xs text-muted hover:text-secondary">← {{ __('Back to the dashboard') }}</a></div>
        </header>

        {{-- ============================================== what it is for --}}
        <section class="card mb-3">
            <p class="text-sm text-secondary leading-relaxed">
                {{ __('Four notifications, each tied to a moment the data is worth a glance: a short morning briefing after the first fetch, an evening nudge that only speaks when the bedtime has been drifting, the morning health alerts, and a Sunday reminder that opens the chat with the weekly-report prompt ready to send. Each one stays quiet when it has nothing honest to say.') }}
            </p>
            <p class="mt-2 text-sm text-secondary leading-relaxed">
                {{ __('Entirely optional. Everything a notification would say is on the dashboard anyway; this channel only shortens the way there.') }}
            </p>
        </section>

        {{-- ================================================== the switch --}}
        @if (! $configured)
            {{-- Nobody can do this step for a self-hosted copy: the key
                 pair is the sender identity of this installation, and a
                 shared one would let whoever holds it push to all of them. --}}
            <section class="card mb-3">
                <p class="pill" data-status="warning">{{ __('Not set up yet') }}</p>
                <p class="mt-3 text-sm text-secondary leading-relaxed">
                    {{ __('Notifications need a key pair of their own, which identifies this installation to the browser vendors that deliver them. Generate one and put both lines into the environment:') }}
                </p>
                <pre class="mt-3 overflow-x-auto rounded-md bg-black/20 p-3 text-xs text-primary">php artisan push:keys</pre>
                <p class="mt-3 text-xs text-muted">
                    {{ __('Keep the pair once it is in use. Replacing it silently breaks every device that has already allowed notifications.') }}
                </p>
            </section>
        @else
            <section class="card mb-3"
                     x-data="pushSwitch({
                         key: @js($publicKey),
                         subscribeUrl: @js(route('push.subscribe')),
                         unsubscribeUrl: @js(route('push.unsubscribe')),
                         token: @js(csrf_token()),
                     })">
                {{-- The state is read from the browser rather than kept
                     here, because the browser's permission and its
                     subscription are what actually decide whether a
                     notification arrives. A remembered "on" that disagrees
                     with them is a switch that lies. --}}
                <div x-show="state === 'working'" class="text-sm text-muted">{{ __('One moment…') }}</div>

                <div x-show="state === 'on'" x-cloak>
                    <div class="flex flex-wrap items-center gap-3">
                        <span class="pill" data-status="good">{{ __('On for this device') }}</span>
                        <button type="button" class="btn btn-ghost btn-sm ml-auto" x-on:click="disable()">{{ __('Turn off') }}</button>
                    </div>
                    <p class="mt-3 text-sm text-secondary leading-relaxed">
                        {{ __('This device gets the notifications described on this page shortly after each one shows up in the data.') }}
                    </p>
                </div>

                <div x-show="state === 'off'" x-cloak>
                    <p class="text-sm text-secondary leading-relaxed">
                        {{ __('Your browser will ask for permission once. Every device is switched on separately, on the device itself.') }}
                    </p>
                    <button type="button" class="btn btn-primary mt-3" x-on:click="enable()">{{ __('Allow notifications') }}</button>
                </div>

                {{-- Denied is a browser setting, and no button here can
                     change it: saying where it lives beats a switch that
                     does nothing when tapped. --}}
                <div x-show="state === 'denied'" x-cloak>
                    <p class="pill" data-status="neutral">{{ __('Blocked in the browser') }}</p>
                    <p class="mt-3 text-sm text-secondary leading-relaxed">
                        {{ __('Notifications for this site are switched off in the browser itself. Allow them in the site settings, usually behind the icon to the left of the address, then come back.') }}
                    </p>
                </div>

                <div x-show="state === 'unsupported'" x-cloak>
                    <p class="pill" data-status="neutral">{{ __('Not available in this browser') }}</p>
                    <p class="mt-3 text-sm text-secondary leading-relaxed">
                        {{ __('On iPhone and iPad this only works once the dashboard has been added to the home screen: open it in Safari, Share, “Add to Home Screen”, then start it from there and come back to this page. Private windows never allow it.') }}
                    </p>
                </div>

                <p x-show="error" x-cloak class="mt-3 text-sm" style="color: var(--status-critical-ink)" x-text="error"></p>
            </section>

            {{-- ============================================= other devices --}}
            @if ($devices->isNotEmpty())
                <section class="card mb-3">
                    <p class="card-title">{{ __('Devices that get notifications') }}</p>
                    <ul class="mt-3 space-y-2">
                        @foreach ($devices as $device)
                            <li class="flex flex-wrap items-baseline gap-x-3 gap-y-1 text-sm">
                                <span class="text-secondary">{{ $device->device ?: __('Unnamed device') }}</span>
                                <span class="text-xs text-muted">
                                    @if ($device->last_push_at)
                                        {{ __('last notified :when', ['when' => $device->last_push_at->diffForHumans()]) }}
                                    @else
                                        {{ __('added :when, nothing sent yet', ['when' => $device->created_at->diffForHumans()]) }}
                                    @endif
                                </span>
                            </li>
                        @endforeach
                    </ul>
                    {{-- No delete button: a device is switched off on the
                         device, and a row removed from here would be back
                         on that device's next visit to this page. Rows for
                         devices that are really gone clear themselves, the
                         first time their push service says so. --}}
                    <p class="mt-3 text-xs text-muted">{{ __('Each device is switched off on the device itself. A device that has been reset or reinstalled drops off this list by itself.') }}</p>
                </section>
            @endif
        @endif

        {{-- =================================================== the deal --}}
        <details class="card disclosure mt-3">
            <summary class="card-title">{{ __('When it notifies, and when it stays quiet') }}</summary>
            <ul class="mt-3 list-disc space-y-1 pl-5 text-sm text-secondary leading-relaxed">
                <li>{{ __('The morning briefing comes once a day after the morning fetch: readiness, the verdict, one focus. It stays quiet on a morning Garmin has not delivered today\'s data yet.') }}</li>
                <li>{{ __('The evening nudge comes at most once a day, and only when the bedtime has drifted far enough that there is a window worth naming. An evening whose sleep window is holding sends nothing, which is the usual evening.') }}</li>
                <li>{{ __('Health alerts fire only while one of the three morning threshold rules is broken, once per rule and day.') }}</li>
                <li>{{ __('The weekly reminder comes on Sunday evening. Its tap opens the chat app with the report prompt prepared; the reminder expires with its Sunday, because the report itself lives in the chat and leaves no trace here.') }}</li>
                <li>{{ __('Every notification is sent empty. The text is fetched by this dashboard at the moment it is shown, so Google, Mozilla and Apple never carry anything about your health. An item dealt with in between stays quiet.') }}</li>
            </ul>
        </details>
    </main>
</body>
</html>
