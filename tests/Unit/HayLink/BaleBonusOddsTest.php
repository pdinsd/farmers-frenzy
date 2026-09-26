<?php

namespace Tests\Unit\HayLink;

use App\Games\HayLink\BaleBonusOdds;
use PHPUnit\Framework\TestCase;

class BaleBonusOddsTest extends TestCase
{
    public function test_certain_landings_always_fill_the_screen(): void
    {
        $odds = new BaleBonusOdds(array_fill(7, 9, 1.0), respins: 3);

        $this->assertSame(['grand' => 1.0, 'average_bales' => 15.0], $odds->fromStart(6));
    }

    public function test_a_fifteenth_bale_that_never_lands_means_no_grand(): void
    {
        $odds = new BaleBonusOdds(array_fill(7, 8, 1.0) + [15 => 0.0], respins: 3);

        $this->assertSame(['grand' => 0.0, 'average_bales' => 14.0], $odds->fromStart(6));
    }

    public function test_a_bale_rolls_on_the_landing_respin_and_then_three_more_times(): void
    {
        $odds = new BaleBonusOdds(array_fill(7, 8, 1.0) + [15 => 0.5], respins: 3);

        $this->assertEqualsWithDelta(1 - 0.5 ** 4, $odds->fromStart(6)['grand'], 1e-12);
    }

    public function test_starting_with_more_bales_makes_the_grand_more_likely(): void
    {
        $odds = new BaleBonusOdds([7 => 0.45, 8 => 0.35, 9 => 0.28, 10 => 0.22, 11 => 0.16, 12 => 0.11, 13 => 0.07, 14 => 0.035, 15 => 0.0015], respins: 3);

        $this->assertGreaterThan($odds->fromStart(6)['grand'], $odds->fromStart(10)['grand']);
        $this->assertGreaterThan(6.0, $odds->fromStart(6)['average_bales']);
    }
}
