<?php

namespace App\Games\HayLink;

/**
 * The machine's PAR (Probability Accounting Report) sheet: everything that can
 * be calculated exactly from the reel strips and weight tables. Simulated
 * figures (RTP, volatility) come from the Simulator.
 */
final class ParSheet
{
    public function __construct(private readonly MachineConfig $machineConfig) {}

    /**
     * @param  array<string, mixed>|null  $settings  Settings to report on instead of the saved ones.
     * @return array<string, mixed>
     */
    public function build(?array $settings = null): array
    {
        $settings = [...$this->machineConfig->settings(), ...($settings ?? [])];
        $config = $this->machineConfig->effective($settings);
        $baseReels = ReelSet::fromSpec($config['reels']['base'], $config['strip_seed']);
        $freeReels = ReelSet::fromSpec($config['reels']['free'], $config['strip_seed']);
        $minBet = min($config['credits_per_line_options']);
        $maxBet = max($config['credits_per_line_options']);

        return [
            'settings' => $settings,
            'rtp_program' => $settings['rtp_program'],
            'program' => $config['rtp_programs'][$settings['rtp_program']],
            'denominations' => array_map(
                fn (int $denomination, int $lines): array => [
                    'denomination' => $denomination,
                    'lines' => $lines,
                    'min_bet_cents' => $minBet * $lines * $denomination,
                    'max_bet_cents' => $maxBet * $lines * $denomination,
                ],
                array_keys($config['denominations']),
                $config['denominations'],
            ),
            'credits_per_line_options' => $config['credits_per_line_options'],
            'strips' => [
                'base' => $this->stripComposition($baseReels),
                'free' => $this->stripComposition($freeReels),
            ],
            'triggers' => [
                'base' => $this->triggerOdds($baseReels, $config),
                'free' => $this->triggerOdds($freeReels, $config),
            ],
            'free_games_feature' => $this->freeGamesFeature($this->triggerOdds($freeReels, $config), $config),
            'paytable' => $config['paytable'],
            'scatter_pays' => $config['scatter_pays'],
            'free_games' => $config['free_games'],
            'door_reveals' => $this->probabilities($config['door_reveals']),
            'bale_values' => array_map(
                fn (array $entries): array => $this->baleTable($config, $entries, $minBet, $maxBet),
                HayLinkGame::baleTables($config),
            ),
            'bale_bonus' => $config['bale_bonus'],
            'bale_bonus_outcomes' => $this->baleBonusOutcomes($config),
            'value_profile_selection' => [
                'cold' => $this->probabilities($config['bale_bonus']['profile_selection']['cold']),
                'hot' => $this->probabilities($config['bale_bonus']['profile_selection']['hot']),
            ],
            'jackpots' => $config['jackpots'],
            'jackpot_bet_scaling' => $config['jackpot_bet_scaling'],
            'credit_value_scale' => $config['credit_value_scale'],
            'major_chances' => $this->majorChances($config),
            'minis' => $this->miniOdds($config),
        ];
    }

    /**
     * How many of each symbol are on every reel strip.
     *
     * @return array{lengths: list<int>, symbols: array<string, list<int>>}
     */
    private function stripComposition(ReelSet $reels): array
    {
        $symbols = [];

        foreach ($reels->strips as $reel => $strip) {
            foreach (array_count_values(array_map(fn (Symbol $symbol): string => $symbol->value, $strip)) as $symbol => $count) {
                $symbols[$symbol] ??= array_fill(0, count($reels->strips), 0);
                $symbols[$symbol][$reel] = $count;
            }
        }

        uksort($symbols, fn (string $a, string $b): int => array_search($a, array_column(Symbol::cases(), 'value')) <=> array_search($b, array_column(Symbol::cases(), 'value')));

        return ['lengths' => array_map('count', $reels->strips), 'symbols' => $symbols];
    }

