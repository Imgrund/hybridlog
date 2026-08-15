{{-- Per-category progression rows under the stacked reps chart: rendered
     here for the page and re-rendered by charts() on a range switch,
     where the [data-kpi] hook lets the standard KPI swap replace it.
     Weighted categories answer in kilograms, weightless ones in reps,
     never both in one breath; the stagnation reading is a written
     observation on its own line, not a colour and not an instruction. --}}
@use('App\Garmin\NumberFormat')
<div class="mt-3" data-kpi="strengthProgress">
    @if ($progress['rows'])
        <ul class="space-y-2">
            @foreach ($progress['rows'] as $row)
                <li class="text-sm">
                    <div class="flex flex-wrap items-baseline gap-x-3 gap-y-1">
                        <span class="font-semibold">{{ $row['label'] }}</span>
                        <span class="ml-auto text-muted tnum whitespace-nowrap">
                            @if ($row['weighted'])
                                {{ __('top :kg kg', ['kg' => NumberFormat::upTo($row['currentTopKg'], 1)]) }}
                                {{-- The heaviest set only earns its place while it beats the
                                     current top; repeating the number next door says nothing. --}}
                                @if ($row['bestSetKg'] !== null && $row['bestSetKg'] > $row['currentTopKg'])
                                    · {{ __('heaviest set :kg kg', ['kg' => NumberFormat::upTo($row['bestSetKg'], 1)]) }}
                                @endif
                            @else
                                @if ($row['lastFullWeekReps'] !== null)
                                    {{ __(':count reps last full week', ['count' => NumberFormat::format($row['lastFullWeekReps'])]) }}
                                    ·
                                @endif
                                {{ __('best week :count', ['count' => NumberFormat::format($row['bestWeekReps'])]) }}
                            @endif
                        </span>
                    </div>
                    @if ($row['stagnation'])
                        <p class="text-xs text-muted">{{ __('Top weight steady at :kg kg for :weeks weeks', ['kg' => NumberFormat::upTo($row['stagnation']['kg'], 1), 'weeks' => $row['stagnation']['weeks']]) }}</p>
                    @endif
                </li>
            @endforeach
        </ul>
        @if (collect($progress['rows'])->contains('unclassified', true))
            <p class="mt-2 text-xs text-muted">{{ __('The watch cannot classify most circuit movements; they land in Unclassified.') }}</p>
        @endif
        @unless ($progress['anyWeight'])
            <p class="mt-2 text-xs text-muted">{{ __('No recorded set carries a weight in this window, so progression is counted in reps.') }}</p>
        @endunless
    @endif
</div>
