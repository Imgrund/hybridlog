@props(['note' => null])

{{-- What the athlete's own history says about heat, next to the metric it
     explains rather than on a card of its own.

     Three rules hold this line honest, and all three live in
     ChartBundle::weatherInsight(): it appears only above the
     minimum window (14 nights, 10 sessions), it names how many nights or
     sessions each half was read over, and it says what went together
     rather than what caused what. A warm week is usually also a busy
     week, and a median split cannot tell the two apart. --}}
@if ($note)
    <p class="wx-note">
        <span class="wx-note-mark" aria-hidden="true"></span>
        {{ $note['line'] }}
        <span class="wx-note-caveat">{{ $note['caveat'] }}</span>
    </p>
@endif