    /**
     * Exact odds of each feature triggering on one spin, found by combining
     * every reel's distribution of Moons, bales and doors in view.
     *
     * @param  array<string, mixed>  $config
     * @return array{free_games: float, bale_bonus: float, any_bonus: float, doors: float, retrigger_only: float, neither: float, full_screen: float}
     */
    private function triggerOdds(ReelSet $reels, array $config): array
    {
        $combined = ['0,0,0' => 1.0];

        foreach ($reels->strips as $strip) {
            $length = count($strip);
            $reelDistribution = [];

            for ($stop = 0; $stop < $length; $stop++) {
                $window = [$strip[$stop], $strip[($stop + 1) % $length], $strip[($stop + 2) % $length]];
                $key = implode(',', [
                    count(array_filter($window, fn (Symbol $symbol): bool => $symbol->isScatter())),
                    count(array_filter($window, fn (Symbol $symbol): bool => $symbol->isBale())),
                    count(array_filter($window, fn (Symbol $symbol): bool => $symbol->isDoor())),
                ]);
                $reelDistribution[$key] = ($reelDistribution[$key] ?? 0) + 1 / $length;
            }

            $next = [];

            foreach ($combined as $totals => $probability) {
                [$moons, $bales, $doors] = array_map('intval', explode(',', $totals));

                foreach ($reelDistribution as $counts => $reelProbability) {
                    [$reelMoons, $reelBales, $reelDoors] = array_map('intval', explode(',', $counts));
                    $key = ($moons + $reelMoons).','.($bales + $reelBales).','.($doors + $reelDoors);
                    $next[$key] = ($next[$key] ?? 0) + $probability * $reelProbability;
                }
            }

            $combined = $next;
        }

        $doorBaleChance = ($config['door_reveals']['bale'] ?? 0) / array_sum($config['door_reveals']);
        $odds = ['free_games' => 0.0, 'bale_bonus' => 0.0, 'any_bonus' => 0.0, 'doors' => 0.0, 'retrigger_only' => 0.0, 'neither' => 0.0, 'full_screen' => 0.0];

        foreach ($combined as $totals => $probability) {
            [$moons, $bales, $doors] = array_map('intval', explode(',', $totals));
            $freeGames = $moons >= $config['free_games']['trigger_count'];
            $baleBonus = match (true) {
                $bales >= $config['bale_bonus']['trigger_count'] => 1.0,
                $doors > 0 && $bales + $doors >= $config['bale_bonus']['trigger_count'] => $doorBaleChance,
                default => 0.0,
            };

            $odds['free_games'] += $freeGames ? $probability : 0.0;
            $odds['bale_bonus'] += $probability * $baleBonus;
            $odds['any_bonus'] += $freeGames ? $probability : $probability * $baleBonus;
            $odds['doors'] += $doors > 0 ? $probability : 0.0;
            $odds['retrigger_only'] += $freeGames ? $probability * (1 - $baleBonus) : 0.0;
            $odds['neither'] += $freeGames ? 0.0 : $probability * (1 - $baleBonus);
            $odds['full_screen'] += match (true) {
                $bales >= HayLinkGame::CELLS => $probability,
                $doors > 0 && $bales + $doors >= HayLinkGame::CELLS => $probability * $doorBaleChance,
                default => 0.0,
            };
        }

        return $odds;
    }

    /**
     * Exact chance that a free games feature (including retriggers) triggers
     * Bale Bonus at least once, and the expected number of free spins played.
     *
     * @param  array{retrigger_only: float, neither: float, free_games: float}  $freeSpinOdds
     * @param  array<string, mixed>  $config
     * @return array{bale_bonus: float, average_spins: float}
     */
    private function freeGamesFeature(array $freeSpinOdds, array $config): array
    {
        $awarded = $config['free_games']['awarded'];
        $retrigger = $config['free_games']['retrigger_awarded'];
        $cap = 500;
        $noBaleBonus = array_fill(0, $cap + $retrigger + 1, 1.0);

        for ($iteration = 0; $iteration < 200; $iteration++) {
            for ($spins = 1; $spins <= $cap; $spins++) {
                $noBaleBonus[$spins] = $freeSpinOdds['neither'] * $noBaleBonus[$spins - 1]
                    + $freeSpinOdds['retrigger_only'] * $noBaleBonus[$spins - 1 + $retrigger];
            }
        }

        return [
            'bale_bonus' => 1 - $noBaleBonus[$awarded],
            'average_spins' => $awarded / max(1e-9, 1 - $freeSpinOdds['free_games'] * $retrigger),
        ];
    }

    /**
     * The chance a Bale Bonus contains the Major, by denomination at the
     * minimum and maximum bet, with the Major at its seed and at its cap.
     *
     * @param  array<string, mixed>  $config
     * @return list<array{denomination: int, min_bet_seed: float, min_bet_cap: float, max_bet_seed: float, max_bet_cap: float}>
     */
    private function majorChances(array $config): array
    {
        $settings = $config['bale_bonus']['major_chance'];
        $chance = fn (int $betCents, float $multiplier): float => min(1.0, $settings['per_bet_cent'] * $betCents * $multiplier);
        $minBet = min($config['credits_per_line_options']);
        $maxBet = max($config['credits_per_line_options']);

        return array_map(
            fn (int $denomination, int $lines): array => [
                'denomination' => $denomination,
                'min_bet_seed' => $chance($minBet * $lines * $denomination, 1.0),
                'min_bet_cap' => $chance($minBet * $lines * $denomination, $settings['at_cap_multiplier']),
                'max_bet_seed' => $chance($maxBet * $lines * $denomination, 1.0),
                'max_bet_cap' => $chance($maxBet * $lines * $denomination, $settings['at_cap_multiplier']),
            ],
            array_keys($config['denominations']),
            $config['denominations'],
        );
    }

