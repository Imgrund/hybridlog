{{-- The shared page chrome of every dashboard surface: header,
     navigation, the data-basis strip and the fetch progress notes. --}}
        @php
            $fetchAt = $lastFetch ? \Carbon\Carbon::parse($lastFetch) : null;
            $fetchLabel = match (true) {
                $fetchAt === null => __('no Garmin fetch'),
                $fetchAt->isToday() => __('Garmin fetch :time', ['time' => $fetchAt->format('H:i')]),
                default => __('Garmin fetch :time', ['time' => $fetchAt->isoFormat(__('MMM D, HH:mm'))]),
            };
            $watchSync = \App\Garmin\WatchSync::describe($watchSyncedAt);
            $surfaceTitle = now()->isoFormat(__('dddd, MMMM D'));
        @endphp

        {{-- ================================================== header --}}
        {{-- Toolbar contract: meta text lives left under the title, the
             right side holds only things one can click. The title is the
             day (the page's actual context); the app needs no name said
             to its only user. Right side speaks in two tiers: the one
             real action (plus the connect CTA while unconnected) keeps
             button chrome, navigation and state stand as quiet words. --}}
        @php
            // Green only with evidence: a fetch that brought data back, and
            // no login failure since. Anything else (never fetched, or a
            // dead session) points at the sign-in, which is the one thing
            // that can be done about it from here.
            //
            // Block form rather than the parenthesised one: Blade's raw
            // pass pairs the short form with the next block terminator
            // anywhere in the file and swallows everything between, and
            // this file has such blocks below.
            $garminConnected = $dataStatus->lastFetch !== null && ! $dataStatus->needsSignIn();
        @endphp

        <div class="app-shell-head">
        <header class="app-header mb-6 flex flex-wrap items-center gap-x-4 gap-y-3">
            <a href="{{ route('dashboard') }}" class="brand-lockup" aria-label="Hybridlog">
                <span class="brand-slash" aria-hidden="true"></span>
                <span>hybridlog</span>
            </a>
            <div>
                <h1 class="text-2xl font-bold tracking-tight leading-none">{{ $surfaceTitle }}</h1>
                <p class="mt-1.5 flex flex-wrap items-center gap-x-2 gap-y-1 text-sm text-muted">
                    {{-- This timestamp is the age of everything below it,
                         which makes it the one honest place to ask for a
                         newer one: "is there anything new" is answered by
                         re-reading the mirror, and the line that says when
                         it was last read is where a reader looks first.
                         A link rather than a button, so it works before
                         Alpine and keeps whichever range is being read.
                         The arrow turns while a fetch is under way, from
                         wherever it was started. --}}
                    <a href="{{ url()->full() }}" class="inline-flex items-center gap-1.5 underline-offset-2 hover:text-secondary hover:underline"
                       title="{{ __('Reload the view to see whether new data has arrived.') }}">
                        <svg viewBox="0 0 24 24" width="11" height="11" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" @class(['icon-spin' => $fetchRunning])><path d="M23 4v6h-6"/><path d="M20.49 15a9 9 0 1 1-2.12-9.36L23 10"/></svg>
                        {{ $fetchLabel }}
                    </a>
                    {{-- The watch sync used to sit here as a second
                         timestamp. It now stands in the data-basis strip
                         below the navigation, beside the other three
                         answers to "is this complete", where it is a fact
                         about the data rather than a second clock in the
                         title line. --}}
                </p>
                @if ($demoMode)
                    {{-- On a public demo the four Garmin verdicts below all
                         say something untrue: there is no watch, no stored
                         session and no scheduled fetch, and by the evening
                         the seed's own timestamp reads as a fetch that
                         "appears to have stopped running". So the demo says
                         what is actually the case, once, in the line those
                         warnings would have used. --}}
                    <p class="mt-1 text-sm text-muted">
                        {{ __('A public demo on generated data: everybody shares one account, and everything is reset each night.') }}
                    </p>
                @elseif (in_array($dataStatus->state, ['auth_broken', 'not_connected'], true))
                    {{-- A missing Garmin session outranks everything: no
                         refresh and no watch sync can help until someone
                         has signed in again. Both states carry the way to
                         do that, because a hint that names a page one then
                         has to go looking for is half an answer. --}}
                    <p class="mt-1 text-sm" style="color: var(--status-critical-ink)">
                        {{ $dataStatus->hint }}
                        <a href="{{ route('connect.garmin') }}" class="font-semibold underline underline-offset-2">{{ __('Sign in to Garmin') }}</a>
                    </p>
                @elseif ($dataStatus->state === 'fetch_stale')
                    <p class="mt-1 text-sm" style="color: var(--status-warning-ink)">
                        {{ $dataStatus->hint }}
                    </p>
                @elseif ($watchSync && $watchSync['stale'])
                    {{-- The answer to "I refreshed and nothing changed":
                         the fetch can only mirror what the watch has
                         already uploaded to Garmin. --}}
                    <p class="mt-1 text-sm" style="color: var(--status-warning-ink)">
                        {{ __('No watch sync since :label.', ['label' => $watchSync['label']]) }}
                        {{ __('New values only arrive after a sync with the Garmin Connect app.') }}
                    </p>
                @endif
            </div>
            <div class="header-actions ml-auto flex flex-wrap items-center justify-end gap-x-2 gap-y-2">
                {{-- Two different facts, and only one of them can be told
                     here: that the numbers are generated (which a local
                     installation seeded for a look around also is), and
                     that this copy is the public shop window. The second
                     one includes the first. --}}
                @if ($demoMode)
                    <span class="pill" data-status="neutral">{{ __('Public demo') }}</span>
                @elseif ($isDemo)
                    <span class="pill" data-status="neutral">{{ __('Demo data') }}</span>
                @endif
                {{-- No fetch button on a demo. It is the one real action in
                     this header and it cannot work there: nothing is signed
                     in to Garmin, and a button that only ever explains
                     itself is worse than no button. --}}
                @unless ($demoMode)
                <form method="POST" action="{{ route('fetch.now') }}" x-data="{ busy: false }" @submit="busy = true">
                    @csrf
                    <button type="submit" class="btn btn-ghost btn-sm" :disabled="busy" title="{{ __('Fetches the latest data from your Garmin account. Values only land there once the watch has synced with the Garmin Connect app.') }}">
                        {{-- The arrow that names the action turns while the
                             action runs: the button's own icon is the
                             spinner, so no second element appears. --}}
                        <svg viewBox="0 0 24 24" width="12" height="12" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" :class="busy && 'icon-spin'"><path d="M23 4v6h-6"/><path d="M20.49 15a9 9 0 1 1-2.12-9.36L23 10"/></svg>
                        <span x-text="busy ? @js(__('Starting …')) : @js(__('Fetch from Garmin'))">{{ __('Fetch from Garmin') }}</span>
                    </button>
                </form>
                @endunless
                {{-- Account and connection fold behind one icon: the
                     header keeps a single real action, everything else
                     is one press away. The dot on the trigger carries
                     the connected state; the menu writes the word. --}}
                <div class="relative" x-data="{ open: false }" @click.outside="open = false"
                     @keydown.escape.stop="open = false; $refs.menuButton.focus()">
                    <button type="button" class="btn btn-ghost btn-sm btn-icon" x-ref="menuButton"
                            @click="open = !open" :aria-expanded="open"
                            aria-haspopup="true" aria-label="{{ __('Account and connection') }}">
                        <svg viewBox="0 0 24 24" width="12" height="12" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                        {{-- The dot rides on the icon rather than beside it,
                             so the button keeps one shape whether or not a
                             connector is attached. Beside it, the button
                             grew a second width for the same control. --}}
                        @if ($aiConnected)
                            <span class="btn-icon-dot" aria-hidden="true"></span>
                        @endif
                    </button>
                    <div class="menu-panel" x-show="open" x-cloak @click="open = false">
                        {{-- The three connections are closed on a demo, so
                             they are not offered there either: the routes
                             still answer with the reason for anyone who
                             arrives by bookmark, but a menu that leads only
                             to "not here" is a menu of dead ends. What is
                             left is what still works. --}}
                        @unless ($demoMode)
                        <a href="{{ route('connect') }}" class="menu-item"
                           title="{{ $aiConnected ? __('Change permissions or disconnect') : __('Connect Claude to the dashboard') }}">
                            <span class="h-1.5 w-1.5 rounded-full {{ $aiConnected ? 'bg-[var(--status-linked)]' : 'bg-[var(--text-muted)]' }}" aria-hidden="true"></span>
                            {{ $aiConnected ? __('AI connected') : __('Connect AI') }}
                        </a>
                        {{-- The other connection: the AI reads the mirror,
                             this one fills it. Same dot, same wording, so
                             the two are told apart by name only. --}}
                        <a href="{{ route('connect.garmin') }}" class="menu-item"
                           title="{{ $garminConnected ? __('Sign in to Garmin again') : __('Sign in to Garmin so the fetch can run') }}">
                            <span class="h-1.5 w-1.5 rounded-full {{ $garminConnected ? 'bg-[var(--status-linked)]' : 'bg-[var(--status-critical-ink)]' }}" aria-hidden="true"></span>
                            {{ $garminConnected ? __('Garmin connected') : __('Connect Garmin') }}
                        </a>
                        {{-- No dot on this one, unlike the three above: it
                             is switched on per device, in the browser, and
                             the server cannot know the state of the one
                             reading this menu. A green dot fed by "some
                             device is subscribed" would be a light for
                             somebody else's phone. --}}
                        <a href="{{ route('connect.notifications') }}" class="menu-item"
                           title="{{ __('The morning briefing and the other notifications, per device') }}">{{ __('Notifications') }}</a>
                        @endunless
                        <a href="{{ route('profile') }}" class="menu-item">{{ __('Profile') }}</a>
                        <form method="POST" action="{{ route('logout') }}">
                            @csrf
                            <button type="submit" class="menu-item">{{ __('Log out') }}</button>
                        </form>
                    </div>
                </div>
            </div>
        </header>

        {{-- What the page below is standing on. Above every number it
             qualifies. --}}
        <x-data-quality :quality="$dataQuality" />
        </div>

        @if (session('fetch_started') || $fetchRunning)
            {{-- Polls the status endpoint every 10 s and reloads once the
                 run is over with a newer fetch_log stamp, the job's real
                 done-signal, instead of guessing with a blind timer. Only
                 once at the end: the stamp already moves per endpoint
                 mid-run, and reloading on it flickered the page. A run
                 that dies never writes that stamp, so the failure ends the
                 wait with its reason and, where signing in is the fix, the
                 way there.

                 While the run is the first-connect backfill the line
                 counts it in ("day 34 of 90"): that run spends many
                 minutes on a quarter of a year, and a static sentence
                 over that wait reads as a page that has stopped. For the
                 same reason there is no fixed give-up any more: the
                 watch waits while progress keeps arriving and calls four
                 quiet minutes a stall, out loud, without abandoning the
                 promised reload (resources/js/app.js says how it ends).

                 Shown for any running fetch, not only the one this request
                 started: the scheduled run and a fetch started from the
                 phone are just as much an answer to "why is this number
                 still yesterday's", and a page that stayed silent about
                 them looked stale for no stated reason. --}}
            @php
                // Untranslated placeholders on purpose: the poll fills
                // :done and :total in the browser as the run walks on.
                $fetchMessages = [
                    'plain' => session('fetch_started')
                        ? __('Fetch from Garmin started. The page reloads by itself once the data is in, about a minute.')
                        : __('A fetch from Garmin is running. The page reloads by itself once the data is in.'),
                    'backfillStart' => __('First fetch: getting the last :total days from Garmin. This takes a few minutes; the page reloads by itself once everything is in.'),
                    'backfillDay' => __('First fetch: day :done of :total. The page reloads by itself once everything is in.'),
                    'backfillRest' => __('First fetch: the daily values are in, now the activities and their details.'),
                    'stalled' => __('The fetch has not reported progress for a few minutes, details in data/fetch.log.'),
                ];
                // The choice message() makes, made once on the server too:
                // the first paint is honest before Alpine wakes, and stays
                // honest without JavaScript at all.
                $fetchInitialText = match (true) {
                    $fetchProgress === null || ! $fetchProgress['backfill'] => $fetchMessages['plain'],
                    $fetchProgress['done'] >= $fetchProgress['total'] => $fetchMessages['backfillRest'],
                    $fetchProgress['done'] > 0 => str_replace([':done', ':total'], [$fetchProgress['done'], $fetchProgress['total']], $fetchMessages['backfillDay']),
                    default => str_replace(':total', $fetchProgress['total'], $fetchMessages['backfillStart']),
                };
            @endphp
            <div class="mb-5 text-sm text-secondary"
                 x-data="fetchWatch('{{ route('fetch.status') }}', {{ \Illuminate\Support\Js::from($lastFetch) }}, {{ \Illuminate\Support\Js::from($fetchProgress) }}, {{ \Illuminate\Support\Js::from($fetchMessages) }})"
                 x-init="start()">
                <p x-show="!problem && !finished" x-text="message()">{{ $fetchInitialText }}</p>
                {{-- The fetch ended without writing anything new. Worth
                     saying out loud, because it is the answer to the
                     question the button was pressed for, and the reason
                     is almost always the watch rather than the fetch. --}}
                <p x-show="finished" x-cloak>{{ __('The fetch has finished. No new data had arrived at Garmin.') }}</p>
                {{-- What this run died of, in one clause, and the way
                     out as a button. The long explanation belongs to the
                     header line above; repeating it here word for word
                     read as a second, different problem. --}}
                <p x-show="problem" x-cloak class="flex flex-wrap items-center gap-x-2 gap-y-1">
                    <span class="pill" data-status="critical">{{ __('The fetch failed') }}</span>
                    <span x-text="problem"></span>
                    <a x-show="connectUrl" x-cloak :href="connectUrl" class="btn btn-primary btn-sm" x-text="action">{{ __('Sign in to Garmin') }}</a>
                </p>
            </div>
        @elseif (session('fetch_refused'))
            {{-- The trigger turned the click down and said why, today
                 one reason: no Garmin session yet. The sentence arrives
                 ready to show, and the one fix it names gets a button.
                 Not on the public demo, where signing in is exactly what
                 the page must not offer. --}}
            <p class="mb-5 text-sm text-secondary flex flex-wrap items-center gap-x-2 gap-y-1">
                <span>{{ session('fetch_refused') }}</span>
                @unless ($demoMode)
                    <a href="{{ route('connect.garmin') }}" class="btn btn-primary btn-sm">{{ __('Sign in to Garmin') }}</a>
                @endunless
            </p>
        @elseif (session('fetch_busy'))
            <p class="mb-5 text-sm text-secondary">
                {{ __('A fetch is already running or was just started. Wait a moment, then reload.') }}
            </p>
        @endif
