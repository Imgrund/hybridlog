<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    @include('partials.head', ['title' => __('Connect AI')])
</head>
<body class="viz-root min-h-screen antialiased">
    <main class="mx-auto max-w-3xl px-4 py-6 sm:px-6 sm:py-9">
        <header class="mb-5 flex flex-wrap items-center gap-x-3 gap-y-1">
            <h1 class="text-xl font-bold tracking-tight">{{ __('Connect AI') }}</h1>
            <div class="ml-auto"><a href="{{ route('dashboard') }}" class="text-xs text-muted hover:text-secondary">← {{ __('Back to the dashboard') }}</a></div>
        </header>

        @if (session('disconnected'))
            <p class="pill mb-3" data-status="neutral">{{ __('Disconnected, every access token revoked') }}</p>
        @endif

        {{-- ==================================================== status --}}
        {{-- Connected is the everyday state, so the page leads with the
             working surface (status + toggles) and folds the one-time
             reading matter into closed disclosures. Before the first
             connection the setup guide opens instead. --}}
        @if ($connected)
            <section class="card mb-3">
                <div class="flex flex-wrap items-center gap-3">
                    <span class="pill" data-status="good">{{ __('Connected') }}</span>
                    <p class="text-sm text-secondary">
                        {{ $lastUsed
                            ? __('Last used :when.', ['when' => \Carbon\Carbon::parse($lastUsed)->diffForHumans()])
                            : __('Not used yet.') }}
                    </p>
                    {{-- Destructive-ish, so a worded confirm step; the
                         consequence is stated exactly when it matters. --}}
                    <form method="POST" action="{{ route('connect.disconnect') }}" class="ml-auto" x-data="{ confirm: false }">
                        @csrf
                        <span x-show="!confirm">
                            <button type="button" class="btn btn-ghost btn-sm" @click="confirm = true">{{ __('Disconnect') }}</button>
                        </span>
                        <span x-show="confirm" x-cloak class="flex items-center gap-2 text-sm text-secondary">
                            {{ __('Revoke every access token right now?') }}
                            <button type="submit" class="btn btn-sm">{{ __('Yes, disconnect') }}</button>
                            <button type="button" class="btn btn-ghost btn-sm" @click="confirm = false">{{ __('Cancel') }}</button>
                        </span>
                    </form>
                </div>
            </section>
        @else
            <section class="card mb-3">
                <p class="text-sm text-secondary leading-relaxed">
                    {{ __('Connect the dashboard to your AI chat and work with your real Garmin data there: ask questions, have the training load and the body map read out, mention how you feel. A symptom you name there shows up on the body map here.') }}
                </p>
            </section>
        @endif

        {{-- ============================================ data & control --}}
        <section class="card">
            <p class="card-title">{{ __('Data and control: what may the AI do?') }}</p>
            <p class="mt-2 text-sm text-secondary leading-relaxed">
                {{ __('The switches take effect immediately for every connected client; whatever is off stays invisible to the AI.') }}
            </p>

            @if (session('permissions_saved'))
                <p class="pill mt-3" data-status="good">{{ __('Saved') }}</p>
            @endif

            <form method="POST" action="{{ route('connect.permissions') }}" class="mt-3 space-y-2.5">
                @csrf
                {{-- Labels come from the model because a refused tool quotes
                     them back at the AI; the athlete has to find the switch
                     it names on this page. --}}
                @foreach (\App\Models\ConnectorSettings::permissions() as $field => $permission)
                    <label class="flex items-start gap-3">
                        <input type="checkbox" name="{{ $field }}" value="1" @checked($settings->{$field})
                               class="mt-0.5 h-4 w-4 accent-current">
                        <span>
                            <span class="block text-sm font-semibold text-primary">{{ $permission['label'] }}</span>
                            <span class="block text-xs text-muted leading-relaxed">{{ $permission['hint'] }}</span>
                        </span>
                    </label>
                @endforeach
                <button type="submit" class="btn btn-primary">{{ __('Save') }}</button>
            </form>
        </section>

        {{-- ================================================ guidelines --}}
        {{-- Only rendered once feedback exists: an empty list would be a
             heading without information. Deleting is destructive, so it
             follows the page's worded inline-confirm pattern. --}}
        @if ($guidelines->isNotEmpty())
            <section class="card mt-3">
                <p class="card-title">{{ __('Guidelines from your feedback') }}</p>
                <p class="mt-2 text-sm text-secondary leading-relaxed">
                    {{ __('The server appends these rules to its instructions for the AI; they take effect from the next conversation on. Deleting removes a rule for good.') }}
                </p>

                @if (session('guideline_deleted'))
                    <p class="pill mt-3" data-status="neutral">{{ __('Guideline deleted') }}</p>
                @endif

                <ul class="mt-3 space-y-2.5">
                    @foreach ($guidelines as $guideline)
                        <li class="flex items-start gap-3">
                            <span class="flex-1 text-sm text-secondary leading-relaxed">
                                <span class="text-muted">[g{{ $guideline->id }}]</span>
                                {{ $guideline->guideline }}
                            </span>
                            <form method="POST" action="{{ route('connect.guidelines.delete', $guideline) }}" x-data="{ confirm: false }">
                                @csrf
                                <span x-show="!confirm">
                                    <button type="button" class="btn btn-ghost btn-sm" aria-label="{{ __('Delete guideline') }}" @click="confirm = true">{{ __('Delete') }}</button>
                                </span>
                                <span x-show="confirm" x-cloak class="flex items-center gap-2 text-sm text-secondary">
                                    {{ __('Delete for good?') }}
                                    <button type="submit" class="btn btn-sm">{{ __('Yes') }}</button>
                                    <button type="button" class="btn btn-ghost btn-sm" @click="confirm = false">{{ __('Cancel') }}</button>
                                </span>
                            </form>
                        </li>
                    @endforeach
                </ul>
            </section>
        @endif

        {{-- ===================================================== setup --}}
        {{-- One client at a time. Five sets of steps stacked under each
             other would make every reader scroll past four that are not
             theirs, and the list only grows. The picker is a radio group
             rather than a scripted tablist so the steps are readable
             before the bundle loads and wherever it does not; see
             .client-picker in app.css for the no-:has() fallback. --}}
        <details class="card disclosure mt-3" @unless ($connected) open @endunless>
            <summary class="card-title">{{ __('Set up the connection') }}</summary>
            <div class="client-picker mt-3">
                <fieldset>
                    <legend class="mb-2 text-xs text-muted">{{ __('Which app are you connecting?') }}</legend>
                    <div class="client-strip">
                        <input type="radio" name="setup-client" id="client-claude" checked>
                        <label class="client-choice" for="client-claude">Claude</label>
                        <input type="radio" name="setup-client" id="client-chatgpt">
                        <label class="client-choice" for="client-chatgpt">ChatGPT</label>
                        <input type="radio" name="setup-client" id="client-langdock">
                        <label class="client-choice" for="client-langdock">Langdock</label>
                        <input type="radio" name="setup-client" id="client-lechat">
                        {{-- Both names on one label: Mistral renamed the
                             product to Vibe, its own documentation still
                             says Le Chat, and whoever is looking for one
                             of the two must not conclude their client is
                             missing from the list. --}}
                        <label class="client-choice" for="client-lechat">Le Chat / Vibe</label>
                        <input type="radio" name="setup-client" id="client-lmstudio">
                        <label class="client-choice" for="client-lmstudio">LM Studio</label>
                        <input type="radio" name="setup-client" id="client-local">
                        <label class="client-choice" for="client-local">{{ __('On this computer') }}</label>
                    </div>
                </fieldset>

                <div class="client-panel mt-4 text-sm text-secondary leading-relaxed" data-client="claude">
                    {{-- The link only fills the dialog in; the address is
                         still read and the grant still approved by hand,
                         which is why this may be one tap and not a
                         confirm step of its own. Absent when the address
                         is a local one claude.ai could not reach, see
                         ConnectController::claudeAddUrl(). --}}
                    {{-- Deliberately not the primary style, though it is
                         the fastest way through this page. The picker now
                         ranks the clients and only one of them happens to
                         have a link; the page's one primary stays on
                         Save, where a decision is actually committed. --}}
                    @if ($claudeAddUrl)
                        <a href="{{ $claudeAddUrl }}" target="_blank" rel="noopener noreferrer"
                           class="btn btn-sm">
                            {{ __('Open Claude with this connector') }}
                            <svg viewBox="0 0 24 24" width="11" height="11" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M7 17 17 7"/><path d="M8 7h9v9"/></svg>
                        </a>
                        <p class="mt-2 text-xs text-muted">{{ __('Opens the add-connector dialog with the address filled in. You still sign in and approve.') }}</p>
                    @endif
                    <ol class="mt-2 list-decimal space-y-1 pl-5">
                        <li>{!! __('<b>Customize</b> → <b>Connectors</b> → <b>Add custom connector</b>. On a Team or Enterprise plan an owner adds it once under <b>Organization settings</b> → <b>Connectors</b>.') !!}</li>
                        <li>{{ __('Paste the address:') }} <code class="text-primary">{{ $mcpUrl }}</code></li>
                        {{-- The dialog offers OAuth fields and they trip
                             people up: this server registers the client
                             itself, so filling them in is what breaks the
                             connection rather than what completes it. --}}
                        <li>{!! __('Leave the OAuth fields under <b>Advanced settings</b> empty. The sign-in sets itself up.') !!}</li>
                        <li>{!! __('Sign in with your dashboard account and tap <b>Allow</b>.') !!}</li>
                    </ol>
                </div>

                <div class="client-panel mt-4 text-sm text-secondary leading-relaxed" data-client="chatgpt">
                    <ol class="list-decimal space-y-1 pl-5">
                        {{-- Named loosely on purpose: OpenAI has moved
                             this between Connectors, Apps and Plugins
                             more than once, and a wrong path costs more
                             than a vague one. --}}
                        <li>{!! __('Turn on <b>Developer mode</b> in the settings; it has lived under Connectors, Apps and Plugins at different times.') !!}</li>
                        <li>{{ __('Add a connector with this address:') }} <code class="text-primary">{{ $mcpUrl }}</code></li>
                        <li>{{ __('Confirm the OAuth sign-in.') }}</li>
                    </ol>
                </div>

                <div class="client-panel mt-4 text-sm text-secondary leading-relaxed" data-client="langdock">
                    <ol class="list-decimal space-y-1 pl-5">
                        <li>{!! __('<b>Integrations</b> → <b>Add Integration</b> → <b>Start from scratch</b> → <b>Connect remote MCP</b>.') !!}</li>
                        <li>{{ __('Paste the address:') }} <code class="text-primary">{{ $mcpUrl }}</code></li>
                        {{-- The neighbouring option is for servers that
                             cannot register a client themselves. This one
                             can, and picking the wrong entry asks for a
                             client id nobody has. --}}
                        <li>{!! __('Choose <b>OAuth 2.0</b>, not <b>Advanced OAuth 2.0</b>, then <b>+ Add connection</b> and sign in.') !!}</li>
                        <li>{!! __('<b>Test connection</b> lists the tools; pick the ones you want and save.') !!}</li>
                    </ol>
                    <p class="mt-2 text-xs text-muted">{{ __('Creating an integration needs an Editor or Admin seat. In a shared workspace an admin adds it once and shares it.') }}</p>
                </div>

                <div class="client-panel mt-4 text-sm text-secondary leading-relaxed" data-client="lechat">
                    <p>{{ __('Mistral renamed Le Chat to Vibe. The interface says Vibe, their documentation still says Le Chat, and both are this same set of steps.') }}</p>
                    <ol class="mt-2 list-decimal space-y-1 pl-5">
                        <li>{!! __('<b>Kontext</b> → <b>Konnektoren</b> → <b>+ Add Connector</b> → the <b>Custom MCP Connector</b> tab.') !!}</li>
                        <li>{{ __('Give it a name and paste the address:') }} <code class="text-primary">{{ $mcpUrl }}</code></li>
                        {{-- It reads the sign-in off the server rather than
                             asking, but it does show what it found, and a
                             reader who sees three options wants to know
                             which one is theirs. --}}
                        <li>{!! __('<b>Connect</b>, then sign in. It works the authentication out on its own and should land on <b>OAuth 2.1</b>.') !!}</li>
                    </ol>
                    <p class="mt-2 text-xs text-muted">{{ __('Adding a connector is an administrator right. On the Free, Pro and Student plans the account owner is the administrator, so that is you.') }}</p>
                    {{-- Named here rather than left to be discovered: the
                         weekly report is the one thing this server offers
                         that Mistral has no way to show, and its absence
                         otherwise reads as a broken connection. --}}
                    <p class="mt-2 text-xs text-muted">{{ __('Mistral does not do prompt templates yet, so the weekly report is missing there. All twelve tools arrive, each with a switch of its own next to the ones here.') }}</p>
                </div>

                <div class="client-panel mt-4 text-sm text-secondary leading-relaxed" data-client="lmstudio">
                    <ol class="list-decimal space-y-1 pl-5">
                        <li>{!! __('Right sidebar → <b>Program</b> → <b>Install</b> → <b>Edit mcp.json</b>.') !!}</li>
                        <li>{{ __('Add this entry and save:') }}</li>
                    </ol>
                    <pre class="mt-2 overflow-x-auto rounded-md bg-black/20 p-3 text-xs text-primary">{{ json_encode([
                        'mcpServers' => ['garmin-health' => ['url' => $mcpUrl]],
                    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
                    {{-- A known LM Studio issue with servers outside its
                         own directory, worth naming here: the sign-in
                         failing to start looks like a broken address, and
                         the reader would otherwise go hunting on the
                         wrong side. --}}
                    <p class="mt-2 text-xs text-muted">{{ __('LM Studio sometimes reports a connection error instead of opening the sign-in. That is a quirk of its own, not a wrong address; running it on this computer avoids it.') }}</p>
                </div>

                <div class="client-panel mt-4 text-sm text-secondary leading-relaxed" data-client="local">
                    <p>{{ __('No address and no sign-in: the server runs as a subprocess of the client.') }}</p>
                    <pre class="mt-2 overflow-x-auto rounded-md bg-black/20 p-3 text-xs text-primary">claude mcp add --scope user garmin-health -- php /path/to/hybridlog/artisan mcp:start garmin</pre>
                    <p class="mt-3">{{ __('LM Studio and anything else that takes a config file want the same thing spelled out:') }}</p>
                    <pre class="mt-2 overflow-x-auto rounded-md bg-black/20 p-3 text-xs text-primary">{{ json_encode([
                        'mcpServers' => ['garmin-health' => [
                            'command' => '/absolute/path/to/php',
                            'args' => ['/absolute/path/to/hybridlog/artisan', 'mcp:start', 'garmin'],
                        ]],
                    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
                    <p class="mt-2 text-xs text-muted">{{ __('Write the path to PHP out in full. Some clients work out their PATH differently from your terminal and will not find it otherwise.') }}</p>
                </div>
            </div>
        </details>

        {{-- ================================================== examples --}}
        <details class="card disclosure mt-3">
            <summary class="card-title">{{ __('Examples: what you can say in the chat') }}</summary>
            <ul class="mt-3 list-disc space-y-1 pl-5 text-sm text-secondary leading-relaxed">
                <li>{{ __('"How did my training week compare with the one before?"') }}</li>
                <li>{{ __('"Am I ready for a hard session or do I need a rest day?"') }}</li>
                <li>{{ __('"Which muscles are fresh, and what should I train today?"') }}</li>
                <li>{{ __('"Where did my pace go in Saturday\'s race, and how much of the clock was station work?"') }}</li>
                <li>{{ __('"My left knee hurt on the box jumps": it lands on the body map.') }}</li>
            </ul>
        </details>

        {{-- ================================================== security --}}
        <details class="card disclosure mt-3">
            <summary class="card-title">{{ __('Security and data freshness') }}</summary>
            <ul class="mt-3 list-disc space-y-1 pl-5 text-sm text-secondary leading-relaxed">
                <li>{{ __('Sign-in happens through a revocable grant: the AI never sees your password.') }}</li>
                <li>{{ __('Garmin data is read-only; the one thing the AI may write is the symptoms you mention.') }}</li>
                <li>{{ __('Last Garmin fetch:') }}
                    <b class="text-primary">{{ $lastFetch ? \Carbon\Carbon::parse($lastFetch)->isoFormat(__('MMM D, YYYY, HH:mm')) : __('none yet') }}</b>.</li>
            </ul>
        </details>
    </main>
</body>
</html>
