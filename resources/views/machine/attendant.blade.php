@php
    $money = fn (int|float $cents): string => '$'.number_format(floor($cents) / 100, 2);
    $denominationLabel = fn (int $cents): string => $cents < 100 ? $cents.'¢' : '$'.number_format($cents / 100, $cents % 100 === 0 ? 0 : 2);
    $oneIn = fn (int $count, int $games): string => $count > 0 ? '1 in '.number_format($games / $count, 1) : '—';
@endphp

<div class="ap" data-attendant-root data-unlocked="{{ $unlocked ? '1' : '0' }}">
    <header class="ap__header">
        <h2 class="ap__title">Attendant</h2>
        @if ($unlocked)
            <span class="ap__badge ap__badge--open">UNLOCKED</span>
            <button type="button" class="ap__button ap__button--small" data-attendant-lock>Lock</button>
        @else
            <span class="ap__badge">LOCKED</span>
        @endif
    </header>

    @unless ($unlocked)
        <form class="ap__card" data-attendant-unlock>
            <p class="ap__hint">Turn the attendant key: enter the PIN to change machine settings.</p>
            <label class="ap__field">
                <span>Attendant PIN</span>
                <input type="password" name="pin" inputmode="numeric" autocomplete="off" maxlength="32" required>
            </label>
            <p class="ap__error" data-attendant-error hidden></p>
            <button type="submit" class="ap__button ap__button--primary">Unlock</button>
        </form>

        <section class="ap__card">
            <h3 class="ap__heading">Machine</h3>
            <dl class="ap__facts">
                <dt>RTP program</dt><dd>{{ $settings['rtp_program'] }}%</dd>
                <dt>Denominations</dt><dd>{{ collect($settings['denominations_enabled'])->sort()->map($denominationLabel)->implode(', ') }}</dd>
                <dt>Games played</dt><dd>{{ number_format($meters->games_played) }}</dd>
            </dl>
        </section>
    @else
        <form class="ap__form" data-attendant-settings novalidate>
            <section class="ap__card">
                <h3 class="ap__heading">RTP program</h3>
                <p class="ap__hint">Changes the reel strips and Bale Bonus odds, never the posted pays. Calibrated at 1¢, minimum bet.</p>
                <div class="ap__segments">
                    @foreach ($programs as $program)
                        <label class="ap__segment">
                            <input type="radio" name="rtp_program" value="{{ $program }}" @checked($settings['rtp_program'] === $program)>
                            <span>{{ $program }}%</span>
                        </label>
                    @endforeach
                </div>
            </section>

            <section class="ap__card">
                <h3 class="ap__heading">Denominations</h3>
                <div class="ap__checks">
                    @foreach ($denominations as $denomination => $lines)
                        <label class="ap__check">
                            <input type="checkbox" name="denominations_enabled[]" value="{{ $denomination }}" @checked(in_array($denomination, $settings['denominations_enabled'], true))>
                            <span><strong>{{ $denominationLabel($denomination) }}</strong> {{ $lines }} lines</span>
                        </label>
                    @endforeach
                </div>
            </section>

            <section class="ap__card">
                <h3 class="ap__heading">Limits</h3>
                <label class="ap__field">
                    <span>Maximum bet</span>
                    <select name="max_credits_per_line">
                        @foreach ($creditsPerLineOptions as $option)
                            <option value="{{ $option }}" @selected($settings['max_credits_per_line'] === $option)>{{ $option }} credit{{ $option > 1 ? 's' : '' }} per line</option>
                        @endforeach
                    </select>
                </label>
                <label class="ap__field">
                    <span>Maximum deposit ($)</span>
                    <input type="number" name="max_deposit_dollars" min="20" max="100000" step="0.01" value="{{ number_format($settings['max_deposit_cents'] / 100, 2, '.', '') }}">
                </label>
                <label class="ap__toggle">
                    <input type="checkbox" name="autoplay_enabled" value="1" @checked($settings['autoplay_enabled'])>
                    <span>Autoplay allowed</span>
                </label>
            </section>

            <section class="ap__card">
                <h3 class="ap__heading">Progressives</h3>
                @foreach (['grand' => 'Grand', 'major' => 'Major'] as $tier => $label)
                    <div class="ap__progressive">
                        <div class="ap__progressive-head">
                            <strong>{{ $label }}</strong>
                            <span class="ap__current" data-progressive-current="{{ $tier }}">{{ $money($progressives[$tier]) }}</span>
                            <button type="button" class="ap__button ap__button--small" data-progressive-reset="{{ $tier }}">Reset</button>
                        </div>
                        <div class="ap__pair">
                            <label class="ap__field">
                                <span>Reset value ($)</span>
                                <input type="number" name="{{ $tier }}_seed_dollars" min="10" step="0.01" value="{{ number_format($settings[$tier.'_seed_cents'] / 100, 2, '.', '') }}">
                            </label>
                            <label class="ap__field">
                                <span>Increment (% of bet)</span>
                                <input type="number" name="{{ $tier }}_contribution_percent" min="0" max="5" step="0.01" value="{{ rtrim(rtrim(number_format($settings[$tier.'_contribution'] * 100, 4, '.', ''), '0'), '.') }}">
                            </label>
                        </div>
                        @if ($tier === 'major')
                            <label class="ap__field">
                                <span>Cap ($): stops growing here, and its hit chance peaks</span>
                                <input type="number" name="major_cap_dollars" min="10" step="0.01" value="{{ number_format($settings['major_cap_cents'] / 100, 2, '.', '') }}">
                            </label>
                        @endif
                    </div>
                @endforeach
                <p class="ap__hint">A new reset value applies the next time the jackpot is won or reset.</p>
            </section>

            <section class="ap__card">
                <h3 class="ap__heading">Features</h3>
                <label class="ap__field">
                    <span>Free games awarded</span>
                    <input type="number" name="free_games_awarded" min="1" max="20" step="1" value="{{ $settings['free_games_awarded'] }}">
                </label>
                <label class="ap__field">
                    <span>Jackpot bale boost at max bet: <output data-boost-output>{{ number_format($settings['jackpot_max_bet_boost'], 1) }}×</output></span>
                    <input type="range" name="jackpot_max_bet_boost" min="1" max="4" step="0.1" value="{{ $settings['jackpot_max_bet_boost'] }}" data-boost-input>
                </label>
                <label class="ap__toggle">
                    <input type="checkbox" name="grand_replaces_values" value="1" @checked($settings['grand_replaces_values'])>
                    <span>Grand replaces bale values</span>
                </label>
                <p class="ap__hint">Free games, the jackpot boost and jackpot settings also move the RTP. Run a simulation in the PAR sheet to see by how much.</p>
            </section>

            <p class="ap__error" data-attendant-error hidden></p>
            <p class="ap__status" data-attendant-status hidden></p>
            <button type="submit" class="ap__button ap__button--primary ap__button--wide">Save settings</button>
        </form>

        <section class="ap__card" data-meters>
            <div class="ap__heading-row">
                <h3 class="ap__heading">Meters</h3>
                <button type="button" class="ap__button ap__button--small ap__button--danger" data-meters-clear>Clear</button>
            </div>
            <dl class="ap__facts">
                <dt>Coin in</dt><dd>{{ $money($meters->coin_in_cents) }}</dd>
                <dt>Coin out</dt><dd>{{ $money($meters->coin_out_cents) }}</dd>
                <dt>Actual RTP</dt><dd>{{ $meters->actualRtp() === null ? '—' : number_format($meters->actualRtp(), 2).'%' }}</dd>
                <dt>Games played</dt><dd>{{ number_format($meters->games_played) }}</dd>
                <dt>Free games</dt><dd>{{ number_format($meters->free_games_triggered) }} <small>{{ $oneIn($meters->free_games_triggered, $meters->games_played) }}</small></dd>
                <dt>Bale Bonus</dt><dd>{{ number_format($meters->bale_bonus_triggered) }} <small>{{ $oneIn($meters->bale_bonus_triggered, $meters->games_played) }}</small></dd>
                <dt>Major / Grand hits</dt><dd>{{ $meters->major_hits }} / {{ $meters->grand_hits }}</dd>
                <dt>Progressives paid</dt><dd>{{ $money($meters->progressives_paid_cents) }}</dd>
                <dt>Deposits</dt><dd>{{ $money($meters->deposits_cents) }}</dd>
                <dt>Since</dt><dd>{{ ($meters->cleared_at ?? $meters->created_at)?->toDayDateTimeString() ?? '—' }}</dd>
            </dl>
        </section>
    @endunless
</div>
