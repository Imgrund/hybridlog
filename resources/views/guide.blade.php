<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
{{-- What "/" answers to somebody who has not signed in: what this is,
     and the four things between arriving here and reading one's own
     numbers. Short on purpose. The page has no data on it, so it can
     afford to say everything it has to say above two screens.

     One action, and it is the sign-in. Everything else on this page is
     type. --}}
<head>
    @include('partials.head', ['title' => __('How this works')])
</head>
<body class="viz-root min-h-screen antialiased">
    <main class="mx-auto w-full max-w-5xl px-4 py-7 sm:px-6 sm:py-11">
        <header class="mb-7 flex flex-wrap items-center gap-x-4 gap-y-2">
            <span class="brand-lockup">
                <span class="brand-slash" aria-hidden="true"></span>
                <span>hybridlog</span>
            </span>
        </header>

        {{-- ============================================ what this is --}}
        <section class="guide-plate">
            <div class="guide-plate-main">
                <p class="guide-eyebrow">{{ __('What this is') }}</p>
                <h1 class="guide-answer">{{ __('Your watch has the numbers. This has the reading.') }}</h1>
                <p class="guide-lede">
                    {{ __('hybridlog mirrors your Garmin account into a database of its own, computes what the watch does not (training load, muscle freshness, readiness in context), and gives a language model a way in.') }}
                </p>
                <a href="{{ route('login') }}" class="btn btn-primary guide-action">
                    {{ __('Sign in') }}
                    <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12h14"/><path d="m12 5 7 7-7 7"/></svg>
                </a>
            </div>

            {{-- The evidence rail of the answer plate, in its one honest
                 form here: this page holds no measured value, so the cells
                 name what the dashboard reads instead of shouting a number
                 nobody has fetched yet. --}}
            <div class="guide-rail">
                <div class="guide-rail-cell">
                    <p class="guide-rail-label">{{ __('Body map') }}</p>
                    <p class="guide-rail-note">{{ __('Freshness per muscle zone, decaying by the hour since it was loaded.') }}</p>
                </div>
                <div class="guide-rail-cell">
                    <p class="guide-rail-label">{{ __('Training load') }}</p>
                    <p class="guide-rail-note">{{ __('CTL, ATL and TSB, with the acute-to-chronic ratio the watch keeps to itself.') }}</p>
                </div>
                <div class="guide-rail-cell">
                    <p class="guide-rail-label">{{ __('In your chat') }}</p>
                    <p class="guide-rail-note">{{ __('The same numbers over MCP, in Claude or ChatGPT, so what Garmin cannot measure can be said out loud.') }}</p>
                </div>
            </div>
        </section>

        {{-- ============================================== the four steps --}}
        <section class="guide-how">
        <p class="guide-eyebrow guide-section-label">{{ __('How to get going') }}</p>

        <ol class="guide-trail">
            <li class="guide-step">
                <span class="guide-step-mark" aria-hidden="true">1</span>
                <div>
                    <h2 class="guide-step-name">{{ __('Get an account') }}</h2>
                    {{-- The step that is not the same sentence on both
                         kinds of installation, and the one place where
                         saying the wrong one costs a visitor the whole
                         page: "whoever runs this installation hands over
                         the password" sends the reader of a public demo
                         to an operator they have no way of reaching, for
                         a password that is printed on the sign-in page.
                         So the demo says where the password actually is,
                         and keeps the own-copy answer as well, because
                         somebody standing in the shop window is reading
                         this to find out how their own copy would work. --}}
                    <p class="guide-step-body">
                        @if ($demoMode)
                            {{ __('There is no sign-up page, on purpose: a login nobody can register at has no surface to attack. This installation is the public demo, so its one account already exists and the sign-in page carries the password. On a copy of your own you create that account at the command line.') }}
                        @else
                            {{ __('There is no sign-up page, on purpose: a login nobody can register at has no surface to attack. Whoever runs this installation creates the account and hands over the password.') }}
                        @endif
                    </p>
                </div>
            </li>
            <li class="guide-step">
                <span class="guide-step-mark" aria-hidden="true">2</span>
                <div>
                    <h2 class="guide-step-name">{{ __('Sign in and connect Garmin') }}</h2>
                    {{-- Demonstrably wrong on a demo, in the plainest
                         way a page can be: it names a menu entry that is
                         not in the menu there, on a page the demo
                         refuses outright. The reason it refuses is worth
                         repeating here rather than leaving to whoever
                         goes looking for the closed door. --}}
                    <p class="guide-step-body">
                        @if ($demoMode)
                            {{ __('Nothing to connect here: the demo runs on generated numbers, and nobody should type their watch password into a dashboard a stranger put on the internet. On a copy of your own it sits under Garmin in the account menu, and what is kept is a token pair that lasts about a year, never the password itself.') }}
                        @else
                            {{ __('Under Garmin in the account menu: your Garmin address, its password, and the code if Garmin asks for one. What is kept is a token pair that lasts about a year, never the password itself.') }}
                        @endif
                    </p>
                </div>
            </li>
            <li class="guide-step">
                <span class="guide-step-mark" aria-hidden="true">3</span>
                <div>
                    {{-- The heading is an instruction everywhere else and
                         nothing to do here, where the history arrived
                         before the visitor did. It becomes a description
                         rather than losing the number: ninety days is the
                         one figure on this page a reader carries away, and
                         it is as true of the demo as of any copy. --}}
                    <h2 class="guide-step-name">
                        {{ $demoMode ? __('The first ninety days') : __('Let the first ninety days land') }}
                    </h2>
                    {{-- Three claims that the demo cannot keep: a backfill
                         that starts at sign-in, a page that fills in while
                         you watch, a fetch three times a day. What actually
                         happens here is the opposite shape, full from the
                         first second and written again every night, so the
                         demo says that first and then hands the reader the
                         same three claims as the answer for their own copy.
                         The seeded span is fetcher/seed_demo.py's DAYS. --}}
                    <p class="guide-step-body">
                        @if ($demoMode)
                            {{ __('The history here is in place before you arrive, a hundred and twenty generated days, and it is written again from the same script every night. On a copy of your own that first sign-in starts a backfill in the background, roughly a quarter of an hour, and the page fills in as the history arrives. Ninety days is about the minimum the models need: the HRV baseline counts three weeks of nights, the load ratios a rolling six weeks. From then on the fetch runs three times a day by itself.') }}
                        @else
                            {{ __('That first sign-in starts a backfill in the background, roughly a quarter of an hour, and the page fills in as the history arrives. Ninety days is about the minimum the models need: the HRV baseline counts three weeks of nights, the load ratios a rolling six weeks. From then on the fetch runs three times a day by itself.') }}
                        @endif
                    </p>
                </div>
            </li>
            <li class="guide-step">
                <span class="guide-step-mark" aria-hidden="true">4</span>
                <div>
                    {{-- The heading stays an invitation on both, because
                         the pill beside it does the qualifying: "optional"
                         and "closed here" are answers to the same question
                         and the reader gets whichever is true of the
                         installation they are standing in. --}}
                    <h2 class="guide-step-name">
                        {{ __('Bring your chat and your phone') }}
                        <span class="pill guide-step-pill" data-status="neutral">
                            {{ $demoMode ? __('Closed here') : __('Optional') }}
                        </span>
                    </h2>
                    {{-- Both doors are shut on a demo (routes/ai.php and
                         the not-demo group in routes/web.php), and for the
                         reason config/demo.php gives: everybody is signed
                         in to the same account, so a token handed to a
                         chat client and a phone subscribed to this ledger
                         both belong to somebody else. --}}
                    <p class="guide-step-body">
                        @if ($demoMode)
                            {{ __('Both are shut here, for the reason the Garmin login is: a chat client would be handed a token to an account that is not yours, and a phone subscribed to this ledger would be told about a stranger\'s morning. On a copy of your own you connect Claude or ChatGPT so the numbers can be read with you, and allow notifications if you want the morning briefing on your phone. Both are set per account, both can be undone.') }}
                        @else
                            {{ __('Connect Claude or ChatGPT so the numbers can be read with you, and allow notifications if you want the morning briefing on your phone. Both are set per account, both can be undone.') }}
                        @endif
                    </p>
                </div>
            </li>
        </ol>

        {{-- The long form of the same four steps, for somebody who has
             just been handed an account and wants the click path rather
             than the shape. Kept as a line of type: this page's one
             action is the sign-in above. --}}
        <p class="mt-5 text-sm text-secondary">
            <a href="{{ route('setup') }}" class="underline underline-offset-2 hover:text-primary">{{ __('Step by step, with the click paths') }}</a>
        </p>
        </section>

        {{-- The limits belong on the page that makes the promise, not on
             one somebody has to go looking for. --}}
        <footer class="guide-note">
            <p>
                {{ __('The fetcher speaks to Garmin Connect\'s unofficial web API, the same one the website uses, and Garmin can change it without notice. This project is not affiliated with Garmin. Nothing it computes is a medical statement: readiness, load ratios and the coach texts are training aids, not diagnoses.') }}
            </p>
            <p class="mt-2">
                <a href="https://github.com/Imgrund/hybridlog" class="underline underline-offset-2 hover:text-secondary" rel="noreferrer">{{ __('Source and setup instructions on GitHub') }}</a>
            </p>
        </footer>
    </main>
</body>
</html>
