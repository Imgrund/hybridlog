{{-- Body map, sports-dashboard canon: silhouettes with muscle-load
     fills, five finding markers with large pill captions (hidden on
     narrow screens where the findings list carries the info).
     Zones with load history are real buttons (Tab, Enter, Space);
     zones Garmin never reports carry no affordance and answer a tap
     with an honest "unknown" explanation instead of a value.
     Selecting anything dims the rest of the figure (Visible-Body
     pattern): focus moves, context stays visible.

     Two things the figure carries beside the load, each on its own
     channel so the blue ramp keeps meaning exactly one thing: an edge
     on zones that need a decision today, and a marker where a symptom
     was reported.
     Polygons: MIT license, body-highlighter project family. --}}
@php
    // $findings and $statusLabels come from the component: the caption
    // widths they produce decide the viewBox margin, so both live where
    // that calculation lives.

    // Organ pictograms for the detail panels: minimal line art, tinted
    // by status ink. Drawn inline, stroke follows currentColor.
    $organs = [
        'heart' => '<path d="M12 20.5C7 16.5 3.5 13 3.5 9.3 3.5 6.4 5.7 4.5 8.2 4.5c1.6 0 3 .8 3.8 2.1C12.8 5.3 14.2 4.5 15.8 4.5c2.5 0 4.7 1.9 4.7 4.8 0 3.7-3.5 7.2-8.5 11.2Z"/><polyline points="5.5 12 9 12 10.5 9.5 12.5 14.5 14 11.5 15.2 12 18.5 12"/>',
        'head' => '<path d="M17.5 13.2A7 7 0 1 1 10.8 4.5a5.6 5.6 0 0 0 6.7 8.7Z"/>',
        'lungs' => '<path d="M12 4v6.5"/><path d="M9.8 8.5c-.9-1-2.3-1.4-3.5-.7C4.2 9 3 11.6 3 14.6c0 2.3.6 4 2.3 4.7 1.8.8 4-.2 4.4-2 .2-.9.2-2.2.2-3.8 0-2-.1-4-.1-5Z"/><path d="M14.2 8.5c.9-1 2.3-1.4 3.5-.7C19.8 9 21 11.6 21 14.6c0 2.3-.6 4-2.3 4.7-1.8.8-4-.2-4.4-2-.2-.9-.2-2.2-.2-3.8 0-2 .1-4 .1-5Z"/>',
        'core' => '<path d="M12 3.5v17"/><path d="M12 6.5c-2.4 0-4.1 1-5.4 2.6M12 6.5c2.4 0 4.1 1 5.4 2.6"/><path d="M12 11c-2 0-3.5.8-4.6 2.2M12 11c2 0 3.5.8 4.6 2.2"/><path d="M12 15.4c-1.5 0-2.7.6-3.6 1.7M12 15.4c1.5 0 2.7.6 3.6 1.7"/>',
        'metabolism' => '<path d="M13.5 3.5c.6 2.5-.3 4-1.8 5.5-1.7 1.7-3.4 3.4-3.4 6.2a5.7 5.7 0 0 0 11.4 0c0-2-1-3.6-2.2-5-.5 1.1-1.2 1.8-2.1 2.2.3-3-1-6.7-1.9-8.9Z"/><path d="M10.5 15.8a2.6 2.6 0 0 0 3 2.9"/>',
    ];

    $fig = $sides['anterior']['figure'];
    $windows = App\Garmin\MuscleFreshness::WINDOWS;
@endphp

{{-- Three cards, two rows: map and findings share the top row, the zone
     ranking and the load scale take their own full-width row below. Both
     of those explain the figure rather than the findings, and both read
     better wide than stacked in a narrow column; keeping them in the
     findings card was also what made that card run twice the height of
     the map beside it. --}}
