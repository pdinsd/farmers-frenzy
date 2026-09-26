<?php

namespace App\Games\HayLink;

use App\Models\MachineMeter;

/**
 * The machine's accounting meters: money in and out, games and features.
 */
final class MachineMeters
{
    public function current(): MachineMeter
    {
        $meter = MachineMeter::query()->firstOrCreate(['id' => 1]);

        return $meter->wasRecentlyCreated ? $meter->refresh() : $meter;
    }

    /**
     * Record one press of SPIN (a paid game, free game or respin). Features
     * are counted against the paid game that triggered them; Bale Bonus wins
     * inside free games count as free games wins.
     *
     * @param  array<string, mixed>  $outcome
     */
    public function recordPlay(array $outcome, int $denomination, int $totalBet): void
    {
        $isBaseGame = $outcome['mode'] === 'base';
        $finishedBaleBonus = $outcome['mode'] === 'bale_bonus' && $outcome['finished'];
        $countCells = fn (JackpotTier $tier): int => $finishedBaleBonus && ! $outcome['values_replaced_by_grand']
            ? count(array_filter($outcome['cells'], fn (?array $cell): bool => ($cell['type'] ?? null) === $tier->value))
            : 0;
        $triggeredFeature = ($outcome['triggered']['free_games'] ?? 0) > 0 || ($outcome['triggered']['bale_bonus'] ?? false);
        $majorCredits = $finishedBaleBonus && ! $outcome['values_replaced_by_grand']
            ? array_sum(array_map(fn (?array $cell): int => ($cell['type'] ?? null) === JackpotTier::Major->value ? $cell['amount'] : 0, $outcome['cells']))
            : 0;

        $increments = array_filter([
            'coin_in_cents' => $isBaseGame ? $totalBet * $denomination : 0,
            'coin_out_cents' => $outcome['win'] * $denomination,
            'line_wins_cents' => $isBaseGame ? array_sum(array_column($outcome['line_wins'], 'win')) * $denomination : 0,
            'scatter_wins_cents' => $isBaseGame ? $outcome['scatter']['win'] * $denomination : 0,
            'free_games_wins_cents' => match (true) {
                $outcome['mode'] === 'free' => $outcome['win'] * $denomination,
                $finishedBaleBonus && ($outcome['during_free_games'] ?? false) => $outcome['win'] * $denomination,
                default => 0,
            },
            'bale_bonus_wins_cents' => $finishedBaleBonus && ! ($outcome['during_free_games'] ?? false) ? $outcome['win'] * $denomination : 0,
            'games_played' => $isBaseGame ? 1 : 0,
            'winning_games' => $isBaseGame && ($outcome['win'] > 0 || $triggeredFeature) ? 1 : 0,
            'free_games_triggered' => $isBaseGame && ($outcome['triggered']['free_games'] ?? 0) > 0 ? 1 : 0,
            'bale_bonus_triggered' => $isBaseGame && ($outcome['triggered']['bale_bonus'] ?? false) ? 1 : 0,
            'mini_hits' => $countCells(JackpotTier::Mini),
            'minor_hits' => $countCells(JackpotTier::Minor),
            'major_hits' => $countCells(JackpotTier::Major),
            'grand_hits' => $finishedBaleBonus && $outcome['grand_won'] ? 1 : 0,
            'progressives_paid_cents' => $finishedBaleBonus ? ($majorCredits + $outcome['grand']) * $denomination : 0,
        ]);

        if ($increments !== []) {
            $this->current();
            MachineMeter::query()->whereKey(1)->incrementEach($increments);
        }
    }

    public function recordDeposit(int $cents): void
    {
        $this->current();
        MachineMeter::query()->whereKey(1)->increment('deposits_cents', $cents);
    }

    /**
     * RAM clear of the accounting meters.
     */
    public function clear(): void
    {
        $this->current()->update([
            'coin_in_cents' => 0,
            'coin_out_cents' => 0,
            'line_wins_cents' => 0,
            'scatter_wins_cents' => 0,
            'free_games_wins_cents' => 0,
            'bale_bonus_wins_cents' => 0,
            'games_played' => 0,
            'winning_games' => 0,
            'free_games_triggered' => 0,
            'bale_bonus_triggered' => 0,
            'mini_hits' => 0,
            'minor_hits' => 0,
            'major_hits' => 0,
            'grand_hits' => 0,
            'progressives_paid_cents' => 0,
            'deposits_cents' => 0,
            'cleared_at' => now(),
        ]);
    }
}
