{{-- Disclosure that draws a card open into its one-layer detail. The
     toggle names its payoff ("Show contributors"), the panel
     answers the one question the card raises but cannot answer. Closed
     panels are visibility-hidden via CSS, so they leave the tab order
     and the accessibility tree without a display toggle that would
     break the height animation. Escape closes only the innermost open
     detail (see cardExpand in app.js). --}}
@props(['id', 'label', 'title'])

<div x-data="cardExpand" x-on:keydown.escape="onEscape($event)" {{ $attributes->class('card-expand') }}>
    <button type="button" class="expand-toggle" x-ref="toggle"
            aria-expanded="false"
            :aria-expanded="open ? 'true' : 'false'"
            aria-controls="detail-{{ $id }}"
            x-on:click="toggle()">
        <svg class="expand-chevron" viewBox="0 0 24 24" width="12" height="12" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg>
        <span x-text="open ? @js(__('Hide :label', ['label' => $label])) : @js(__('Show :label', ['label' => $label]))">{{ __('Show :label', ['label' => $label]) }}</span>
    </button>
    <div class="card-detail" :class="{ 'is-open': open }" id="detail-{{ $id }}" role="region" aria-label="{{ $title }}">
        <div class="card-detail-inner">{{ $slot }}</div>
    </div>
</div>