<div
    class="grid gap-3 lg:grid-cols-[1.6fr_1fr]"
    x-data="{
        sel: null,
        // Which reading the figure paints. Freshness decays and answers
        // 'what can I train tonight'; the volume windows do not decay and
        // answer 'where did my training go'. Two questions, so the control
        // names the window on screen instead of letting one colour mean
        // both.
        lens: 'freshness',
        // Pointing at a zone anywhere lights it up everywhere. The map and
        // the ranking are two views of one list, and without the link the
        // list is a legend you have to match up by eye.
        lit: null,
        // Narrow means the detail cannot sit beside the map, so it becomes
        // a sheet over the page instead. One flag drives both the layout
        // and the scrolling, so the two can never disagree about which
        // shape is on screen; the breakpoint is the lg: the grid uses.
        narrow: window.matchMedia('(max-width: 1023px)').matches,
        systems: @js($systems),
        zones: @js($zones),
        statusLabels: @js($statusLabels),
        get selSystem() { return this.sel && this.systems[this.sel] ? this.systems[this.sel] : null },
        get selZone() { return this.sel && this.zones[this.sel] ? this.zones[this.sel] : null },
        get lensNote() {
            return this.lens === 'freshness'
                ? @js(__('Colour = load left in the zone right now.'))
                : @js(__('Colour = accumulated load, against your loudest zone in the window.'));
        },
        fillOf(zone) {
            const z = this.zones[zone];
            return z && z.fills ? z.fills[this.lens] : 'var(--map-neutral)';
        },
        init() {
            window.matchMedia('(max-width: 1023px)').addEventListener('change', (e) => {
                this.narrow = e.matches;
                this.$nextTick(() => this.reserveSheetRoom());
            });
            // Every path into a selection ends here: the map, the chips,
            // the findings list, Escape and the back button.
            this.$watch('sel', () => this.$nextTick(() => this.reserveSheetRoom()));
        },
        toggle(key, from = null) {
            this.sel = this.sel === key ? null : key;
            if (this.sel && from) this.$nextTick(() => this.keepInView(from));
        },
        smooth() { return window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth' },
        keepInView(el) {
            // Wide: the detail always lands in the panel card, which sits
            // above the zone list, so a tap in that list can replace
            // content the reader cannot see. 'nearest' alone does not fix
            // it: the panel is tall enough that its tail stays on screen
            // while its head, where the detail begins, is already gone.
            // Aim at the head in that case and let 'nearest' handle the
            // panel-below-the-fold case, where it scrolls the least.
            if (!this.narrow) {
                const panel = this.$refs.panel;
                panel.scrollIntoView({
                    behavior: this.smooth(),
                    block: panel.getBoundingClientRect().top < 0 ? 'start' : 'nearest',
                });

                return;
            }
            // Narrow: the sheet is the thing that moved, not the page. The
            // page moves only as far as it takes to lift what was tapped
            // out from under the sheet, so the map stays where the reader
            // left it instead of being scrolled away from under them.
            const sheet = this.$refs.detail.getBoundingClientRect();
            const box = el.getBoundingClientRect();
            const gap = 12;
            let by = box.bottom > sheet.top - gap ? box.bottom - sheet.top + gap : 0;
            // Anything taller than the room left over is shown from its
            // top: a zone whose head is off screen cannot be read at all.
            if (box.top - by < gap) by = box.top - gap;
            if (Math.abs(by) < 1) return;
            window.scrollBy({ top: by, behavior: this.smooth() });
        },
        // The sheet floats over the page, so the page has to end above it:
        // without the room, the last rows of the zone list can never be
        // scrolled out from under it.
        reserveSheetRoom() {
            this.$el.style.paddingBottom = this.narrow && this.sel
                ? this.$refs.detail.offsetHeight + 'px'
                : '';
        },
        locale: @js(str_replace('_', '-', app()->getLocale())),
        fmtDate(d) {
            if (!d) return '–';
            return new Date(d + 'T12:00:00').toLocaleDateString(this.locale, { weekday: 'short', day: '2-digit', month: '2-digit' });
        },
        relDays(n) {
            if (n === 0) return @js(__('today'));
            if (n === 1) return @js(__('yesterday'));
            return @js(__(':days days ago', ['days' => '{n}'])).replace('{n}', n);
        },
        // The forecast is a projection off the decay curve, so it is
        // spelled as a moment, not as a countdown that ticks: any session
        // today makes it wrong, and the panel says so beneath it.
        fmtForecast(at) {
            if (!at) return null;
            const d = new Date(at.replace(' ', 'T'));
            const day = d.toDateString() === new Date().toDateString()
                ? @js(__('today'))
                : d.toLocaleDateString(this.locale, { weekday: 'long' });
            return day + ', ' + d.toLocaleTimeString(this.locale, { hour: '2-digit', minute: '2-digit' });
        },
    }"
    x-on:keydown.escape.window="sel = null"
