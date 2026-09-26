<?php

namespace App\Games\HayLink;

use App\Games\HayLink\Jackpots\InMemoryJackpotBank;
use Random\Engine\Mt19937;
use Random\Randomizer;

/**
 * Plays the machine many times with a private jackpot bank and measures
 * return to player, feature frequencies and volatility.
 *
 * @phpstan-type SimulationResult array{
 *     spins: int,
 *     credits_per_line: int,
 *     denomination: int,
 *     lines: int,
 *     total_bet: int,
 *     rtp_program: int,
 *     fingerprint: string,
 *     rtp: float,
 *     rtp_confidence: float,
 *     rtp_excluding_progressives: float,
 *     rtp_by_source: array<string, float>,
 *     progressive_contribution: float,
 *     hit_frequency: float,
 *     standard_deviation: float,
 *     volatility_index: float,
 *     counts: array<string, int>,
 *     win_distribution: list<array{label: string, count: int}>,
 *     biggest_win_multiple: float,
 *     ran_at: string,
 * }
 */
final class Simulator
{
    /**
     * Wins grouped by multiple of the total bet, as [label, lower bound inclusive].
     */
    private const WIN_BUCKETS = [
        ['No win', 0.0],
        ['Under 1x', 0.000001],
        ['1x to 5x', 1.0],
        ['5x to 20x', 5.0],
        ['20x to 100x', 20.0],
        ['100x to 500x', 100.0],
        ['500x or more', 500.0],
    ];

    public function __construct(private readonly MachineConfig $machineConfig) {}

    /**
     * @param  array<string, mixed>  $settings  Attendant settings to simulate instead of the saved ones.
     * @param  (callable(int): void)|null  $onProgress  Called with the number of spins completed.
     * @return SimulationResult
     */
    public function run(int $spins, int $creditsPerLine, int $denomination, array $settings = [], ?int $seed = null, ?callable $onProgress = null): array
    {
        $config = $this->machineConfig->effective($settings);
        $randomizer = new Randomizer($seed === null ? null : new Mt19937($seed));
        $game = HayLinkGame::fromConfig($config, new InMemoryJackpotBank($config['jackpots']), $randomizer);

        $state = $game->newState(denomination: $denomination);
        $state->creditsPerLine = $creditsPerLine;
        $game->normalize($state);
        $totalBet = $game->totalBet($state);

        $won = ['base_lines' => 0, 'base_scatter' => 0, 'free_games' => 0, 'bale_bonus' => 0];
        $progressivesWon = 0;
        $counts = [
            'winning_spins' => 0, 'free_games_triggers' => 0, 'bale_bonus_triggers' => 0, 'any_bonus' => 0,
            'door_reveals' => 0, 'door_bale_reveals' => 0, 'mini' => 0, 'minor' => 0, 'major' => 0, 'grand' => 0,
        ];
        $buckets = array_fill(0, count(self::WIN_BUCKETS), 0);
        $sumOfSquares = 0.0;
        $biggestWin = 0;

        for ($spin = 1; $spin <= $spins; $spin++) {
            $state->credits = PHP_INT_MAX >> 1;
            $outcome = $game->play($state);
            $spinWin = $outcome['win'];

            $won['base_lines'] += array_sum(array_column($outcome['line_wins'], 'win'));
            $won['base_scatter'] += $outcome['scatter']['win'];
            $counts['free_games_triggers'] += $outcome['triggered']['free_games'] > 0 ? 1 : 0;
            $counts['bale_bonus_triggers'] += $outcome['triggered']['bale_bonus'] ? 1 : 0;
            $counts['any_bonus'] += ($outcome['triggered']['free_games'] > 0 || $outcome['triggered']['bale_bonus']) ? 1 : 0;

            while ($state->isInFeature()) {
                $duringFreeGames = $state->baleBonus === null || $state->baleBonus['during_free_games'];
                $feature = $game->play($state);

                $won[$duringFreeGames ? 'free_games' : 'bale_bonus'] += $feature['win'];
                $spinWin += $feature['win'];

                if (($feature['doors'] ?? null) !== null) {
                    $counts['door_reveals']++;
                    $counts['door_bale_reveals'] += $feature['doors']['symbol'] === 'bale' ? 1 : 0;
                }

                if ($feature['mode'] === 'bale_bonus' && $feature['finished']) {
                    foreach (['mini', 'minor', 'major'] as $tier) {
                        $counts[$tier] += count(array_filter($feature['cells'], fn (?array $cell): bool => ($cell['type'] ?? null) === $tier));
                    }
                    $counts['grand'] += $feature['grand_won'] ? 1 : 0;
                    $progressivesWon += $feature['grand'] + ($feature['values_replaced_by_grand'] ? 0 : array_sum(array_map(
                        fn (?array $cell): int => ($cell['type'] ?? null) === 'major' ? $cell['amount'] : 0,
                        $feature['cells'],
                    )));
                }
            }

            $multiple = $spinWin / $totalBet;
            $sumOfSquares += $multiple ** 2;
            $counts['winning_spins'] += $spinWin > 0 ? 1 : 0;
            $biggestWin = max($biggestWin, $spinWin);
            $buckets[$this->bucketFor($multiple)]++;

            if ($onProgress !== null && $spin % 1000 === 0) {
                $onProgress($spin);
            }
        }

        $wagered = $spins * $totalBet;
        $rtp = array_sum($won) / $wagered;
        $variance = max(0.0, $sumOfSquares / $spins - $rtp ** 2);
        $standardDeviation = sqrt($variance);

        return [
            'spins' => $spins,
            'credits_per_line' => $state->creditsPerLine,
            'denomination' => $state->denomination,
            'lines' => $game->lines($state),
            'total_bet' => $totalBet,
            'rtp_program' => [...$this->machineConfig->settings(), ...$settings]['rtp_program'],
            'fingerprint' => $this->machineConfig->mathFingerprint($settings),
            'rtp' => $rtp * 100,
            'rtp_confidence' => 1.96 * $standardDeviation / sqrt($spins) * 100,
            'rtp_excluding_progressives' => (array_sum($won) - $progressivesWon) / $wagered * 100,
            'rtp_by_source' => array_map(fn (int $amount): float => $amount / $wagered * 100, $won),
            'progressive_contribution' => ($config['jackpots']['major']['contribution'] + $config['jackpots']['grand']['contribution']) * 100,
            'hit_frequency' => $counts['winning_spins'] / $spins * 100,
            'standard_deviation' => $standardDeviation,
            'volatility_index' => 1.645 * $standardDeviation,
            'counts' => $counts,
            'win_distribution' => array_map(
                fn (array $bucket, int $count): array => ['label' => $bucket[0], 'count' => $count],
                self::WIN_BUCKETS,
                $buckets,
            ),
            'biggest_win_multiple' => $biggestWin / $totalBet,
            'ran_at' => now()->toIso8601String(),
        ];
    }

    private function bucketFor(float $multiple): int
    {
        $bucket = 0;

        foreach (self::WIN_BUCKETS as $index => [, $lowerBound]) {
            if ($multiple >= $lowerBound) {
                $bucket = $index;
            }
        }

        return $bucket;
    }
}
