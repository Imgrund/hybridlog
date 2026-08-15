{{-- Second-metric overlay for one chart card: a curated radiogroup in
     the segmented vocabulary of the tabs and the range switch, one
     size down because it is a card-local view control, never a
     headline. Exactly one choice is on ("Off" or one metric); the
     chart layer (app.js) draws the chosen series against a right-hand
     axis in --series-2 and names it in the legend and the axis title,
     so nothing hangs on colour alone. The server always ships the off
     state: the chart looks exactly as it does without the feature
     until a metric is chosen (a remembered choice re-applies
     client-side). The note line words an overlay without drawable
     points in the current window, mirroring the .chart-empty pattern;
     the whole control hides while its own chart has nothing to draw.
     $options maps overlay key => segment label; the keys must exist
     in OVERLAY_SERIES in app.js. --}}
@props(['chart', 'name', 'options'])

@php($overlayOptions = collect($options)->map(fn ($label, $key) => ['key' => $key, 'label' => $label])->values()->all())

<div class="overlay-switch" data-overlay-switch="{{ $chart }}"
     x-data="chartOverlay(@js($chart), @js($overlayOptions))" x-show="hasChart">
    <span class="overlay-label" aria-hidden="true">{{ __('Overlay') }}</span>
    <div class="ov-track" role="radiogroup" aria-label="{{ __('Overlay a second metric: :name', ['name' => $name]) }}"
         x-on:keydown.arrow-right.prevent="move(1)"
         x-on:keydown.arrow-left.prevent="move(-1)"
         x-on:keydown.home.prevent="moveTo(0)"
         x-on:keydown.end.prevent="moveTo(keys().length - 1)">
        <button type="button" class="ov-seg" role="radio"
                id="ov-{{ $chart }}-off"
                aria-checked="true" tabindex="0"
                :aria-checked="active === null ? 'true' : 'false'"
                :tabindex="active === null ? 0 : -1"
                x-on:click="select(null)">{{ __('Off') }}</button>
        @foreach ($options as $key => $label)
            <button type="button" class="ov-seg" role="radio"
                    id="ov-{{ $chart }}-{{ $key }}"
                    aria-checked="false" tabindex="-1"
                    :aria-checked="active === '{{ $key }}' ? 'true' : 'false'"
                    :tabindex="active === '{{ $key }}' ? 0 : -1"
                    x-on:click="select('{{ $key }}')">{{ $label }}</button>
        @endforeach
    </div>
    <span class="overlay-note" role="status" x-show="note" x-text="note" x-cloak></span>
</div>
