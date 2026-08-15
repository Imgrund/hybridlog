        {{-- =============================================== area tabs --}}
        {{-- Every panel stays in the DOM (x-show, never x-if) because the
             charts are built once on DOMContentLoaded; app.js re-measures
             them on dashboard:tab-shown. --}}
            <div class="tabbar">
                <div class="tablist" role="tablist" aria-label="{{ __('Areas') }}" x-ref="tablist"
                     x-on:keydown.arrow-right.prevent="move(1)"
                     x-on:keydown.arrow-left.prevent="move(-1)"
                     x-on:keydown.home.prevent="moveTo(0)"
                     x-on:keydown.end.prevent="moveTo(tabs.length - 1)">
                    @foreach ($tabs as $tab)
                        <button type="button" class="tab" role="tab"
                                id="tab-{{ $tab['id'] }}"
                                aria-controls="panel-{{ $tab['id'] }}"
                                :aria-selected="active === '{{ $tab['id'] }}' ? 'true' : 'false'"
                                :tabindex="active === '{{ $tab['id'] }}' ? 0 : -1"
                                x-on:click="select('{{ $tab['id'] }}')">
                            {{ $tab['label'] }}
                        </button>
                    @endforeach
                </div>

                {{-- Global range switch: one window for every series below
                     the answer zone. Same segmented vocabulary as the tabs;
                     radiogroup semantics because exactly one range is on.
                     It goes invisible on panels it cannot reach, so no
                     control on this page is ever dead. --}}
                <div class="range-switch" x-data="rangeSwitch({{ \Illuminate\Support\Js::from($payload['rangeOptions']) }}, {{ $payload['range'] }}, {{ $payload['rangeLimit'] }})">
                    <span class="range-label" id="range-switch-label">{{ __('Days') }}</span>
                    <div class="range-track" role="radiogroup" aria-labelledby="range-switch-label"
                         x-on:keydown.arrow-right.prevent="move(1)"
                         x-on:keydown.arrow-left.prevent="move(-1)"
                         x-on:keydown.home.prevent="moveToEdge(false)"
                         x-on:keydown.end.prevent="moveToEdge(true)">
                        {{-- A stage the mirror cannot fill is shown and disabled, never
                             dropped: it is short of history, not wrong, and it comes back
                             by itself once enough days have accumulated. Dropping it would
                             take it away for good, including the year from now when it
                             would finally have something to say. The reason rides on the
                             label rather than a tooltip, so it survives a keyboard and a
                             screen reader. --}}
                        @foreach ($payload['rangeOptions'] as $r)
                            @php $reachable = $r <= $payload['rangeLimit']; @endphp
                            <button type="button" class="range-seg" role="radio"
                                    id="range-{{ $r }}"
                                    @disabled(! $reachable)
                                    aria-label="{{ $reachable
                                        ? __(':days days', ['days' => $r])
                                        : __(':days days, not available yet: the mirror holds :have days', ['days' => $r, 'have' => $payload['rangeLimit']]) }}"
                                    :aria-checked="active === {{ $r }} ? 'true' : 'false'"
                                    :tabindex="active === {{ $r }} ? 0 : -1"
                                    x-on:click="select({{ $r }})">{{ $r }}</button>
                        @endforeach
                    </div>
                    <span class="range-error" role="status" x-show="error" x-cloak>{{ __('The range could not be loaded.') }}</span>
                </div>
            </div>
