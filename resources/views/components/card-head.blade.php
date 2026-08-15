{{-- Card header: what the card is, then what it shows. The descriptor
     carries the qualifier (window, method, unit) that used to sit inline
     behind the title, where it read as an afterthought. --}}
@props(['name', 'desc' => null, 'as' => 'div'])

@php $tag = in_array($as, ['div', 'figcaption'], true) ? $as : 'div'; @endphp

<{{ $tag }} {{ $attributes->class('card-head') }}>
    <div class="min-w-0">
        <p class="card-name">{{ $name }}</p>
        @if ($desc)
            <p class="card-desc">{{ $desc }}</p>
        @endif
    </div>
    @isset($aside)
        <div class="card-head-aside">{{ $aside }}</div>
    @endisset
</{{ $tag }}>
