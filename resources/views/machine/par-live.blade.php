@php
    $percentOf = fn (int|float $part, int|float $whole): string => $whole > 0 ? number_format($part / $whole * 100, 2).'%' : '—';
    $oneIn = fn (int|float $count, int|float $games): string => $count > 0 ? '1 in '.number_format($games / $count, 1) : '—';
    $exactOneIn = fn (float $probability): string => $probability > 0 ? '1 in '.number_format(1 / $probability, 1) : '—';
    $money = fn (int|float $cents): string => '$'.number_format($cents / 100, 2);
    $games = $meters->games_played;
    $actualRtp = $meters->actualRtp();
    $currentSimulation = $simulationIsCurrent ? $simulation : null;
    $standardDeviation = $currentSimulation['standard_deviation'] ?? null;
    $band = $games > 0 && $standardDeviation !== null ? 1.96 * $standardDeviation / sqrt($games) * 100 : null;
    $target = (float) $sheet['rtp_program'];
    $sources = [
        'base_lines' => ['Base game lines', $meters->line_wins_cents],
        'base_scatter' => ['Base game scatters', $meters->scatter_wins_cents],
        'free_games' => ['Free games', $meters->free_games_wins_cents],
        'bale_bonus' => ['Bale Bonus', $meters->bale_bonus_wins_cents],
    ];
@endphp

<div class="par__stats">
    <div class="par__stat">
        <span>Games played</span>
        <strong>{{ number_format($games) }}</strong>
    </div>
    <div class="par__stat par__stat--primary">
        <span>Actual RTP</span>
        <strong>{{ $actualRtp === null ? '—' : number_format($actualRtp, 2).'%' }}</strong>
        <small>
            Program {{ $target }}%@if ($band !== null) · expected {{ number_format(max(0, $target - $band), 1) }}–{{ number_format($target + $band, 1) }}% @endif
        </small>
    </div>
    <div class="par__stat">
        <span>Coin in</span>
        <strong>{{ $money($meters->coin_in_cents) }}</strong>
    </div>
    <div class="par__stat">
        <span>Coin out</span>
        <strong>{{ $money($meters->coin_out_cents) }}</strong>
    </div>
</div>

@if ($games > 0 && $band === null)
    <p class="par__note">Run a simulation to see the range the actual RTP should fall in after {{ number_format($games) }} games.</p>
@elseif ($games > 0)
    <p class="par__note">95% of machines at this program would be inside that range after {{ number_format($games) }} games; it narrows as more games are played.</p>
@endif

<table class="par__table">
    <thead><tr><th>Measure</th><th>Actual</th><th>Expected</th></tr></thead>
    <tbody>
        <tr>
            <td>Hit frequency</td>
            <td>{{ $percentOf($meters->winning_games, $games) }}</td>
            <td>{{ $currentSimulation ? number_format($currentSimulation['hit_frequency'], 2).'%' : '—' }}</td>
        </tr>
        <tr>
            <td>Free games</td>
            <td>{{ $oneIn($meters->free_games_triggered, $games) }}</td>
            <td>{{ $exactOneIn($sheet['triggers']['base']['free_games']) }}</td>
        </tr>
        <tr>
            <td>Bale Bonus</td>
            <td>{{ $oneIn($meters->bale_bonus_triggered, $games) }}</td>
            <td>{{ $exactOneIn($sheet['triggers']['base']['bale_bonus']) }}</td>
        </tr>
        <tr>
            <td>Any bonus</td>
            <td>{{ $oneIn($meters->free_games_triggered + $meters->bale_bonus_triggered, $games) }}</td>
            <td>{{ $exactOneIn($sheet['triggers']['base']['any_bonus']) }}</td>
        </tr>
    </tbody>
</table>

<table class="par__table">
    <thead><tr><th>Return by source</th><th>Actual</th><th>Simulated</th></tr></thead>
    <tbody>
        @foreach ($sources as $key => [$label, $cents])
            <tr>
                <td>{{ $label }}</td>
                <td>{{ $percentOf($cents, $meters->coin_in_cents) }}</td>
                <td>{{ $currentSimulation ? number_format($currentSimulation['rtp_by_source'][$key], 2).'%' : '—' }}</td>
            </tr>
        @endforeach
    </tbody>
    <tfoot>
        <tr>
            <td>Total</td>
            <td>{{ $percentOf($meters->coin_out_cents, $meters->coin_in_cents) }}</td>
            <td>{{ $currentSimulation ? number_format($currentSimulation['rtp'], 2).'%' : '—' }}</td>
        </tr>
    </tfoot>
</table>

<dl class="par__facts">
    <dt>Jackpots hit (Mini / Minor / Major / Grand)</dt>
    <dd>{{ number_format($meters->mini_hits) }} / {{ number_format($meters->minor_hits) }} / {{ number_format($meters->major_hits) }} / {{ number_format($meters->grand_hits) }}</dd>
    <dt>Progressives paid (included above)</dt>
    <dd>{{ $money($meters->progressives_paid_cents) }}</dd>
    <dt>Since</dt>
    <dd>{{ ($meters->cleared_at ?? $meters->created_at)?->toDayDateTimeString() ?? '—' }}</dd>
    <dt>Updated</dt>
    <dd>{{ now()->format('g:i:s A') }}</dd>
</dl>
<p class="par__note">Counts every title on this machine. Free games and Bale Bonus wins include any jackpots won in them.</p>
