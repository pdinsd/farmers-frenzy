<?php

namespace Tests\Unit\HayLink;

use App\Games\HayLink\BaleValues;
use PHPUnit\Framework\TestCase;
use Random\Engine\Mt19937;
use Random\Randomizer;

class BaleValuesTest extends TestCase
{
    public function test_a_higher_bet_boost_lands_jackpot_bales_more_often(): void
    {
        $values = $this->values([
            ['multiplier' => 1, 'weight' => 90],
            ['jackpot' => 'mini', 'weight' => 10],
        ]);

        $minBetMinis = $this->countMinis($values, jackpotBoost: 1.0);
        $maxBetMinis = $this->countMinis($values, jackpotBoost: 4.0);

        $this->assertEqualsWithDelta(0.10 * 4000, $minBetMinis, 60);
        $this->assertEqualsWithDelta(40 / 130 * 4000, $maxBetMinis, 90);
    }

    public function test_jackpot_amounts_scale_with_the_bet(): void
    {
        $values = $this->values([['jackpot' => 'minor', 'weight' => 1]]);

        $this->assertSame(['type' => 'minor', 'amount' => 100 * 500], $values->pick('base', 500, new Randomizer(new Mt19937(1))));
    }

    public function test_capped_prizes_are_excluded_when_not_allowed(): void
    {
        $values = $this->values([
            ['multiplier' => 250, 'weight' => 1000],
            ['jackpot' => 'major', 'weight' => 1000],
            ['multiplier' => 3, 'weight' => 1],
        ]);
        $randomizer = new Randomizer(new Mt19937(5));

        for ($i = 0; $i < 200; $i++) {
            $this->assertSame(['type' => 'credits', 'amount' => 150], $values->pick('base', 50, $randomizer, allowCapped: false));
        }
    }

    public function test_it_identifies_ultra_high_prizes(): void
    {
        $values = $this->values([]);

        $this->assertTrue($values->isCapped(['type' => 'major', 'amount' => 0], 50));
        $this->assertTrue($values->isCapped(['type' => 'credits', 'amount' => 100 * 50], 50));
        $this->assertFalse($values->isCapped(['type' => 'credits', 'amount' => 50 * 50], 50));
        $this->assertFalse($values->isCapped(['type' => 'minor', 'amount' => 100 * 50], 50));
        $this->assertFalse($values->isCapped(null, 50));
    }

    /**
     * @param  list<array{multiplier?: int, jackpot?: string, weight: int}>  $table
     */
    private function values(array $table): BaleValues
    {
        return new BaleValues(
            ['base' => $table],
            ['mini' => ['bet_multiplier' => 20], 'minor' => ['bet_multiplier' => 100]],
            cappedMinMultiplier: 100,
        );
    }

    private function countMinis(BaleValues $values, float $jackpotBoost): int
    {
        $randomizer = new Randomizer(new Mt19937(3));
        $minis = 0;

        for ($i = 0; $i < 4000; $i++) {
            $minis += $values->pick('base', 50, $randomizer, $jackpotBoost)['type'] === 'mini' ? 1 : 0;
        }

        return $minis;
    }
}