    /**
     * Minis decided at the start of a Bale Bonus, by bet level: the chance of
     * at least one and the expected number (before any are left unplaced
     * because the feature ends).
     *
     * @param  array<string, mixed>  $config
     * @return list<array{credits_per_line: int, chance: float, expected: float, credit_scale: float}>
     */
    private function miniOdds(array $config): array
    {
        $minis = $config['bale_bonus']['minis'];

        return array_map(function (int $creditsPerLine) use ($minis, $config): array {
            $chance = $minis['chance'][$creditsPerLine] ?? 0.0;
            $extra = $minis['extra_chance'][$creditsPerLine] ?? 0.0;
            $expectedGivenAny = array_sum(array_map(fn (int $count): float => $extra ** ($count - 1), range(1, $minis['max'])));

            return [
                'credits_per_line' => $creditsPerLine,
                'chance' => $chance,
                'expected' => $chance * $expectedGivenAny,
                'credit_scale' => (float) ($config['credit_value_scale'][$creditsPerLine] ?? 1.0),
            ];
        }, $config['credits_per_line_options']);
    }

    /**
     * Exact Grand odds and expected final bale count by starting bales.
     *
     * @param  array<string, mixed>  $config
     * @return list<array{start: int, grand: float, average_bales: float}>
     */
    private function baleBonusOutcomes(array $config): array
    {
        $odds = new BaleBonusOdds($config['bale_bonus']['landing_chances'], $config['bale_bonus']['respins']);

        return array_map(
            fn (int $start): array => ['start' => $start, ...$odds->fromStart($start)],
            range($config['bale_bonus']['trigger_count'], 14),
        );
    }

    /**
     * @param  array<string, int|float>  $weights
     * @return array<string, float>
     */
    private function probabilities(array $weights): array
    {
        $total = array_sum($weights);

        return array_map(fn (int|float $weight): float => $weight / $total, $weights);
    }

    /**
     * Each bale value's chance of landing at the minimum and maximum bet,
     * with the expected credit value (as a multiple of the total bet).
     *
     * @param  array<string, mixed>  $config
     * @param  list<array{multiplier?: int, jackpot?: string, weight: int|float}>  $entries
     * @return array{rows: list<array{label: string, weight: int|float, min_bet_chance: float, max_bet_chance: float}>, min_bet_expected_multiple: float, max_bet_expected_multiple: float}
     */
    private function baleTable(array $config, array $entries, int $minCreditsPerLine, int $maxCreditsPerLine): array
    {
        $boosts = [
            'min' => $config['jackpot_bet_scaling'][$minCreditsPerLine] ?? 1.0,
            'max' => $config['jackpot_bet_scaling'][$maxCreditsPerLine] ?? 1.0,
        ];
        $expected = [];
        $chances = [];

        foreach ($boosts as $level => $boost) {
            $weights = array_map(fn (array $entry): float => isset($entry['jackpot']) ? $entry['weight'] * $boost : (float) $entry['weight'], $entries);
            $total = array_sum($weights);
            $chances[$level] = array_map(fn (float $weight): float => $weight / $total, $weights);
            $expected[$level] = array_sum(array_map(
                fn (array $entry, float $chance): float => $chance * match ($entry['jackpot'] ?? null) {
                    null => $entry['multiplier'],
                    'mini', 'minor' => $config['jackpots'][$entry['jackpot']]['bet_multiplier'],
                    default => 0,
                },
                $entries,
                $chances[$level],
            ));
        }

        return [
            'rows' => array_map(
                fn (array $entry, int $index): array => [
                    'label' => isset($entry['jackpot']) ? strtoupper($entry['jackpot']) : $entry['multiplier'].'x bet',
                    'weight' => $entry['weight'],
                    'min_bet_chance' => $chances['min'][$index],
                    'max_bet_chance' => $chances['max'][$index],
                ],
                $entries,
                array_keys($entries),
            ),
            'min_bet_expected_multiple' => $expected['min'],
            'max_bet_expected_multiple' => $expected['max'],
        ];
    }
}
