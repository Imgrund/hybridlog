        {{-- ================================================ body map --}}
        <section class="mt-4" id="panel-koerperkarte" role="tabpanel" aria-labelledby="tab-koerperkarte"
                 tabindex="0" x-show="active === 'koerperkarte'" x-cloak>
            <p class="panel-lede">{{ __('Five findings with a concrete recommendation, plus the muscle load from training load and strength sets, calibrated against your last 90 days. Hatched zones have no data behind them.') }}</p>
            <p class="panel-note mt-1">{{ __('Colour depth = accumulated muscle load, decaying per zone (arms fastest, legs slowest)') }}</p>
            <div class="mt-4">
                <x-body-map :freshness="$freshness" :systems="$systems"
                            :volume-ceiling="$volumeCeiling" :symptoms="$symptomZones"
                            :beat="$heartbeat" />
            </div>
        </section>
