<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
{{-- The page an invited athlete is sent to: everything between holding
     a password and asking a chat about last night, in the order it
     happens, with nothing left to ask the person who invited them.

     Kept to the setup itself. Anything that explains, sells or
     reassures belongs to the guide at "/", which is the page for
     somebody still deciding. A reader who is here has decided and
     wants to be done, so every line either says what to do or names
     the one thing that would otherwise be a surprise.

     Public on purpose. It is read before the first sign-in, so the
     links into the app lead through the login rather than around it,
     and it names no athlete and reads no mirror. --}}
<head>
    @include('partials.head', ['title' => __('Getting set up')])
</head>
<body class="viz-root min-h-screen antialiased">
    <main class="mx-auto w-full max-w-3xl px-4 py-7 sm:px-6 sm:py-11">
        <header class="mb-7 flex flex-wrap items-center gap-x-4 gap-y-2">
            <span class="brand-lockup">
                <span class="brand-slash" aria-hidden="true"></span>
                <span>hybridlog</span>
            </span>
            <div class="ml-auto"><a href="{{ route('dashboard') }}" class="text-xs text-muted hover:text-secondary">{{ __('What this is') }} →</a></div>
        </header>

        <section class="guide-plate">
            <div class="guide-plate-main">
                <p class="guide-eyebrow">{{ $demoMode ? __('Public demo') : __('Getting set up') }}</p>
                {{-- The time that is actually the reader's: signing in,
                     a Garmin login, a town. The ninety-day backfill is
                     twelve times longer and belongs nowhere near this
                     line, because nobody sits through it. It is named
                     at the step where it starts. --}}
                <h1 class="guide-answer">{{ $demoMode ? __('Nothing to set up here.') : __('Connected in three minutes.') }}</h1>
                <p class="guide-lede">
                    {{ $demoMode
                        ? __('This copy is already connected, on generated data, and everything is put back every night.')
                        : __('You need the account details you were given and your Garmin login.') }}
                </p>
                {{-- The page's one primary action, and the only one that
                     is true for every reader: nothing else here works
                     before it. --}}
                <a href="{{ route('login') }}" class="btn btn-primary guide-action">
                    {{ __('Sign in') }}
                    <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12h14"/><path d="m12 5 7 7-7 7"/></svg>
                </a>
            </div>
        </section>

        @if ($demoMode)
            {{-- Three of the four steps below are closed on the public
                 demo, so it gets none of them. A numbered trail that
                 cannot be walked reads as a broken page, not as a
                 restriction, and the reader would find that out one
                 refusal at a time. The wording is the one the closed
                 doors themselves use (resources/views/demo-locked.blade.php),
                 so a visitor who gets there anyway hears no second
                 story about the same installation. --}}
            {{-- No "Public demo" pill on this card, though the closed
                 doors carry one: the plate directly above already says
                 it in its eyebrow, and twice on one screen reads as a
                 template rather than as a statement. --}}
            <section class="card mt-3">
                <p class="text-sm text-secondary leading-relaxed">
                    {{ __('Everybody who visits this dashboard signs in to the same account, so the parts that would reach out of it are switched off.') }}
                </p>
                <ul class="mt-3 list-disc space-y-1 pl-5 text-sm text-secondary leading-relaxed">
                    <li>{{ __('Signing in to Garmin. Nobody should type their real watch password into a dashboard a stranger put on the internet.') }}</li>
                    <li>{{ __('Naming your town, which would send a lookup to a weather service on this installation\'s account.') }}</li>
                    <li>{{ __('Connecting an AI, which would hand a chat client a token to an account that is not yours.') }}</li>
                </ul>
            </section>

            <section class="card mt-3">
                <p class="card-title">{{ __('To use these, run your own copy') }}</p>
                <p class="mt-2 text-sm text-secondary leading-relaxed">
                    {{ __('It is self-hosted and the readme opens with a quickstart: one clone, one compose file, your own watch.') }}
                </p>
            </section>
        @else
        <section class="guide-how">
            <ol class="guide-trail">
                {{-- ================================================== 1 --}}
                <li class="guide-step">
                    <span class="guide-step-mark" aria-hidden="true">1</span>
                    <div>
                        <h2 class="guide-step-name">{{ __('Sign in') }}</h2>
                        {{-- The one thing worth a line: somebody who goes
                             looking for a sign-up page will not find one. --}}
                        <p class="guide-step-body">{{ __('There is no sign-up page; the account already exists.') }}</p>
                    </div>
                </li>

                {{-- ================================================== 2 --}}
                <li class="guide-step">
                    <span class="guide-step-mark" aria-hidden="true">2</span>
                    <div>
                        <h2 class="guide-step-name">{{ __('Connect your own Garmin account') }}</h2>
                        <p class="guide-step-body">
                            {{ __('In the account menu, under Garmin. What is kept is a token, never your password.') }}
                        </p>
                        {{-- The quarter of an hour is the surprise this
                             page exists to take away. --}}
                        <p class="guide-step-body mt-2">
                            {{ __('The first sign-in backfills ninety days, about a quarter of an hour in the background. You do not have to wait for it.') }}
                        </p>
                    </div>
                </li>

                {{-- ================================================== 3 --}}
                <li class="guide-step">
                    <span class="guide-step-mark" aria-hidden="true">3</span>
                    <div>
                        <h2 class="guide-step-name">
                            {{ __('Say where you train') }}
                            <span class="pill guide-step-pill" data-status="neutral">{{ __('One line') }}</span>
                        </h2>
                        <p class="guide-step-body">
                            {{ __('Name your town under Profile, or the weather beside your sleep stays where this installation was set up.') }}
                        </p>
                    </div>
                </li>

                {{-- ================================================== 4 --}}
                <li class="guide-step">
                    <span class="guide-step-mark" aria-hidden="true">4</span>
                    <div>
                        <h2 class="guide-step-name">
                            {{ __('Connect your chat') }}
                            <span class="pill guide-step-pill" data-status="neutral">{{ __('Optional') }}</span>
                        </h2>
                        <p class="guide-step-body">
                            {{ __('This is what lets you ask questions of your own numbers instead of reading charts.') }}
                        </p>

                        @if ($claudeAddUrl)
                            {{-- Quieter than the sign-in above on purpose:
                                 this step is optional and the fourth of
                                 four, and the page's one primary belongs
                                 to the step without which none of the
                                 others work. --}}
                            <a href="{{ $claudeAddUrl }}" target="_blank" rel="noopener noreferrer" class="btn btn-sm mt-3">
                                {{ __('Open Claude with this connector') }}
                                <svg viewBox="0 0 24 24" width="11" height="11" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M7 17 17 7"/><path d="M8 7h9v9"/></svg>
                            </a>
                        @endif

                        <p class="guide-step-body mt-3">{{ __('By hand, in Claude:') }}</p>
                        <ol class="mt-1 list-decimal space-y-1 pl-5 text-sm text-secondary leading-relaxed">
                            <li>{!! __('<b>Customize</b> → <b>Connectors</b> → <b>Add custom connector</b>.') !!}</li>
                            <li>{{ __('Paste the address:') }} <code class="text-primary">{{ $mcpUrl }}</code></li>
                            <li>{!! __('Leave the OAuth fields under <b>Advanced settings</b> empty. The sign-in sets itself up.') !!}</li>
                            <li>{!! __('Sign in with your dashboard account and tap <b>Allow</b>.') !!}</li>
                        </ol>
                        <p class="guide-step-body mt-3">{{ __('Custom connectors need a paid Claude plan.') }}</p>
                    </div>
                </li>
            </ol>
        </section>
        @endif

        {{-- ==================================================== limits --}}
        <footer class="guide-note">
            <p>
                {{-- The demo has no chat connection to revoke, so it is
                     not promised one. What survives is the sentence that
                     is true of every copy. --}}
                {{ $demoMode
                    ? __('Nothing this computes is a medical statement.')
                    : __('Garmin data is read-only here, the chat connection can be revoked in one click and nothing this computes is a medical statement.') }}
            </p>
        </footer>
    </main>
</body>
</html>
