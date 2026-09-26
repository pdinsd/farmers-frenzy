@php
    $percent = fn (float $fraction, int $decimals = 2): string => number_format($fraction * 100, $decimals).'%';
    $oneIn = fn (float $probability): string => $probability > 0 ? '1 in '.number_format(1 / $probability, $probability > 0.01 ? 1 : 0) : 'never';
    $money = fn (int|float $cents): string => '$'.number_format($cents / 100, 2);
    $denominationLabel = fn (int $cents): string => $cents < 100 ? $cents.'¢' : '$'.number_format($cents / 100, $cents % 100 === 0 ? 0 : 2);
    $symbolNames = [...$theme['names'], 'farmer' => $theme['names']['farmer'].' (wild)', 'moon' => $theme['names']['moon'].' (scatter)'];
    $sourceNames = ['base_lines' => 'Base game lines', 'base_scatter' => 'Base game scatters', 'free_games' => 'Free games', 'bale_bonus' => 'Bale Bonus'];
    $eventNames = [
        'winning_spins' => 'Winning spins', 'free_games_triggers' => 'Free games', 'bale_bonus_triggers' => 'Bale Bonus',
        'any_bonus' => 'Any bonus', 'door_reveals' => $theme['names']['door'].' reveals', 'door_bale_reveals' => $theme['plurals']['door'].' → '.strtolower($theme['plurals']['bale']),
        'mini' => 'Mini', 'minor' => 'Minor', 'major' => 'Major', 'grand' => 'Grand',
    ];
    $profiles = array_values(array_filter(array_keys($sheet['bale_values']), fn (string $table): bool => str_starts_with($table, 'profile_')));
    $tierLabels = collect($profiles)
        ->flatMap(fn (string $profile): array => array_column($sheet['bale_values'][$profile]['rows'], 'label'))
        ->unique()
        ->sortBy(fn (string $label): float => is_numeric($multiple = strstr($label, 'x', true)) ? (float) $multiple : 1e6 + strlen($label))
        ->values();
@endphp

