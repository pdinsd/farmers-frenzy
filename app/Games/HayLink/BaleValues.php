<?php

namespace App\Games\HayLink;

use Random\Randomizer;

final class BaleValues
{
    /**
     * @param  array<string, list<array{multiplier?: int, jackpot?: string, weight: int|float}>>  $tables
     * @param  array{mini: array{bet_multiplier: int}, minor: array{bet_multiplier: int}}  $jackpots
     * @param  int  $cappedMinMultiplier  Credit bales at or above this bet multiple are ultra-high prizes.
     */
    public function __construct(
        private readonly array $tables,
        private readonly array $jackpots,
        private readonly int $cappedMinMultiplier,
    ) {}

    /**
     * Draw a value for a bale from the named weighted table.
     *
     * Jackpot entries have their weight multiplied by `$jackpotBoost`, so higher
     * bets land jackpot bales more often, and credit values are multiplied by
     * `$creditScale` (the bet level balancing). When `$allowCapped` is false the
     * ultra-high prizes (see isCapped()) cannot be drawn.
     *
     * Credit values and the Mini/Minor are resolved against the bet immediately.
     * A Major has amount 0 here; it is paid from the progressive when the feature is collected.
     *
     * @return array{type: string, amount: int}
     */
    public function pick(string $table, int $totalBet, Randomizer $randomizer, float $jackpotBoost = 1.0, bool $allowCapped = true, float $creditScale = 1.0): array
    {
        $entries = array_values(array_filter(
            array_map(
                fn (array $entry): array => isset($entry['jackpot']) ? [...$entry, 'weight' => $entry['weight'] * $jackpotBoost] : $entry,
                $this->tables[$table],
            ),
            fn (array $entry): bool => $allowCapped || ! $this->isCappedEntry($entry),
        ));
        $roll = $randomizer->nextFloat() * array_sum(array_column($entries, 'weight'));

        foreach ($entries as $entry) {
            $roll -= $entry['weight'];

            if ($roll <= 0) {
                return $this->valueFor($entry, $totalBet, $creditScale);
            }
        }

        return $this->valueFor(end($entries), $totalBet, $creditScale);
    }

    /**
     * Whether a landed bale is an ultra-high prize: a Major, or a credit
     * ball worth at least the capped multiple of the bet (after bet level
     * balancing). Only one may land per feature.
     *
     * @param  array{type: string, amount: int}|null  $value
     */
    public function isCapped(?array $value, int $totalBet, float $creditScale = 1.0): bool
    {
        if ($value === null) {
            return false;
        }

        return $value['type'] === JackpotTier::Major->value
            || ($value['type'] === 'credits' && $value['amount'] >= $this->cappedMinMultiplier * $totalBet * $creditScale);
    }

    /**
     * @param  array{multiplier?: int, jackpot?: string, weight: int|float}  $entry
     */
    private function isCappedEntry(array $entry): bool
    {
        return ($entry['jackpot'] ?? null) === JackpotTier::Major->value
            || ($entry['multiplier'] ?? 0) >= $this->cappedMinMultiplier;
    }

    /**
     * @param  array{multiplier?: int, jackpot?: string, weight: int|float}  $entry
     * @return array{type: string, amount: int}
     */
    private function valueFor(array $entry, int $totalBet, float $creditScale): array
    {
        if (! isset($entry['jackpot'])) {
            return ['type' => 'credits', 'amount' => max(1, (int) round($entry['multiplier'] * $totalBet * $creditScale))];
        }

        $tier = JackpotTier::from($entry['jackpot']);

        return [
            'type' => $tier->value,
            'amount' => $tier->isProgressive() ? 0 : $this->jackpots[$tier->value]['bet_multiplier'] * $totalBet,
        ];
    }
}
