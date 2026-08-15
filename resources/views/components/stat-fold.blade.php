{{-- The methodology of a card behind one word. The honesty principle
     stays served by the panel note and the confidence words in sight;
     what folds here is the derivation, not the caveat. Folding keeps
     the text in the DOM, so screen readers and tests reach it. --}}
<details {{ $attributes->merge(['class' => 'fold stat-fold']) }}>
    <summary>{{ __('Derivation') }}</summary>
    <div class="stat-ref">{{ $slot }}</div>
</details>