<div class="par" data-par-root>
    <header class="par__header">
        <div>
            <h2 class="par__title">PAR Sheet</h2>
            <p class="par__subtitle">{{ $theme['title'] }} · Hay Link · 5×3 reels</p>
        </div>
        <div class="par__program">
            <span>Program</span>
            <strong>{{ $sheet['rtp_program'] }}%</strong>
        </div>
    </header>

    <section class="par__card" data-par-live>
        <div class="par__live-head">
            <h3 class="par__heading">Live performance</h3>
            <fieldset class="par__live-toggle">
                <legend>Real-time updates</legend>
                <label><input type="radio" name="par_live_updates" value="off" checked data-live-toggle> Off</label>
                <label><input type="radio" name="par_live_updates" value="on" data-live-toggle> On</label>
            </fieldset>
        </div>
        <div data-par-live-body>
            @include('machine.par-live')
        </div>
    </section>

    <section class="par__card">
        <h3 class="par__heading">Simulated performance</h3>
        <form class="par__simulate" data-par-simulate>
            <label>Spins
                <select name="spins">
                    @foreach ([50_000, 100_000, 200_000, $maxSimulationSpins] as $spins)
                        <option value="{{ $spins }}" @selected($spins === 200_000)>{{ number_format($spins) }}</option>
                    @endforeach
                </select>
            </label>
            <label>Denom
                <select name="denomination">
                    @foreach ($sheet['denominations'] as $row)
                        <option value="{{ $row['denomination'] }}">{{ $denominationLabel($row['denomination']) }}</option>
                    @endforeach
                </select>
            </label>
            <label>Bet
                <select name="credits_per_line">
                    @foreach ($sheet['credits_per_line_options'] as $option)
                        <option value="{{ $option }}">{{ $option }}/line</option>
                    @endforeach
                </select>
            </label>
            <button type="submit" class="ap__button ap__button--primary">Run</button>
        </form>
        <p class="par__note" data-par-status hidden></p>
        <p class="par__note">For the calibrated figure use <code>php artisan hay-link:simulate 4000000</code>; a web run is limited to {{ number_format($maxSimulationSpins) }} spins.</p>

        @if ($simulation)
            @unless ($simulationIsCurrent)
                <p class="par__warning">These results are from different settings. Run the simulation again.</p>
            @endunless

            <div class="par__stats">
                <div class="par__stat par__stat--primary">
                    <span>Measured RTP</span>
                    <strong>{{ number_format($simulation['rtp'], 2) }}%</strong>
                    <small>± {{ number_format($simulation['rtp_confidence'], 2) }} (95%)</small>
                </div>
                <div class="par__stat">
                    <span>Excl. Major &amp; Grand</span>
                    <strong>{{ number_format($simulation['rtp_excluding_progressives'], 2) }}%</strong>
                </div>
                <div class="par__stat">
                    <span>Hit frequency</span>
                    <strong>{{ number_format($simulation['hit_frequency'], 2) }}%</strong>
                </div>
                <div class="par__stat">
                    <span>Volatility index</span>
                    <strong>{{ number_format($simulation['volatility_index'], 1) }}</strong>
                    <small>SD {{ number_format($simulation['standard_deviation'], 1) }}</small>
                </div>
            </div>
            <p class="par__note">
                {{ number_format($simulation['spins']) }} spins at {{ $denominationLabel($simulation['denomination']) }},
                {{ $simulation['lines'] }} lines, {{ $simulation['total_bet'] }} credits bet, program {{ $simulation['rtp_program'] }}%.
                Progressive increment: {{ number_format($simulation['progressive_contribution'], 2) }}% of coin in (paid back through the Major and Grand).
                Run {{ \Illuminate\Support\Carbon::parse($simulation['ran_at'])->diffForHumans() }}.
            </p>

            <table class="par__table">
                <thead><tr><th>Return by source</th><th>RTP</th></tr></thead>
                <tbody>
                    @foreach ($simulation['rtp_by_source'] as $source => $rtp)
                        <tr><td>{{ $sourceNames[$source] ?? $source }}</td><td>{{ number_format($rtp, 2) }}%</td></tr>
                    @endforeach
                </tbody>
                <tfoot><tr><td>Total</td><td>{{ number_format($simulation['rtp'], 2) }}%</td></tr></tfoot>
            </table>

            <table class="par__table">
                <thead><tr><th>Event</th><th>Count</th><th>Frequency</th></tr></thead>
                <tbody>
                    @foreach ($simulation['counts'] as $event => $count)
                        <tr>
                            <td>{{ $eventNames[$event] ?? $event }}</td>
                            <td>{{ number_format($count) }}</td>
                            <td>{{ $count > 0 ? '1 in '.number_format($simulation['spins'] / $count, 1) : '—' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>

            @php($maxBucket = max(1, ...array_column($simulation['win_distribution'], 'count')))
            <table class="par__table">
                <thead><tr><th>Win per spin</th><th>Spins</th><th>Share</th></tr></thead>
                <tbody>
                    @foreach ($simulation['win_distribution'] as $bucket)
                        <tr>
                            <td>{{ $bucket['label'] }}</td>
                            <td>{{ number_format($bucket['count']) }}</td>
                            <td class="par__bar-cell">
                                <span class="par__bar" style="width: {{ max(1, round($bucket['count'] / $maxBucket * 100)) }}%"></span>
                                {{ $percent($bucket['count'] / $simulation['spins']) }}
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
            <p class="par__note">Largest single game: {{ number_format($simulation['biggest_win_multiple'], 1) }}× the bet.</p>
        @else
            <p class="par__note">No simulation yet. Unlock the attendant panel and press Run.</p>
        @endif
    </section>

    <section class="par__card">
        <h3 class="par__heading">Feature frequencies (exact)</h3>
        <table class="par__table">
            <thead><tr><th>Per spin</th><th>Base game</th><th>Free game</th></tr></thead>
            <tbody>
                <tr><td>Free games ({{ $sheet['free_games']['trigger_count'] }}+ {{ $theme['plurals']['moon'] }})</td><td>{{ $oneIn($sheet['triggers']['base']['free_games']) }}</td><td>{{ $oneIn($sheet['triggers']['free']['free_games']) }}</td></tr>
                <tr><td>Bale Bonus ({{ $sheet['bale_bonus']['trigger_count'] }}+ {{ strtolower($theme['plurals']['bale']) }})</td><td>{{ $oneIn($sheet['triggers']['base']['bale_bonus']) }}</td><td>{{ $oneIn($sheet['triggers']['free']['bale_bonus']) }}</td></tr>
                <tr><td>Any bonus</td><td>{{ $oneIn($sheet['triggers']['base']['any_bonus']) }}</td><td>{{ $oneIn($sheet['triggers']['free']['any_bonus']) }}</td></tr>
                <tr><td>Doors in view</td><td>—</td><td>{{ $percent($sheet['triggers']['free']['doors'], 1) }}</td></tr>
            </tbody>
        </table>
        <dl class="par__facts">
            <dt>Bonus split (base game)</dt>
            <dd>{{ $percent($sheet['triggers']['base']['free_games'] / $sheet['triggers']['base']['any_bonus'], 0) }} free games · {{ $percent(1 - $sheet['triggers']['base']['free_games'] / $sheet['triggers']['base']['any_bonus'], 0) }} Bale Bonus</dd>
            <dt>Bonus within 80 spins</dt>
            <dd>{{ $percent(1 - (1 - $sheet['triggers']['base']['any_bonus']) ** 80, 1) }}</dd>
            <dt>Free games awarded</dt>
            <dd>{{ $sheet['free_games']['awarded'] }} (retrigger +{{ $sheet['free_games']['retrigger_awarded'] }}), {{ number_format($sheet['free_games_feature']['average_spins'], 2) }} spins on average</dd>
            <dt>Bale Bonus during a free games feature</dt>
            <dd>{{ $percent($sheet['free_games_feature']['bale_bonus'], 1) }}</dd>
            <dt>Instant full screen (Grand) per free spin</dt>
            <dd>{{ $oneIn($sheet['triggers']['free']['full_screen']) }}</dd>
        </dl>
    </section>

    <section class="par__card">
        <h3 class="par__heading">Bale Bonus</h3>
        <table class="par__table">
            <thead><tr><th>Bale #</th>@foreach ($sheet['bale_bonus']['landing_chances'] as $nth => $chance)<th>{{ $nth }}</th>@endforeach</tr></thead>
            <tbody><tr><td>Lands per respin</td>@foreach ($sheet['bale_bonus']['landing_chances'] as $chance)<td>{{ $percent($chance, $chance < 0.01 ? 2 : 0) }}</td>@endforeach</tr></tbody>
        </table>
        <table class="par__table">
            <thead><tr><th>Starting bales</th><th>Average finish</th><th>Grand</th></tr></thead>
            <tbody>
                @foreach ($sheet['bale_bonus_outcomes'] as $row)
                    <tr><td>{{ $row['start'] }}</td><td>{{ number_format($row['average_bales'], 2) }}</td><td>{{ $oneIn($row['grand']) }}</td></tr>
                @endforeach
            </tbody>
        </table>
        <p class="par__note">{{ $sheet['bale_bonus']['respins'] }} respins, reset by every new bale. Only one {{ $sheet['bale_bonus']['capped_min_multiplier'] }}×+ bale or Major per feature. Filling all 15 {{ $sheet['bale_bonus']['grand_replaces_values'] ? 'pays the Grand in place of the bale values' : 'pays the Grand on top of the bale values' }}.</p>

        <h4 class="par__subheading">Value tiers</h4>
        <div class="par__scroll">
            <table class="par__table par__table--compact">
                <thead>
                    <tr><th>Value</th>@foreach ($profiles as $profile)<th>T{{ substr($profile, 8) }}</th>@endforeach</tr>
                </thead>
                <tbody>
                    @foreach ($tierLabels as $label)
                        <tr>
                            <td>{{ $label }}</td>
                            @foreach ($profiles as $profile)
                                @php($chance = collect($sheet['bale_values'][$profile]['rows'])->firstWhere('label', $label)['min_bet_chance'] ?? 0)
                                <td>{{ $chance > 0 ? $percent($chance, $chance < 0.01 ? 2 : 1) : '—' }}</td>
                            @endforeach
                        </tr>
                    @endforeach
                </tbody>
                <tfoot>
                    <tr><td>Average</td>@foreach ($profiles as $profile)<td>{{ number_format($sheet['bale_values'][$profile]['min_bet_expected_multiple'], 2) }}×</td>@endforeach</tr>
                    <tr><td>Chance (low trigger)</td>@foreach ($sheet['value_profile_selection']['cold'] as $chance)<td>{{ $percent($chance, 0) }}</td>@endforeach</tr>
                    <tr><td>Chance (high trigger)</td>@foreach ($sheet['value_profile_selection']['hot'] as $chance)<td>{{ $percent($chance, 0) }}</td>@endforeach</tr>
                </tfoot>
            </table>
        </div>
        <p class="par__note">One tier is chosen when the feature starts; richer triggering bales slide the odds towards the high tiers. Averages exclude the Major (paid from the progressive).</p>
    </section>

    <section class="par__card">
        <h3 class="par__heading">Reel bale values</h3>
        <table class="par__table">
            <thead><tr><th>Value</th><th>Weight</th><th>Min bet</th><th>Max bet</th></tr></thead>
            <tbody>
                @foreach ($sheet['bale_values']['base']['rows'] as $row)
                    <tr><td>{{ $row['label'] }}</td><td>{{ $row['weight'] }}</td><td>{{ $percent($row['min_bet_chance']) }}</td><td>{{ $percent($row['max_bet_chance']) }}</td></tr>
                @endforeach
            </tbody>
            <tfoot><tr><td>Average</td><td></td><td>{{ number_format($sheet['bale_values']['base']['min_bet_expected_multiple'], 2) }}×</td><td>{{ number_format($sheet['bale_values']['base']['max_bet_expected_multiple'], 2) }}×</td></tr></tfoot>
        </table>
        <p class="par__note">Jackpot bales land {{ number_format(max($sheet['jackpot_bet_scaling']), 2) }}× as often at the maximum bet.</p>
    </section>

    <section class="par__card">
        <h3 class="par__heading">Jackpots</h3>
        <table class="par__table">
            <thead><tr><th>Tier</th><th>Value</th><th>Increment</th></tr></thead>
            <tbody>
                <tr><td>Grand</td><td>Resets to {{ $money($sheet['jackpots']['grand']['seed']) }}</td><td>{{ $percent($sheet['jackpots']['grand']['contribution']) }}</td></tr>
                <tr><td>Major</td><td>Resets to {{ $money($sheet['jackpots']['major']['seed']) }}, caps at {{ $money($sheet['jackpots']['major']['cap']) }}</td><td>{{ $percent($sheet['jackpots']['major']['contribution']) }}</td></tr>
                <tr><td>Minor</td><td>{{ $sheet['jackpots']['minor']['bet_multiplier'] }}× bet</td><td>—</td></tr>
                <tr><td>Mini</td><td>{{ $sheet['jackpots']['mini']['bet_multiplier'] }}× bet</td><td>—</td></tr>
            </tbody>
        </table>

        <h4 class="par__subheading">Major: chance a Bale Bonus contains it</h4>
        <table class="par__table par__table--compact">
            <thead>
                <tr><th>Denom</th><th>Min bet at {{ $money($sheet['jackpots']['major']['seed']) }}</th><th>at {{ $money($sheet['jackpots']['major']['cap']) }}</th><th>Max bet at {{ $money($sheet['jackpots']['major']['seed']) }}</th><th>at {{ $money($sheet['jackpots']['major']['cap']) }}</th></tr>
            </thead>
            <tbody>
                @foreach ($sheet['major_chances'] as $row)
                    <tr>
                        <td>{{ $denominationLabel($row['denomination']) }}</td>
                        <td>{{ $percent($row['min_bet_seed']) }}</td>
                        <td>{{ $percent($row['min_bet_cap']) }}</td>
                        <td>{{ $percent($row['max_bet_seed']) }}</td>
                        <td>{{ $percent($row['max_bet_cap']) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
        <p class="par__note">The chance is proportional to the bet in dollars and rises as the Major climbs from its reset value to its cap, so the Major is the same share of RTP at every bet and denomination.</p>
    </section>

    <section class="par__card">
        <h3 class="par__heading">Bet level balancing</h3>
        <table class="par__table">
            <thead><tr><th>Bet</th><th>Bale Bonus with a Mini</th><th>Minis per Bale Bonus</th><th>Credit values</th></tr></thead>
            <tbody>
                @foreach ($sheet['minis'] as $row)
                    <tr>
                        <td>{{ $row['credits_per_line'] }}/line</td>
                        <td>{{ $percent($row['chance'], 1) }}</td>
                        <td>{{ number_format($row['expected'], 2) }}</td>
                        <td>{{ $percent($row['credit_scale'], 0) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
        <p class="par__note">Minis (up to {{ $sheet['bale_bonus']['minis']['max'] }}) are decided when Bale Bonus starts. Higher bets get more Minis, Minors and Majors; credit bale values are scaled down there so every bet returns the program's RTP.</p>
    </section>

    <section class="par__card">
        <h3 class="par__heading">Denominations</h3>
        <table class="par__table">
            <thead><tr><th>Denom</th><th>Lines</th><th>Min bet</th><th>Max bet</th></tr></thead>
            <tbody>
                @foreach ($sheet['denominations'] as $row)
                    <tr><td>{{ $denominationLabel($row['denomination']) }}</td><td>{{ $row['lines'] }}</td><td>{{ $money($row['min_bet_cents']) }}</td><td>{{ $money($row['max_bet_cents']) }}</td></tr>
                @endforeach
            </tbody>
        </table>
    </section>

    <section class="par__card">
        <h3 class="par__heading">Pays</h3>
        <table class="par__table">
            <thead><tr><th>Symbol</th><th>3</th><th>4</th><th>5</th></tr></thead>
            <tbody>
                @foreach ($sheet['paytable'] as $symbol => $pays)
                    <tr><td>{{ $symbolNames[$symbol] ?? $symbol }}</td><td>{{ $pays[3] }}</td><td>{{ $pays[4] }}</td><td>{{ $pays[5] }}</td></tr>
                @endforeach
                <tr><td>{{ $theme['names']['moon'] }} scatter (× bet)</td><td>{{ $sheet['scatter_pays'][3] }}</td><td>{{ $sheet['scatter_pays'][4] }}</td><td>{{ $sheet['scatter_pays'][5] }}</td></tr>
            </tbody>
        </table>
        <p class="par__note">Line pays are multiples of the line bet; wins pay left to right. The {{ $theme['names']['farmer'] }} wild substitutes for all but {{ $theme['plurals']['moon'] }} and {{ strtolower($theme['plurals']['bale']) }}.</p>

        <h4 class="par__subheading">Door reveals (free games)</h4>
        <table class="par__table par__table--compact">
            <tbody>
                @foreach ($sheet['door_reveals'] as $symbol => $chance)
                    <tr><td>{{ $symbolNames[$symbol] ?? $symbol }}</td><td>{{ $percent($chance, 1) }}</td></tr>
                @endforeach
            </tbody>
        </table>
    </section>

    @foreach (['base' => 'Base game reel strips', 'free' => 'Free games reel strips'] as $mode => $title)
        <section class="par__card">
            <h3 class="par__heading">{{ $title }}</h3>
            <table class="par__table par__table--compact">
                <thead><tr><th>Symbol</th>@foreach ($sheet['strips'][$mode]['lengths'] as $reel => $length)<th>R{{ $reel + 1 }}</th>@endforeach</tr></thead>
                <tbody>
                    @foreach ($sheet['strips'][$mode]['symbols'] as $symbol => $counts)
                        <tr><td>{{ $symbolNames[$symbol] ?? $symbol }}</td>@foreach ($counts as $count)<td>{{ $count ?: '—' }}</td>@endforeach</tr>
                    @endforeach
                </tbody>
                <tfoot><tr><td>Stops</td>@foreach ($sheet['strips'][$mode]['lengths'] as $length)<td>{{ $length }}</td>@endforeach</tr></tfoot>
            </table>
        </section>
    @endforeach
</div>
