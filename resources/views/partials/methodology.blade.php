        {{-- ============================================= methodology --}}
        <footer class="methodology mt-8 mb-8">
            {{-- What this footer may still say is what no card says: the
                 source, and where the references come from. The three
                 limits it used to list are each written on the card that
                 shows the figure (sleep stages on last night, the chest
                 strap on the training panel, the corridor on the load
                 gauge), and a footer repeating them taught the reader to
                 look for limits at the bottom instead of at the number. --}}
            <p><b>{{ __('Method and limits.') }}</b> {{ __('Unofficial Garmin Connect data, stored locally. Where a figure has a limit, it is named on the card that shows it.') }}
            {{ __('All references are personal baselines, not population norms.') }}</p>
            {{-- The two derivations that belong to a panel rather than to
                 the page: they stay in full, one word away. --}}
            <x-stat-fold>
                {{ __('The body map only knows load that Garmin delivers: runs and circuit sessions it spreads across stored activity profiles, single muscles it reaches only through strength sets with a recorded exercise category. Zones without any data behind them are hatched and read “unknown”, not “recovered”.') }}
                <span class="fold-para">{{ __('Load decays exponentially, and each zone carries its own half-life: about 20 hours for the forearms, 38 for the large leg muscles. Those numbers are fixed rather than fitted to you. The decay constants of a fitness-fatigue model cannot be identified from one athlete’s training history, so a curve fitted to your data would look precise and mean nothing.') }}</span>
                {{-- Not a nicety: the solid is an adaptation of BodyParts3D,
                     whose licence asks for this credit wherever the model
                     travels, and it travels to every browser that opens the
                     3D view. Details and what the licence binds:
                     resources/models/CREDITS.md. --}}
                <span class="fold-para">{{ __('The three-dimensional figure is an écorché: its zones are real muscle bellies. Model: BodyParts3D, Copyright© The Database Center for Life Science, licensed by CC Attribution-Share Alike 2.1 Japan.') }}</span>
                <span class="fold-para">{{ __('The illness early warning compares resting heart rate (from +5 bpm), nightly respiration rate (from +2 breaths) and HRV (below the normal band or at least 10 % under the weekly mean) against the median of the 30 days before the last two; it only fires once a second marker deviates alongside the resting heart rate, and it is a pattern hint, not a diagnosis.') }}</span>
            </x-stat-fold>
        </footer>
