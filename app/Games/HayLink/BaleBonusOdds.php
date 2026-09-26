<?php

namespace App\Games\HayLink;

/**
 * Exact Bale Bonus outcomes for the landing-chance model: on each respin the
 * next bale lands with its own chance (and may chain into the next); any
 * landing resets the respins; filling all 15 windows wins the Grand.
 */
final class BaleBonusOdds
{
    /** @var array<string, array{0: float, 1: float}> */
    private array $memo = [];

    /**
     * @param  array<int, float>  $landingChances  Keyed by which bale (7th ... 15th) is landing.
     */
    public function __construct(
        private readonly array $landingChances,
        private readonly int $respins,
        private readonly int $cells = 15,
    ) {}

    /**
     * Chance of winning the Grand, and the expected number of bales at the
     * end, for a feature that starts with this many bales.
     *
     * @return array{grand: float, average_bales: float}
     */
    public function fromStart(int $bales): array
    {
        [$grand, $average] = $this->solve(min($bales, $this->cells), $this->respins);

        return ['grand' => $grand, 'average_bales' => $average];
    }

    /**
     * @return array{0: float, 1: float}
     */
    private function solve(int $filled, int $respinsLeft): array
    {
        if ($filled >= $this->cells) {
            return [1.0, (float) $this->cells];
        }

        if ($respinsLeft === 0) {
            return [0.0, (float) $filled];
        }

        if (isset($this->memo["{$filled},{$respinsLeft}"])) {
            return $this->memo["{$filled},{$respinsLeft}"];
        }

        $grand = 0.0;
        $average = 0.0;
        $reach = 1.0;

        for ($after = $filled; $after <= $this->cells; $after++) {
            if ($after === $this->cells) {
                $grand += $reach;
                $average += $reach * $this->cells;

                break;
            }

            $stopHere = $reach * (1 - $this->chance($after + 1));
            [$nextGrand, $nextAverage] = $after === $filled ? $this->solve($filled, $respinsLeft - 1) : $this->solve($after, $this->respins);
            $grand += $stopHere * $nextGrand;
            $average += $stopHere * $nextAverage;
            $reach *= $this->chance($after + 1);
        }

        return $this->memo["{$filled},{$respinsLeft}"] = [$grand, $average];
    }

    private function chance(int $nthBale): float
    {
        $lowest = min(array_keys($this->landingChances));

        return (float) ($this->landingChances[$nthBale] ?? ($nthBale < $lowest ? $this->landingChances[$lowest] : 0.0));
    }
}
