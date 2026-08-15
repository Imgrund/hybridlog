{{-- Inline sparkline for a stat tile: history drawn in the de-emphasis
     tone, only the newest point in the data accent, so the tile gains a
     direction without competing with the big number beside it. Renders
     nothing below three points: a sparse mirror must not fake a trend, and
     two points always draw a straight segment that reads as one. --}}
@props(['points' => [], 'width' => 76, 'height' => 30])

@php
    $points = array_values(array_filter($points, fn ($v) => $v !== null));
    $n = count($points);

    if ($n >= 3) {
        $min = min($points);
        $max = max($points);
        // Right padding leaves room for the end dot and its surface ring,
        // vertical padding keeps peaks from clipping at the viewBox edge.
        $padL = 2.0;
        $padR = 7.0;
        $padY = 6.5;
        $coords = [];
        foreach ($points as $i => $v) {
            $x = $padL + ($width - $padL - $padR) * $i / ($n - 1);
            $y = $max === $min
                ? $height / 2
                : $padY + ($height - 2 * $padY) * (1 - ($v - $min) / ($max - $min));
            $coords[] = round($x, 1).','.round($y, 1);
        }
        [$dotX, $dotY] = explode(',', end($coords));
    }
@endphp

@if ($n >= 3)
    <svg {{ $attributes->class('spark') }} viewBox="0 0 {{ $width }} {{ $height }}" width="{{ $width }}" height="{{ $height }}"
         role="img" aria-label="{{ __('Course of the last :count values', ['count' => $n]) }}">
        {{-- pathLength normalises the drawn length to 1, so the draw-in
             animation in app.css uses one dash length for every spark on
             the page, whatever its width or how many points it plots. --}}
        <polyline points="{{ implode(' ', $coords) }}" pathLength="1" />
        <circle cx="{{ $dotX }}" cy="{{ $dotY }}" r="3.5" />
    </svg>
@endif
