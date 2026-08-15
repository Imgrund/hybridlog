{{-- Footer KPIs of a card: label, value, optional unit and tone. The tone
     names the chart series the number belongs to, so the dot colour ties
     the tile back to the line or bar it summarises. --}}
@props(['items' => []])

@php
    $toneVars = [
        'blue' => 'var(--series-1)',
        'orange' => 'var(--series-2)',
        'neg' => 'var(--diverge-neg)',
        'good' => 'var(--status-good-ink)',
        'warning' => 'var(--status-warning-ink)',
        'serious' => 'var(--status-serious-ink)',
        'critical' => 'var(--status-critical-ink)',
    ];
@endphp

@if ($items)
    <dl {{ $attributes->class('kpi-row') }}>
        @foreach ($items as $item)
            @php $tone = $toneVars[$item['tone'] ?? ''] ?? null; @endphp
            <div class="kpi">
                <dt class="kpi-label">
                    <span class="kpi-dot" @if ($tone) style="background: {{ $tone }}" @endif></span>
                    <span>{{ $item['label'] }}</span>
                </dt>
                <dd class="kpi-value">{{ $item['value'] }}@isset($item['unit'])<span class="kpi-unit">{{ $item['unit'] }}</span>@endisset</dd>
            </div>
        @endforeach
    </dl>
@endif