>
    {{-- --------------------------------------------------- the reading --}}
    {{-- One sentence over the figure. The card is the centre of this tab,
         and a body without a reading is an anatomy poster: this is the
         line that turns it into a coaching surface. Everything in it is
         drawn from the same numbers the zones carry. --}}
    @if ($story !== null)
        <p class="bm-story lg:col-span-2" data-tone="{{ $story['tone'] }}">
            <span class="bm-story-lead">{{ $story['lead'] }}</span>
            @if ($story['follow'] !== null)
                <span class="bm-story-follow">{{ $story['follow'] }}</span>
            @endif
        </p>
    @endif

    {{-- --------------------------------------- both figures, one card --}}
    {{-- The solid is a second rendering of this same state, mounted into
         the stage below and driven by x-effect. It never replaces the
         flat map in the DOM: that one stays the accessible surface, and
         the zone chips stay the control in both modes. --}}
    <figure class="card" x-data="body3d" x-on:zone-pick.stop="toggle($event.detail)"
            x-effect="syncSolid(zones, lens, sel)">
        <x-card-head as="figcaption" :name="__('Muscle load')"
                     :desc="__('Both sides at one scale · tap a zone or a finding')">
            <x-slot:aside>
                {{-- The lens, beside the title rather than under the
                     figure: it changes what the colour means, so it has to
                     be read before the figure, not after it. --}}
                <div class="lens" role="group" aria-label="{{ __('Reading of the map') }}">
                    @foreach ($lenses as $lens)
                        <button type="button" class="lens-btn"
                                x-bind:aria-pressed="lens === '{{ $lens['key'] }}' ? 'true' : 'false'"
                                x-on:click="lens = '{{ $lens['key'] }}'"
                                title="{{ $lens['desc'] }}">{{ $lens['name'] }}</button>
                    @endforeach
                </div>
                {{-- Only offered once the module has loaded and answered
                     that this browser can run it: a control that appears
                     and then turns out not to work is worse than none. --}}
                <button type="button" class="lens-btn lens-solid" x-cloak x-show="solidOffered"
                        x-bind:aria-pressed="solid ? 'true' : 'false'"
                        x-on:click="toggleSolid()"
                        title="{{ __('Show the same reading on a rotatable body') }}">{{ __('3D') }}</button>
            </x-slot:aside>
        </x-card-head>

        {{-- The stage. Sized like the flat figures so switching does not
             move the page; drag or arrow keys turn it, a tap picks a
             zone. Marked as decorative: everything it can say is said by
             the zone list, which stays keyboard-operable throughout.

             The beat rides in on the element rather than through the
             component, the same way the tile heart carries it, so the
             viewer needs nothing from Alpine to know the reader's pulse.
             Absent both attributes, the heart in there is still. --}}
        <div class="bm-stage mt-3" x-ref="stage" x-cloak x-show="solid" tabindex="-1"
             aria-hidden="true"
             @if ($beat) data-beat-interval="{{ $beat->interval }}" data-beat-sway="{{ $beat->sway }}" @endif></div>
        <p class="mt-1 text-center text-xs text-muted" x-cloak x-show="solid">
            {{ __('Drag to turn, arrow keys to step, tap a muscle or the heart for its detail.') }}
        </p>

        {{-- The two boxes are sized by their unit widths, not split in half:
             only the anterior carries caption pills, so only it needs the
             margin. Both silhouettes come out at exactly one scale. --}}
        <div class="bm-figures mt-3 grid gap-2" x-show="!solid"
             style="--bm-ant: {{ $sides['anterior']['unitWidth'] }}; --bm-post: {{ $sides['posterior']['unitWidth'] }}">
            @foreach (['anterior' => __('Front'), 'posterior' => __('Back')] as $side => $sideWord)
            <div>
                <svg viewBox="{{ $sides[$side]['viewBox'] }}" class="bm-fig h-[380px] w-full sm:h-[460px]" role="group"
                     x-bind:class="{ 'bm-dim': sel !== null }"
                     aria-label="{{ $side === 'anterior' ? __('Body map, front, with findings') : __('Body map, back: muscle load') }}">
                    <defs>
                        {{-- hatch = zone without any load history; patterns and
                             gradients are referenced by id, so each figure
                             carries its own copy --}}
                        <pattern id="bm-nodata-{{ $side }}" width="3.4" height="3.4"
                                 patternUnits="userSpaceOnUse" patternTransform="rotate(45)">
                            <rect width="3.4" height="3.4" fill="var(--map-neutral)" />
                            <line x1="0" y1="0" x2="0" y2="3.4" stroke="var(--map-hatch)" stroke-width="1.1" />
                        </pattern>

                        {{-- Depth without a second colour scale: one light
                             source over the whole figure, clipped to the
                             silhouette and blended over whatever fill the
                             zone carries. Per-zone gradients would each have
                             to know their zone's colour; this one does not,
                             so it survives every lens and both themes.
                             Radial rather than vertical: a top-down ramp
                             lights the head and shades the feet, a light
                             placed off the shoulder gives the body volume. --}}
                        <radialGradient id="bm-shade-{{ $side }}" cx="0.34" cy="0.2" r="0.95">
                            <stop offset="0" stop-color="var(--map-shade-top)" />
                            <stop offset="0.42" stop-color="var(--map-shade-mid)" />
                            <stop offset="1" stop-color="var(--map-shade-bottom)" />
                        </radialGradient>
                        <clipPath id="bm-body-{{ $side }}">
                            @foreach ($sides[$side]['entries'] as $entry)
                                @foreach ($entry['polygons'] as $poly)
                                    <polygon points="{{ $poly }}" />
                                @endforeach
                            @endforeach
                        </clipPath>
                    </defs>
                    @php $nodataFill = "url(#bm-nodata-{$side})"; @endphp

                    @foreach ($sides[$side]['entries'] as $entry)
                        @php
                            $zone = $entry['zone'];
                            $zd = $zones[$zone] ?? null;
                        @endphp
                        @if ($zd && $zd['hasData'])
                            <g class="bm-zone" data-zone="{{ $zone }}"
                               role="button" tabindex="0"
                               aria-label="{{ __(':zone: :band, :percent % fresh', ['zone' => $zd['label'], 'band' => $zd['bandLabel'], 'percent' => $zd['freshness']]) }}"
                               x-bind:aria-pressed="sel === '{{ $zone }}' ? 'true' : 'false'"
                               x-bind:class="{ 'bm-selected': sel === '{{ $zone }}', 'bm-lit': lit === '{{ $zone }}' }"
                               x-on:click="toggle('{{ $zone }}', $event.currentTarget)"
                               x-on:keydown.enter.prevent="toggle('{{ $zone }}', $event.currentTarget)"
                               x-on:keydown.space.prevent="toggle('{{ $zone }}', $event.currentTarget)"
                               x-on:pointerenter="lit = '{{ $zone }}'" x-on:pointerleave="lit = null"
                               x-on:focus="lit = '{{ $zone }}'" x-on:blur="lit = null">
                                @foreach ($entry['polygons'] as $poly)
                                    <polygon points="{{ $poly }}" x-bind:fill="fillOf('{{ $zone }}')" />
                                @endforeach
                            </g>
                        @elseif ($zd)
                            {{-- no history: not a control (no focus, no hover),
                                 a pointer tap still opens the honest explanation --}}
                            <g class="bm-zone-nodata" data-zone="{{ $zone }}" aria-hidden="true"
                               x-bind:class="{ 'bm-selected': sel === '{{ $zone }}', 'bm-lit': lit === '{{ $zone }}' }"
                               x-on:click="toggle('{{ $zone }}', $event.currentTarget)">
                                @foreach ($entry['polygons'] as $poly)
                                    <polygon points="{{ $poly }}" fill="{{ $nodataFill }}" />
                                @endforeach
                            </g>
                        @else
                            <g class="bm-static" aria-hidden="true">
                                @foreach ($entry['polygons'] as $poly)
                                    <polygon points="{{ $poly }}" />
                                @endforeach
                            </g>
                        @endif
                    @endforeach

                    {{-- The light, over the fills and under everything that
                         carries meaning. Pointer-transparent, so it never
                         eats a tap meant for a zone. --}}
                    <rect class="bm-shade" clip-path="url(#bm-body-{{ $side }})"
                          fill="url(#bm-shade-{{ $side }})" aria-hidden="true"
                          x="{{ $fig['x'] - 20 }}" y="{{ $fig['y'] - 20 }}"
                          width="{{ $fig['w'] + 40 }}" height="{{ $fig['h'] + 40 }}" />

                    {{-- Second channel, drawn over the shading so it survives
                         it: an outline on the zones that need a decision
                         today. Never a second fill colour, because the ramp
                         is the one carrier of "how much load". --}}
                    @foreach ($sides[$side]['entries'] as $entry)
                        @php
                            $zone = $entry['zone'];
                            $zd = $zones[$zone] ?? null;
                        @endphp
                        @if ($zd && ($zd['flagged'] ?? false))
                            <g class="bm-flag" aria-hidden="true">
                                @foreach ($entry['polygons'] as $poly)
                                    <polygon points="{{ $poly }}" />
                                @endforeach
                            </g>
                        @endif
                    @endforeach

                    {{-- Reported complaints, as their own mark rather than a
                         tint: a symptom is what the athlete felt, the fill is
                         what the model computed, and merging the two would
                         make neither readable. --}}
                    @foreach ($sides[$side]['entries'] as $entry)
                        @php
                            $zone = $entry['zone'];
                            $sym = $zones[$zone]['symptom'] ?? null;
                        @endphp
                        @if ($sym)
                            @php
                                $c = App\View\Components\BodyMap::polygonCentre($entry['polygons']);
                            @endphp
                            <g class="bm-symptom" data-severity="{{ $sym['severity'] ?? 1 }}"
                               x-on:click="toggle('{{ $zone }}', $event.currentTarget)">
                                <circle cx="{{ $c['x'] }}" cy="{{ $c['y'] }}" r="4.6" class="bm-symptom-dot" />
                                <path d="M{{ $c['x'] }},{{ $c['y'] - 2.4 }} v3.1 M{{ $c['x'] }},{{ $c['y'] + 2.2 }} v0.1"
                                      class="bm-symptom-mark" />
                                <title>{{ $sym['symptom'] }}</title>
                            </g>
                        @endif
                    @endforeach

                    @if ($side === 'anterior')
                        {{-- finding markers with pill captions --}}
                        @foreach ($findings as $key => $f)
                            @if (isset($systems[$key]))
                                @php
                                    $sys = $systems[$key];
                                    $statusWord = $statusLabels[$sys['status']];
                                    $cx = $fig['x'] + $f['fx'] * $fig['w'];
                                    $cy = $fig['y'] + $f['fy'] * $fig['h'];
                                    $capY = $cy + $f['capdy'];
                                    $right = $f['side'] === 'right';
                                    $pillW = $captionWidth($f['name'], $statusWord);
                                    $pillH = 17.5;
                                    $pillX = $captionX($f, $pillW);
                                    $textX = $pillX + 5;
                                    $anchorX = $right ? $pillX : $pillX + $pillW;
                                    $kneeX = $right ? $cx + 8 : $cx - 8;
                                @endphp
                                <g class="bm-marker"
                                   role="button" tabindex="0"
                                   aria-label="{{ $sys['label'] }}: {{ $statusWord }}"
                                   x-bind:aria-pressed="sel === '{{ $key }}' ? 'true' : 'false'"
                                   x-bind:class="{ 'bm-selected': sel === '{{ $key }}' }"
                                   x-on:click="toggle('{{ $key }}', $event.currentTarget)"
                                   x-on:keydown.enter.prevent="toggle('{{ $key }}', $event.currentTarget)"
                                   x-on:keydown.space.prevent="toggle('{{ $key }}', $event.currentTarget)">
                                    <g class="bm-pill-group">
                                        <polyline class="bm-leader"
                                            points="{{ $cx }},{{ $cy }} {{ $kneeX }},{{ $capY }} {{ $anchorX }},{{ $capY }}" />
                                        <rect class="bm-pill-bg" x="{{ $pillX }}" y="{{ $capY - $pillH / 2 }}"
                                              width="{{ $pillW }}" height="{{ $pillH }}" rx="4.5" />
                                        <text class="bm-cap-name" x="{{ $textX }}" y="{{ $capY - 1.6 }}">{{ $f['name'] }}</text>
                                        <text class="bm-cap-status" x="{{ $textX }}" y="{{ $capY + 5.4 }}"
                                              style="fill: var(--status-{{ $sys['status'] }}-ink)">{{ $statusWord }}</text>
                                    </g>
                                    {{-- keyboard focus rides on the dot, the one part
                                         that survives the narrow layout where the
                                         pill is hidden --}}
                                    <circle class="bm-focus-ring" cx="{{ $cx }}" cy="{{ $cy }}" r="8.2" />
                                    <circle class="dot" cx="{{ $cx }}" cy="{{ $cy }}" r="4.4"
                                            style="stroke: var(--status-{{ $sys['status'] }}-ink)" />
                                    <circle cx="{{ $cx }}" cy="{{ $cy }}" r="1.9"
                                            style="fill: var(--status-{{ $sys['status'] }}-ink)" />
                                    <title>{{ $sys['label'] }}: {{ $statusWord }}</title>
                                </g>
                            @endif
                        @endforeach
                    @endif
                </svg>
                <p class="mt-1 text-center text-xs text-muted">{{ $sideWord }}</p>
            </div>
            @endforeach
        </div>

        <p class="mt-2 text-center text-xs text-muted" x-text="lensNote"></p>
        {{-- A hint that names a list a screen and a half further down has
             to be the way there as well, or the phone reader is told
             about a shortcut they still have to hunt for. Plain anchor:
             it works before Alpine and survives a reload. --}}
        <p class="mt-1 text-center text-xs text-muted sm:hidden">
            <a href="#bm-zones" class="underline-offset-2 hover:text-secondary hover:underline">
                {{ __('Small zones are easier to hit from the zone list further down.') }}
            </a>
        </p>
    </figure>

    {{-- ----------------------------------------------- findings panel --}}
    <div class="card bm-panel" x-ref="panel">
        <x-card-head :name="__('Findings and recommendation')"
                     :desc="__('Five body systems from sleep, HRV, pulse, load and metabolism')" />

        {{-- Default, and on a phone the permanent state of this card: the
             detail moves into a sheet there, so the list it was replacing
             can stay put and go on being the way between the findings.
             The closing hint is pinned to the bottom edge, so whatever
             height the map beside it sets becomes deliberate air over that
             line instead of a ragged gap. --}}
        <div x-show="!sel || narrow" class="bm-panel-body mt-3">
            <div class="space-y-2">
                <template x-for="[key, sys] of Object.entries(systems)" :key="key">
                    <button type="button" class="finding-row"
                            x-bind:aria-pressed="sel === key ? 'true' : 'false'"
                            x-on:click="toggle(key, $event.currentTarget)">
                        <span class="flex-1">
                            <span class="block text-[0.95rem] font-semibold" x-text="sys.label"></span>
                            <span class="block text-sm text-secondary num" x-text="sys.value"></span>
                        </span>
                        <span class="pill" x-bind:data-status="sys.status" x-text="statusLabels[sys.status]"></span>
                    </button>
                </template>
            </div>
            <p class="bm-panel-foot text-xs text-muted">
                {{ __('Tap a finding or a muscle zone. Colour depth of a zone = accumulated load.') }}
            </p>
        </div>

        {{-- The detail, in one wrapper because on a phone it leaves the
             card: there it is a sheet over the lower edge of the screen,
             which is what keeps the map on screen behind it. In the flow
             it takes no room while nothing is selected, and it takes none
             as a sheet either, so opening one shifts nothing. --}}
        <div x-ref="detail" x-show="sel" x-cloak aria-live="polite"
             x-bind:class="{ 'bm-sheet': narrow && sel }">
            {{-- Sheet only: the head is what stays put while the detail
                 scrolls, and the thumbnail on it answers the question the
                 real map cannot when it is a screen further up. --}}
            <div class="bm-sheet-head" x-show="narrow">
                <div class="bm-mini" aria-hidden="true">
                    @foreach (['anterior', 'posterior'] as $side)
                        <svg viewBox="{{ $sides[$side]['figureBox'] }}" class="bm-mini-fig">
                            @foreach ($sides[$side]['entries'] as $entry)
                                @php $zone = $entry['zone']; @endphp
                                <g class="bm-mini-zone"
                                   @if (isset($zones[$zone])) x-bind:class="{ 'bm-mini-on': sel === '{{ $zone }}' }" @endif>
                                    @foreach ($entry['polygons'] as $poly)
                                        <polygon points="{{ $poly }}" />
                                    @endforeach
                                </g>
                            @endforeach
                            @if ($side === 'anterior')
                                @foreach ($findings as $key => $f)
                                    @if (isset($systems[$key]))
                                        <circle class="bm-mini-dot" r="6"
                                                cx="{{ round($fig['x'] + $f['fx'] * $fig['w'], 1) }}"
                                                cy="{{ round($fig['y'] + $f['fy'] * $fig['h'], 1) }}"
                                                x-bind:class="{ 'bm-mini-on': sel === '{{ $key }}' }" />
                                    @endif
                                @endforeach
                            @endif
                        </svg>
                    @endforeach
                </div>
                <p class="bm-sheet-title" x-text="selSystem?.label ?? selZone?.label ?? ''"></p>
                <button type="button" class="bm-sheet-close" x-on:click="sel = null"
                        aria-label="{{ __('Close the detail') }}">
                    <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor"
                         stroke-width="2" stroke-linecap="round" aria-hidden="true">
                        <path d="M6 6l12 12M18 6L6 18" />
                    </svg>
                </button>
            </div>

            {{-- selected system: one server-rendered block per organ, so the
                 panel can carry sparklines, facts and the HR-zone band without
                 pushing all of it through Alpine state --}}
            @foreach ($systems as $sysKey => $sys)
                <div x-show="sel === '{{ $sysKey }}'" x-cloak class="mt-3 space-y-3">
                    <div class="flex items-center justify-between gap-2.5">
                        @if (isset($organs[$sysKey]))
                            <span class="organ" aria-hidden="true" style="color: var(--status-{{ $sys['status'] }}-ink)">
                                <svg viewBox="0 0 24 24" width="34" height="34" fill="none" stroke="currentColor"
                                     stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round">{!! $organs[$sysKey] !!}</svg>
                            </span>
                        @endif
                        {{-- The sheet head already names what is open, and it stays
                             put while the detail scrolls, so the heading would only
                             repeat itself two lines further down. --}}
                        <h3 class="flex-1 text-lg font-bold tracking-tight" x-show="!narrow">{{ $sys['label'] }}</h3>
                        <span class="pill" data-status="{{ $sys['status'] }}">{{ $statusLabels[$sys['status']] }}</span>
                    </div>

                    <p class="stat-value">{{ $sys['value'] }}</p>

                    @if (! empty($sys['spark']) && count($sys['spark']) >= 2)
                        <div>
                            <x-spark :points="$sys['spark']" :width="230" :height="46" class="w-full max-w-[230px]" />
                            <p class="mt-1 text-xs text-muted">{{ $sys['sparkLabel'] ?? '' }}</p>
                        </div>
                    @endif

                    @if (! empty($sys['facts']))
                        <dl class="space-y-1.5 border-t border-hairline pt-2.5 text-sm num">
                            @foreach ($sys['facts'] as $fact)
                                <div class="flex justify-between gap-3">
                                    <dt class="text-secondary">{{ $fact['label'] }}</dt>
                                    <dd class="text-right font-semibold">{{ $fact['value'] }}</dd>
                                </div>
                            @endforeach
                        </dl>
                    @endif

                    {{-- Help belongs in the product: when a sensor is off, the
                         panel says which one and where to enable it, instead of
                         a dash nobody can act on. --}}
                    @if (($sys['help'] ?? null) !== null)
                        <p class="detail-note">{{ $sys['help'] }}</p>
                    @endif

                    @if (($sys['zones'] ?? null) !== null)
                        @php
                            $zn = $sys['zones'];
                            $zoneSpan = max(1, $zn['max'] - $zn['floors'][0]);
                        @endphp
                        <div>
                            <p class="card-title">{{ __('HR zones') }}</p>
                            <div class="zonebar mt-2" role="img"
                                 aria-label="{{ __('Heart rate zones from :from to :to bpm', ['from' => $zn['floors'][0], 'to' => $zn['max']]) }}">
                                @for ($i = 0; $i < 5; $i++)
                                    @php
                                        $from = $zn['floors'][$i];
                                        $to = $i < 4 ? $zn['floors'][$i + 1] : $zn['max'];
                                    @endphp
                                    <div class="zonebar-seg" title="Zone {{ $i + 1 }}: {{ $from }}–{{ $to }} bpm"
                                         style="width: {{ round(($to - $from) / $zoneSpan * 100, 1) }}%; background: var(--load-{{ [1, 3, 5, 7, 9][$i] }})">
                                        <span>Z{{ $i + 1 }}</span>
                                    </div>
                                @endfor
                            </div>
                            <div class="mt-1 flex justify-between text-xs text-muted num">
                                <span>{{ $zn['floors'][0] }}</span><span>{{ $zn['max'] }} bpm</span>
                            </div>
                            @if ($zn['lthr'] !== null)
                                <p class="stat-ref mt-1">{{ __('Lactate threshold at :bpm bpm.', ['bpm' => $zn['lthr']]) }}</p>
                            @endif
                        </div>
                    @endif

                    <div class="reco">
                        <p class="reco-head">{{ __('Recommendation') }}</p>
                        <p class="mt-1.5 text-[0.95rem] leading-relaxed">{{ $sys['recommendation'] }}</p>
                    </div>
                    <button type="button" class="bm-back" x-on:click="sel = null">← {{ __('All findings') }}</button>
                </div>
            @endforeach

            {{-- selected muscle zone --}}
            <div x-show="selZone && !selSystem" x-cloak class="mt-3 space-y-3">
                <h3 class="text-lg font-bold tracking-tight" x-show="!narrow" x-text="selZone?.label"></h3>

                <template x-if="selZone && selZone.hasData">
                    <div class="space-y-3">
                        <div>
                            {{-- The band leads, the number follows it in a
                                 smaller size. A decayed estimate calibrated
                                 against one athlete's 90 days cannot carry the
                                 precision two digits imply, and reading the
                                 band first is also how the decision gets made. --}}
                            <p class="stat-value" x-text="selZone?.bandLabel"></p>
                            <div class="bm-bar">
                                <div class="bm-bar-fill" x-bind:style="selZone && '--bm-bar-v:' + selZone.freshness + '%'"></div>
                            </div>
                            <p class="stat-ref mt-1">
                                <span class="num" x-text="selZone?.freshness"></span>{{ __('% fresh, from the decay model') }}
                            </p>
                        </div>

                        {{-- The projection, when there is one worth naming.
                             Explicitly a projection: the next session moves it. --}}
                        <template x-if="selZone?.recoversAt">
                            <p class="bm-forecast">
                                {{ __('Back above 90 % around') }}
                                <strong x-text="fmtForecast(selZone.recoversAt)"></strong>
                                <span class="block text-xs text-muted">{{ __('Projection off the decay curve; any session before then moves it.') }}</span>
                            </p>
                        </template>

                        <dl class="space-y-1.5 text-sm num">
                            <div class="flex justify-between gap-3">
                                <dt class="text-secondary">{{ __('Last loaded') }}</dt>
                                <dd class="font-semibold">
                                    <span x-text="fmtDate(selZone?.lastTrained)"></span>
                                    <span class="font-normal text-muted" x-text="selZone ? '· ' + relDays(selZone.daysSince) : ''"></span>
                                </dd>
                            </div>
                            @foreach ($windows as $span)
                            <div class="flex justify-between gap-3">
                                <dt class="text-secondary">{{ __('Sets, :days days', ['days' => $span]) }}</dt>
                                <dd class="font-semibold">
                                    <span x-text="selZone ? selZone.windows[{{ $span }}].sets.toLocaleString(locale) : '–'"></span>
                                    @if ($span === 7)
                                        <span class="font-normal text-muted">{{ __('of :low–:high', $corridor) }}</span>
                                    @endif
                                </dd>
                            </div>
                            @endforeach
                            <div class="flex justify-between gap-3">
                                <dt class="text-secondary">{{ __('Load, 7 days') }}</dt>
                                <dd class="font-semibold"><span x-text="selZone ? Math.round(selZone.windows[7].volume).toLocaleString(locale) : '–'"></span> {{ __('pts') }}</dd>
                            </div>
                        </dl>

                        {{-- How much of this zone's reading was measured and
                             how much was spread from a whole activity. Without
                             it an estimated zone looks exactly like a measured
                             one, which is the single largest way this map
                             could mislead. --}}
                        <template x-if="selZone?.windows[7].measuredShare !== null">
                            <p class="bm-quality" x-bind:data-quality="selZone.windows[7].measuredShare >= 50 ? 'measured' : 'estimated'">
                                <span x-show="selZone.windows[7].measuredShare >= 50">
                                    {{ __('Mostly measured:') }}
                                    <span class="num" x-text="selZone.windows[7].measuredShare"></span>{{ __('% of this week\'s load came from tracked strength sets.') }}
                                </span>
                                <span x-show="selZone.windows[7].measuredShare < 50">
                                    {{ __('Mostly estimated:') }}
                                    {{ __('only') }} <span class="num" x-text="selZone.windows[7].measuredShare"></span>{{ __('% of this week\'s load came from tracked sets, the rest is spread from whole activities.') }}
                                </span>
                            </p>
                        </template>

                        {{-- Attribution: which sessions put the load here.
                             This is what lets a mapping error be caught, and
                             it is visible nowhere else. --}}
                        <template x-if="selZone?.windows[7].sessions.length">
                            <div>
                                <p class="card-title">{{ __('What loaded it') }}</p>
                                <ul class="bm-sessions mt-1.5">
                                    <template x-for="s of selZone.windows[7].sessions" :key="s.label + s.date">
                                        <li>
                                            <span class="bm-session-name" x-text="s.label"></span>
                                            <span class="text-muted" x-text="fmtDate(s.date)"></span>
                                            <span class="num font-semibold" x-text="s.share + ' %'"></span>
                                        </li>
                                    </template>
                                </ul>
                            </div>
                        </template>

                        {{-- A reported complaint sits with the zone it was
                             reported on, and the load context stays purely
                             descriptive: this is a hypothesis generator, not
                             a finding, and n = 1 carries no causality. --}}
                        <template x-if="selZone?.symptom">
                            <p class="bm-symptom-note">
                                <strong x-text="selZone.symptom.symptom"></strong>
                                <span x-text="'· ' + fmtDate(selZone.symptom.date)"></span>
                                <span class="block text-xs text-muted">{{ __('Reported by you. Shown next to the load, not mixed into it.') }}</span>
                            </p>
                        </template>

                        <div class="reco">
                            <p class="reco-head">{{ __('Recommendation') }}</p>
                            <p class="mt-1.5 text-[0.95rem] leading-relaxed" x-text="selZone?.advice"></p>
                        </div>
                        <p class="text-xs text-muted">{{ __('Sets count fractionally: an exercise counts for a zone as much as it loads it. Runs and circuit sessions carry no sets, they come in through the training load.') }}</p>
                    </div>
                </template>

                <template x-if="selZone && !selZone.hasData">
                    <div class="space-y-3">
                        <p class="stat-value text-muted">{{ __('no data') }}</p>
                        <p class="text-sm leading-relaxed text-secondary">
                            {{ __('Garmin has not reported a single load point for this zone. It only fills from strength sets with a properly picked exercise; circuit sessions and runs never reach it. That is why the hatching means “unknown”, not “recovered”.') }}
                        </p>
                        <p class="text-sm leading-relaxed text-secondary">
                            {{ __('As soon as you track a strength exercise with a matching exercise choice, the zone fills on the next sync.') }}
                        </p>
                    </div>
                </template>

                <button type="button" class="bm-back" x-on:click="sel = null">← {{ __('All findings') }}</button>
            </div>
        </div>
    </div>

    {{-- ------------------------------------ zone ranking + load scale --}}
    {{-- The list stays put while a detail is open: it is the navigation
         between zones, not a state of the panel, and a list that vanishes
         on the tap that used it is a list you cannot walk. Where the
         detail opens depends on the room: beside the map on a wide
         screen, in the sheet over the lower edge on a phone. --}}
    <div class="card lg:col-span-2" id="bm-zones">
        <x-card-head :name="__('Muscle zones')"
                     :desc="__('Heaviest load first · tap a zone for its detail')" />

        <div class="bm-chip-grid mt-3">
            @foreach ($ranked as $z)
                @php $zd = $zones[$z]; @endphp
                <button type="button" class="bm-chip"
                        @if ($zd['flagged']) data-flagged="true" @endif
                        aria-label="{{ __(':zone: :band, :percent % fresh', ['zone' => $zd['label'], 'band' => $zd['bandLabel'], 'percent' => $zd['freshness']]) }}"
                        x-bind:aria-pressed="sel === '{{ $z }}' ? 'true' : 'false'"
                        x-bind:class="{ 'bm-chip-lit': lit === '{{ $z }}' }"
                        x-on:pointerenter="lit = '{{ $z }}'" x-on:pointerleave="lit = null"
                        x-on:focus="lit = '{{ $z }}'" x-on:blur="lit = null"
                        x-on:click="toggle('{{ $z }}', $event.currentTarget)">
                    <span class="bm-chip-swatch" aria-hidden="true" x-bind:style="'background: ' + fillOf('{{ $z }}')"></span>
                    <span class="bm-chip-name">{{ $zd['label'] }}</span>
                    @if ($zd['symptom'])
                        <span class="bm-chip-sym" aria-hidden="true" title="{{ $zd['symptom']['symptom'] }}">!</span>
                    @endif
                    <span class="bm-chip-val num">{{ $zd['freshness'] }}&nbsp;%</span>
                </button>
            @endforeach
        </div>

        {{-- Zones without history sit below the ranking, not inside it: a
             rank orders one quantity, and "unknown" is not a smaller value
             of it. Sorted last they still read as the tail of the list. --}}
        @if ($unknown !== [])
            <p class="mt-3.5 text-xs text-muted">{{ __('Without any data') }}</p>
            <div class="bm-chip-grid mt-1.5">
                @foreach ($unknown as $z)
                    @php $zd = $zones[$z]; @endphp
                    <button type="button" class="bm-chip bm-chip-nodata"
                            aria-label="{{ __(':zone: no set data', ['zone' => $zd['label']]) }}"
                            x-bind:aria-pressed="sel === '{{ $z }}' ? 'true' : 'false'"
                            x-bind:class="{ 'bm-chip-lit': lit === '{{ $z }}' }"
                            x-on:pointerenter="lit = '{{ $z }}'" x-on:pointerleave="lit = null"
                            x-on:click="toggle('{{ $z }}', $event.currentTarget)">
                        <span class="bm-chip-swatch legend-nodata" aria-hidden="true"></span>
                        <span class="bm-chip-name">{{ $zd['label'] }}</span>
                        <span class="bm-chip-val num">–</span>
                    </button>
                @endforeach
            </div>
        @endif

        {{-- Load scale. A ramp this fine cannot be read as classes, so it
             gets ticks instead of swatches: labelled ends and even steps in
             between, in the same unit the zones and chips report. Strip
             left, caveats right: at full page width one line of ticks
             stretched over 1.100 px would read as a chart, not a key. --}}
        <div class="bm-scale mt-4 border-t border-hairline pt-3.5">
            @php
                // Tick positions follow the gradient, not the label count:
                // the strip spends its first eleventh on the neutral step
                // (load below 5 %) and the rest on the ramp, so evenly
                // spaced labels would sit up to three points off the colour
                // they name. The ramp is not linear in load either, so the
                // position comes from the same curve the fills bucket by
                // rather than from a second formula that could drift. The
                // labels bunching to the right is the point: it shows that
                // most of the strip covers the load range you actually
                // reach, and that the loud end is a long way off.
                $tickAt = function (int $freshness): float {
                    $load = 100 - $freshness;
                    $neutral = 100 / 11;

                    return round($load < 5
                        ? $load / 5 * $neutral
                        : $neutral + \App\View\Components\BodyMap::rampCurve($load) * (100 - $neutral), 2);
                };
                $ticks = [100, 75, 50, 25, 0];
            @endphp
            <div>
                <p class="card-title">{{ __('Muscle load scale') }}</p>
                <div class="scale-strip mt-2"></div>
                <div class="scale-ticks mt-1" aria-hidden="true" x-show="lens === 'freshness'">
                    @foreach ($ticks as $i => $tick)
                        <span class="num" style="left: {{ $tickAt($tick) }}%
                            @if ($i === 0) ; transform: none @elseif ($i === count($ticks) - 1) ; transform: translateX(-100%) @endif">{{ $tick }}</span>
                    @endforeach
                </div>
                <div class="mt-0.5 flex justify-between text-xs text-secondary">
                    <span x-text="lens === 'freshness' ? @js(__('fresh')) : @js(__('untouched'))"></span>
                    <span x-text="lens === 'freshness' ? @js(__('heavily loaded')) : @js(__('your loudest zone'))"></span>
                </div>
            </div>
            <div>
                <p class="stat-ref" x-show="lens === 'freshness'">
                    {{ __('Values in % freshness. The right end is your highest daily load of the last 90 days, the same scale for every zone so they stay comparable with each other. Load decays per zone: arms in about 22 h, legs in about 38 h.') }}
                </p>
                <p class="stat-ref" x-show="lens !== 'freshness'" x-cloak>
                    {{ __('Accumulated load in the chosen window, without any decay. The right end is your own loudest zone in that window, so this lens shows where your training went, not how much is left in the tank.') }}
                </p>
                <div class="mt-2.5 flex flex-wrap items-center gap-x-4 gap-y-1.5 text-xs text-secondary">
                    <span class="flex items-center gap-2">
                        <span class="legend-swatch legend-nodata" aria-hidden="true"></span>
                        <span>{{ __('hatched = no set data') }}</span>
                    </span>
                    <span class="flex items-center gap-2">
                        <span class="legend-swatch legend-flagged" aria-hidden="true"></span>
                        <span>{{ __('outlined = barely recovered') }}</span>
                    </span>
                    <span class="flex items-center gap-2">
                        <span class="legend-swatch legend-symptom" aria-hidden="true"></span>
                        <span>{{ __('marked = symptom reported') }}</span>
                    </span>
                </div>
            </div>
        </div>
    </div>
</div>
