<?php

namespace App\Games\HayLink\Jackpots;

use App\Games\HayLink\JackpotTier;

interface JackpotBank
{
    /**
     * Add a share of a wager, in cents, to every progressive jackpot, up to its cap.
     */
    public function contribute(int $wagerCents): void;

    /**
     * Current progressive values in cents (may include fractions of a cent).
     *
     * @return array{major: float, grand: float}
     */
    public function progressiveValues(): array;

    /**
     * Pay out a progressive jackpot, resetting it to its seed. Returns whole cents won.
     */
    public function award(JackpotTier $tier): int;

    /**
     * Put a progressive back to its seed without paying it (an attendant reset).
     */
    public function resetToSeed(JackpotTier $tier): void;
}
