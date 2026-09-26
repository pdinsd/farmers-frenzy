<?php

namespace App\Games\HayLink;

final class PaylineEvaluator
{
    /**
     * @param  list<list<int>>  $paylines
     * @param  array<string, array<int, int>>  $paytable
     * @param  array<int, int>  $scatterPays
     */
    public function __construct(
        private readonly array $paylines,
        private readonly array $paytable,
        private readonly array $scatterPays,
    ) {}

    /**
     * Evaluate the first `$lineCount` paylines left to right. Wins are in credits.
     *
     * @param  list<list<Symbol>>  $grid
     * @return list<array{line: int, symbol: string, count: int, positions: list<array{0: int, 1: int}>, win: int}>
     */
    public function lineWins(array $grid, int $creditsPerLine, int $lineCount): array
    {
        $wins = [];

        foreach (array_slice($this->paylines, 0, $lineCount) as $index => $rows) {
            $symbols = array_map(fn (int $reel): Symbol => $grid[$reel][$rows[$reel]], array_keys($rows));
            $best = $this->bestPay($symbols);

            if ($best === null) {
                continue;
            }

            $wins[] = [
                'line' => $index + 1,
                'symbol' => $best['symbol']->value,
                'count' => $best['count'],
                'positions' => array_map(fn (int $reel): array => [$reel, $rows[$reel]], range(0, $best['count'] - 1)),
                'win' => $best['pay'] * $creditsPerLine,
            ];
        }

        return $wins;
    }

    /**
     * Scatter pays anywhere on the screen, as a multiple of the total bet.
     *
     * @param  list<list<Symbol>>  $grid
     * @return array{count: int, positions: list<array{0: int, 1: int}>, win: int}
     */
    public function scatterWin(array $grid, int $totalBet): array
    {
        $positions = $this->positionsOf($grid, Symbol::Moon);
        $count = count($positions);
        $payingCount = min($count, max(array_keys($this->scatterPays)));

        return [
            'count' => $count,
            'positions' => $positions,
            'win' => ($this->scatterPays[$payingCount] ?? 0) * $totalBet,
        ];
    }

    /**
     * @param  list<list<Symbol>>  $grid
     * @return list<array{0: int, 1: int}>
     */
    public function positionsOf(array $grid, Symbol $symbol): array
    {
        $positions = [];

        foreach ($grid as $reel => $column) {
            foreach ($column as $row => $cell) {
                if ($cell === $symbol) {
                    $positions[] = [$reel, $row];
                }
            }
        }

        return $positions;
    }

    /**
     * The best paying combination on a single line, considering both the
     * substituted symbol and a pure run of wilds.
     *
     * @param  list<Symbol>  $symbols
     * @return array{symbol: Symbol, count: int, pay: int}|null
     */
    private function bestPay(array $symbols): ?array
    {
        $candidates = [];

        $leadingWilds = 0;
        while ($leadingWilds < count($symbols) && $symbols[$leadingWilds]->isWild()) {
            $leadingWilds++;
        }

        if ($leadingWilds > 0) {
            $candidates[] = ['symbol' => Symbol::Farmer, 'count' => $leadingWilds];
        }

        $target = $symbols[$leadingWilds] ?? null;

        if ($target !== null && $target->isSubstitutable()) {
            $count = $leadingWilds;
            while ($count < count($symbols) && ($symbols[$count] === $target || $symbols[$count]->isWild())) {
                $count++;
            }
            $candidates[] = ['symbol' => $target, 'count' => $count];
        }

        $best = null;

        foreach ($candidates as $candidate) {
            $pay = $this->paytable[$candidate['symbol']->value][$candidate['count']] ?? 0;

            if ($pay > 0 && ($best === null || $pay > $best['pay'])) {
                $best = [...$candidate, 'pay' => $pay];
            }
        }

        return $best;
    }
}
