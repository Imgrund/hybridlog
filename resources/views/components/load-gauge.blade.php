{{-- The instrument only. The verdict word, the two loads the ratio is made
     of and the source note used to hang off the bottom of this component,
     which was right while it was one card among many. On the answer plate
     those three are the plate's own parts (headline, aside and method
     note), so the component draws the scale and says nothing else. --}}
@props(['value', 'stamp'])

@php
    use App\Garmin\NumberFormat;

    // The scale is fixed at 0-2 like the corridor meter in the answer
    // zone: the two must agree on where a value sits, or the small one
    // reads as wrong the moment this one is open. Values above 2 pin to
    // the end of the arc; the printed number still says what they are.
    $clamped = min(2.0, max(0.0, $value));

    // 45 ticks across the half circle. The corridor's ticks are drawn
    // heavier instead of as a band of their own, so the guardrail stays
    // part of the scale rather than becoming a second needle. Endpoint
    // ticks belong to the corridor only when they sit inside it, which
    // 0.8 and 1.3 of 0-2 do exactly at ticks 18 and 29.25, and the loop
    // works in scale values, not indices, to keep that readable.
    $ticks = collect(range(0, 44))->map(function (int $i): array {
        $scale = $i / 44 * 2;
        $angle = deg2rad(180 - ($scale / 2 * 180));

        return [
            'corridor' => $scale >= 0.8 && $scale <= 1.3,
            'x1' => 100 + cos($angle) * 78,
            'y1' => 100 - sin($angle) * 78,
            'x2' => 100 + cos($angle) * 88,
            'y2' => 100 - sin($angle) * 88,
        ];
    });

    $needle = deg2rad(180 - ($clamped / 2 * 180));
    $ink = [
        'good' => 'var(--status-good-ink)',
        'warning' => 'var(--status-warning-ink)',
        'critical' => 'var(--status-critical-ink)',
        'neutral' => 'var(--text-muted)',
    ][$stamp];
@endphp

<div class="gauge" role="img"
     aria-label="{{ __('Load ratio :value on a scale from 0 to 2, corridor 0.8 to 1.3', ['value' => NumberFormat::format($value, 1)]) }}">
    <svg viewBox="0 0 200 104" aria-hidden="true">
        @foreach ($ticks as $tick)
            <line class="{{ $tick['corridor'] ? 'gauge-tick-corridor' : 'gauge-tick' }}"
                  x1="{{ round($tick['x1'], 2) }}" y1="{{ round($tick['y1'], 2) }}"
                  x2="{{ round($tick['x2'], 2) }}" y2="{{ round($tick['y2'], 2) }}" />
        @endforeach
        {{-- The needle stops short of the tick ring and carries the value's
             own status ink, exactly like the meter dot in the answer zone. --}}
        <line class="gauge-needle" style="stroke: {{ $ink }}"
              x1="{{ round(100 + cos($needle) * 46, 2) }}" y1="{{ round(100 - sin($needle) * 46, 2) }}"
              x2="{{ round(100 + cos($needle) * 70, 2) }}" y2="{{ round(100 - sin($needle) * 70, 2) }}" />
        <circle class="gauge-hub" style="fill: {{ $ink }}"
                cx="{{ round(100 + cos($needle) * 70, 2) }}" cy="{{ round(100 - sin($needle) * 70, 2) }}" r="3.4" />
    </svg>
    {{-- Only the figure sits inside the arc; the scale's name goes below the
         drawing, where a needle at either end of the scale (0 and 2 are
         horizontal) can never run through it. --}}
    <div class="gauge-read">
        <p class="stat-value" data-status="{{ $stamp }}">{{ NumberFormat::format($value, 1) }}</p>
    </div>
</div>
{{-- The corridor stays with the drawing that marks it: the heavier ticks
     are the only place it is shown, so the reader is told what they are
     where they are. --}}
<p class="gauge-word">{{ __('corridor 0.8–1.3') }}</p>
