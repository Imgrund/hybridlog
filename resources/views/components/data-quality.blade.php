{{-- One line for how complete the data under this page is: watch sync,
     last fetch, today's tracking, and a session the burn suggests but no
     activity records.

     Always visible, because a strip that only appears when something is
     wrong teaches nobody where to look, and its absence then means "no
     news" and "not rendered" at once. It states facts in the same register
     whether or not they are gaps; only a real gap earns the marker, and
     even that is a dot rather than a colour. The detail folds, so the
     normal state costs one line.

     Nothing here notifies. This is a line one reads before trusting a
     number, not an alert that interrupts a day. --}}
@php
    $flags = array_values(array_filter($quality['flags'], fn (?array $f): bool => $f !== null));
    $details = array_values(array_filter($flags, fn (array $f): bool => $f['detail'] !== null));
@endphp

@if ($flags !== [])
    <div class="dq">
        @if ($details === [])
            <p class="dq-strip">
                <span class="dq-label">{{ __('Data basis') }}</span>
                @foreach ($flags as $flag)
                    <span class="dq-item" @if ($flag['gap']) data-gap="true" @endif>{{ $flag['label'] }}</span>
                @endforeach
            </p>
        @else
            <details class="dq-fold">
                <summary class="dq-strip">
                    <span class="dq-label">{{ __('Data basis') }}</span>
                    @foreach ($flags as $flag)
                        <span class="dq-item" @if ($flag['gap']) data-gap="true" @endif>{{ $flag['label'] }}</span>
                    @endforeach
                    <span class="dq-more">{{ $quality['gaps'] > 0
                        ? trans_choice('{1}:count gap|[2,*]:count gaps', $quality['gaps'], ['count' => $quality['gaps']])
                        : __('details') }}</span>
                </summary>
                <ul class="dq-details" role="list">
                    @foreach ($details as $flag)
                        <li><b>{{ $flag['label'] }}</b> · {{ $flag['detail'] }}</li>
                    @endforeach
                </ul>
            </details>
        @endif
    </div>
@endif
