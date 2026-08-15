@use('App\Garmin\NumberFormat')
        {{-- =============================================== training --}}
        <section class="mt-4" id="panel-belastung" role="tabpanel" aria-labelledby="tab-belastung"
                 tabindex="0" x-show="active === 'belastung'" x-cloak>
            <p class="panel-note">{{ __('Load for strength and HIIT is systematically underestimated without a chest strap') }}</p>
            {{-- The load ratio as the answer this route exists to give: the
                 measured ratio taped to the word the corridor puts on it,
                 the three standing figures of the load model as the evidence
                 rail, and the two loads the ratio is literally made of as
                 the one aside. The ratio is read off the latest training
                 status, so the plate stands outside the range switch, like
                 the night does on Recovery.

                 The instrument is the gauge and not the ring the sleep
                 plate uses: this scale runs 0 to 2 with a corridor in the
                 middle, and a ring would promise a share of a hundred. --}}
            @if ($acwr['value'] !== null)
                @php
                    // Fitness, fatigue and form, from the model's own KPI
                    // set. Their last value is the same in every window the
                    // switch offers, because the ranges only trim the start.
                    // The rail says the same thing whichever one is on.
                    $loadFacts = $kpi['pmc'];
                    $loadParts = $acwr['acute'] !== null && $acwr['chronic'] !== null
                        ? [
                            ['label' => __('Acute, 7 days'), 'value' => $acwr['acute']],
                            ['label' => __('Chronic, weekly Ø'), 'value' => $acwr['chronic']],
                        ]
                        : [];
                    $loadPartMax = max(array_merge(array_column($loadParts, 'value'), [1.0]));
                @endphp
                <figure class="tape-lead tape-lead-gauge mt-3">
                    <div class="tape-lead-main">
                        <div class="ring-col">
                            <x-load-gauge :value="$acwr['value']" :stamp="$acwrStamp" />
                        </div>
                        <figcaption class="min-w-0">
                            <p class="tape-lead-eyebrow">{{ __('Load ratio') }}</p>
                            {{-- The corridor's own word for the value, at the
                                 size the answer of a route deserves. Written
                                 out, so no reader has to decode the needle's
                                 ink to learn where the ratio stands. --}}
                            <p class="tape-headline">{{ $acwrWord }}</p>
                            <p class="tape-lead-meta">{{ __('Acute load against the chronic base on the fixed 0–2 scale') }}</p>
                            <p class="tape-lead-note">
                                {{ $acwr['source'] === 'garmin'
                                    ? __('Garmin’s own ratio of acute to chronic training load.')
                                    : __('Computed from daily load: the last 7 days against the weekly average of 28.') }}
                                {{ __('The corridor is a guideline, not a validated risk score.') }}
                            </p>
                        </figcaption>
                    </div>
                    <div class="tape-rail" style="--tape-rail-cols: {{ count($loadFacts) }}">
                        @foreach ($loadFacts as $fact)
                            <div class="tape-rail-cell">
                                <p class="tape-rail-label">{{ $fact['label'] }}</p>
                                <p class="tape-rail-value" @if ($fact['value'] === '–') data-empty @endif>{{ $fact['value'] }}@isset($fact['unit'])<span class="stat-unit">{{ $fact['unit'] }}</span>@endisset</p>
                            </div>
                        @endforeach
                    </div>
                    @if ($loadParts)
                        <div class="tape-lead-aside">
                            <p class="tape-lead-eyebrow">{{ __('Composition') }}</p>
                            {{-- Both bars share the larger of the two figures
                                 as their scale, so the pair draws the same
                                 ratio the gauge points at. --}}
                            <dl class="load-split">
                                @foreach ($loadParts as $part)
                                    <div class="load-split-row">
                                        <dt>{{ $part['label'] }}</dt>
                                        <dd class="tnum">{{ NumberFormat::format($part['value'], 0) }}</dd>
                                        <span class="cell-bar" aria-hidden="true"><i style="width: {{ round($part['value'] / $loadPartMax * 100) }}%"></i></span>
                                    </div>
                                @endforeach
                            </dl>
                        </div>
                    @endif
                </figure>
            @endif
            <div class="card-grid mt-3 grid gap-3 lg:grid-cols-2">
                <figure class="card">
                    <x-card-head as="figcaption" :name="__('Fitness, fatigue, form')"
                                 :desc="$meta['desc']['pmc']" data-range-desc="pmc" />
                    {{-- Deliberately no overlay control here: the card already
                         carries three series and both data colours (ATL owns
                         --series-2), so a fourth series on a second axis would
                         break the One-Accent Rule and the readability the
                         PMC answer depends on. Its natural pairings live on
                         the other cards (ATL over HRV, CTL over VO2max). --}}
                    <div class="chart-box"><canvas id="chart-pmc" role="img" aria-label="{{ __('Fitness, fatigue and form over time') }}"></canvas></div>
                    <x-kpi-row :items="$kpi['pmc']" data-kpi="pmc" />
                </figure>
                <figure class="card">
                    <x-card-head as="figcaption" :name="__('HRV and normal band')"
                                 :desc="$meta['desc']['hrv']" data-range-desc="hrv" />
                    {{-- Curated pairs. Fatigue (ATL): acute load is the
                         coach's first suspect behind a suppressed HRV; ATL
                         peaks against HRV dips separate training-driven
                         suppression from illness or life stress. Resting HR:
                         the second autonomic marker; HRV down while resting
                         HR rises is the systemic red flag, HRV down alone
                         the milder finding. Both are daily series sampled at
                         the HRV dates, no resampling. --}}
                    <x-chart-overlay chart="chart-hrv" :name="__('HRV and normal band')"
                                     :options="['atl' => __('Fatigue'), 'rhr' => __('Resting HR')]" />
                    <div class="chart-box"><canvas id="chart-hrv" role="img" aria-label="{{ __('HRV over time with baseline band') }}"></canvas></div>
                    <x-kpi-row :items="$kpi['hrv']" data-kpi="hrv" />
                    {{-- On the card that carries both figures, because the
                         question this answers is asked while looking at
                         them: can yesterday's heat account for this? --}}
                    <x-weather-note :note="$weatherInsight['mornings'] ?? null" />
                </figure>
                <figure class="card">
                    <x-card-head as="figcaption" :name="__('Strength load per week')"
                                 :desc="$meta['desc']['strengthLoad']" data-range-desc="strengthLoad" />
                    {{-- Curated pair. Intensity minutes: the hybrid athlete's
                         programming question is interference: do heavy
                         strength weeks crowd out the aerobic engine work or
                         ride on top of it? Both series are weekly ISO
                         buckets joined on the week key, no resampling. --}}
                    <x-chart-overlay chart="chart-strength-load" :name="__('Strength load per week')"
                                     :options="['intensity' => __('Intensity')]" />
                    <div class="chart-box"><canvas id="chart-strength-load" role="img" aria-label="{{ __('Weekly strength training load') }}"></canvas></div>
                    <x-kpi-row :items="$kpi['strengthLoad']" data-kpi="strengthLoad" />
                    {{-- The bars aggregate per week; this layer lists the
                         sessions each bar is made of. Fixed to the current and
                         the three previous ISO weeks, independent of the range. --}}
                    @if ($details['strengthSessions'])
                        <x-card-expand id="strength-sessions" :label="__('Sessions')" :title="__('Sessions behind the weekly bars')">
                            <div class="detail-block">
                                <table class="detail-table">
                                    <thead>
                                        <tr><th scope="col">{{ __('Session') }}</th><th scope="col">{{ __('Duration') }}</th><th scope="col">{{ __('Load') }}</th><th scope="col">{{ __('Avg HR') }}</th></tr>
                                    </thead>
                                    @foreach ($details['strengthSessions'] as $week)
                                        <tbody>
                                            <tr><th class="detail-group" scope="rowgroup" colspan="4">{{ $week['label'] }} · Load {{ $week['sum'] }}</th></tr>
                                            @foreach ($week['sessions'] as $session)
                                                <tr>
                                                    <td>{{ $session['date'] }} · {{ $session['type'] }}</td>
                                                    <td>{{ $session['duration'] }}</td>
                                                    <td>{{ $session['load'] }}</td>
                                                    <td>{{ $session['hr'] }}</td>
                                                </tr>
                                            @endforeach
                                        </tbody>
                                    @endforeach
                                </table>
                                <p class="detail-note">{{ __('Load per session as reported by Garmin · average HR in bpm · underestimated without a chest strap') }}</p>
                            </div>
                        </x-card-expand>
                    @endif
                </figure>
                {{-- The progression behind the load bars next door: what was
                     actually lifted, per exercise category. Only present
                     while the mirror holds set recordings at all; an empty
                     display window words itself on the canvas instead. --}}
                @if ($strengthSetsTotal > 0)
                <figure class="card">
                    <x-card-head as="figcaption" :name="__('Strength progression')"
                                 :desc="$meta['desc']['strengthProgress']" data-range-desc="strengthProgress" />
                    <div class="chart-box"><canvas id="chart-strength-progress" role="img" aria-label="{{ __('Weekly reps by exercise category') }}"></canvas></div>
                    @include('partials.strength-progress', ['progress' => $strengthProgression])
                    {{-- The card's own boundary, stated where the numbers are:
                         set recordings exist only for workouts the watch
                         tracked set by set, and in this mirror that is a
                         subset of the strength work that happened. --}}
                    <p class="stat-ref">{{ __('Only workouts the watch recorded set by set count here; a circuit session without set data is missing.') }}</p>
                </figure>
                @endif
                <figure class="card">
                    <x-card-head as="figcaption" :name="__('Intensity minutes per week')"
                                 :desc="$meta['desc']['intensity']" data-range-desc="intensity" />
                    {{-- Curated pair. Strength load: the same interference
                         question as next door, read from the engine side.
                         whichever card he is on, the balance of strength
                         versus aerobic weeks is one glance away. Weekly ISO
                         buckets joined on the week key, no resampling. --}}
                    <x-chart-overlay chart="chart-intensity" :name="__('Intensity minutes per week')"
                                     :options="['strengthLoad' => __('Strength load')]" />
                    <div class="chart-box"><canvas id="chart-intensity" role="img" aria-label="{{ __('Weekly intensity minutes') }}"></canvas></div>
                    <x-kpi-row :items="$kpi['intensity']" data-kpi="intensity" />
                    {{-- The chart plots the WHO-weighted sum; this layer shows
                         the moderate versus vigorous mix that the doubling
                         hides. Same fixed four-week window as the sessions. --}}
                    @if ($details['intensitySplit'])
                        <x-card-expand id="intensity-split" :label="__('Breakdown')" :title="__('Moderate and vigorous minutes per week')">
                            <div class="detail-block">
                                <table class="detail-table">
                                    <thead>
                                        <tr><th scope="col">{{ __('Week') }}</th><th scope="col">{{ __('Moderate') }}</th><th scope="col">{{ __('Vigorous') }}</th><th scope="col">{{ __('Counted') }}</th></tr>
                                    </thead>
                                    <tbody>
                                        @foreach ($details['intensitySplit'] as $week)
                                            <tr>
                                                <td>{{ $week['label'] }}</td>
                                                <td>{{ $week['moderate'] }}</td>
                                                <td>{{ $week['vigorous'] }}</td>
                                                <td>{{ $week['weighted'] }}</td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                                <p class="detail-note">{{ __('Minutes per week · counted = moderate plus vigorous minutes at double weight, the WHO count, which is what the chart plots too.') }}</p>
                            </div>
                        </x-card-expand>
                    @endif
                </figure>
                <figure class="card">
                    <x-card-head as="figcaption" :name="__('Stimulus profile per session')"
                                 :desc="$meta['desc']['trainingEffect']" data-range-desc="trainingEffect" />
                    <div class="chart-box chart-box-tall"><canvas id="chart-training-effect" role="img" aria-label="{{ __('Aerobic versus anaerobic training effect per session') }}"></canvas></div>
                    <x-kpi-row :items="$kpi['trainingEffect']" data-kpi="trainingEffect" />
                    {{-- Heat belongs on this card and not on the load one:
                         the question here is what a session cost, and in
                         warmth the same work costs more heart rate. --}}
                    <x-weather-note :note="$weatherInsight['sessions'] ?? null" />
                    {{-- Above the history, because a session that has not
                         happened yet is the only one still worth moving. --}}
                    <x-weather-note :note="$weatherInsight['outlook'] ?? null" />
                    {{-- The scale is named on the card rather than in a
                         tooltip: a reader who has to hover to learn that 3
                         means "improving" reads the plot as a score, and
                         top right as the good corner. It is not one.
                         Firstbeat recommends two to three easy sessions per
                         hard one, so the lower left is where most of a
                         well-built week is supposed to land. --}}
                    <p class="stat-ref">
                        {{ __('Firstbeat steps on both axes: 1 minor, 3 improving, 5 overreaching. Top right is not “better”: one hard session belongs with two or three easy ones.') }}
                    </p>
                    <x-stat-fold>
                        {{ __('Below 1 the session had no effect, 2 is maintaining, 4 highly improving. The value is an estimate from peak EPOC relative to the stored fitness, not a measurement. Without pace or power data the anaerobic value comes out systematically too low for circuit work, and combo sessions carry values assembled from their parts. Dot area shows how many sessions sit on the same coordinate.') }}
                    </x-stat-fold>
                </figure>
            </div>
        </section>
