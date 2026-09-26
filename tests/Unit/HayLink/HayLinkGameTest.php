<?php

namespace Tests\Unit\HayLink;

use App\Games\HayLink\HayLinkGame;
use App\Games\HayLink\InsufficientCreditsException;
use App\Games\HayLink\Jackpots\InMemoryJackpotBank;
use App\Games\HayLink\JackpotTier;
use App\Games\HayLink\ReelSet;
use PHPUnit\Framework\TestCase;
use Random\Engine\Mt19937;
use Random\Randomizer;

class HayLinkGameTest extends TestCase
{
    public function test_a_base_spin_deducts_the_bet_and_pays_wins(): void
    {
        $game = $this->game();
        $state = $game->newState();

        $outcome = $game->play($state);

        $this->assertSame('base', $outcome['mode']);
        $this->assertSame(10000 - 50 + $outcome['win'], $state->credits);
        $this->assertCount(5, $outcome['grid']);
    }

    public function test_the_reel_strips_are_stable_between_builds(): void
    {
        $config = $this->config();

        $this->assertEquals(
            ReelSet::fromSpec($config['reels']['base'], $config['strip_seed'])->strips,
            ReelSet::fromSpec($config['reels']['base'], $config['strip_seed'])->strips,
        );
    }

    public function test_farmer_wilds_are_stacked_on_the_strips(): void
    {
        $config = $this->config();
        $strip = array_map(fn ($symbol) => $symbol->value, ReelSet::fromSpec($config['reels']['base'], $config['strip_seed'])->strips[1]);

        $this->assertStringContainsString('farmer,farmer,farmer', implode(',', $strip));
        $this->assertNotContains('farmer', array_map(fn ($symbol) => $symbol->value, ReelSet::fromSpec($config['reels']['base'], $config['strip_seed'])->strips[0]));
    }

    public function test_playing_without_enough_credits_throws(): void
    {
        $game = $this->game();
        $state = $game->newState();
        $state->credits = 49;

        $this->expectException(InsufficientCreditsException::class);

        $game->play($state);
    }

    public function test_six_or_more_bales_trigger_bale_bonus(): void
    {
        $game = $this->game(fn (array $config) => $this->allReels($config, 'bale', baleBonusChance: 0.0));
        $state = $game->newState();

        $outcome = $game->play($state);

        $this->assertTrue($outcome['triggered']['bale_bonus']);
        $this->assertCount(15, $outcome['bales']);
        $this->assertSame(3, $state->baleBonus['respins_left']);
        $this->assertTrue($state->isInFeature());
    }

    public function test_bale_bonus_ends_after_three_blank_respins_and_pays_the_held_bales(): void
    {
        $game = $this->game(fn (array $config) => $this->allReels($config, 'nine', baleBonusChance: 0.0));
        $state = $game->newState();
        $state->baleBonus = [
            'cells' => [['type' => 'credits', 'amount' => 100], ['type' => 'mini', 'amount' => 1000], ...array_fill(0, 13, null)],
            'respins_left' => 3,
            'during_free_games' => false,
        ];
        $creditsBefore = $state->credits;

        $this->assertFalse($game->play($state)['finished']);
        $this->assertFalse($game->play($state)['finished']);
        $outcome = $game->play($state);

        $this->assertTrue($outcome['finished']);
        $this->assertSame(1100, $outcome['win']);
        $this->assertSame($creditsBefore + 1100, $state->credits);
        $this->assertNull($state->baleBonus);
    }

    public function test_a_new_bale_resets_the_respins(): void
    {
        $game = $this->game(fn (array $config) => $this->allReels($config, 'nine', baleBonusChance: 1.0));
        $state = $game->newState();
        $state->baleBonus = [
            'cells' => [...array_fill(0, 6, ['type' => 'credits', 'amount' => 50]), ...array_fill(0, 9, null)],
            'respins_left' => 1,
            'during_free_games' => false,
        ];

        $outcome = $game->play($state);

        $this->assertCount(9, $outcome['landed']);
        $this->assertTrue($outcome['grand_won']);
        $this->assertTrue($outcome['finished']);
    }

    public function test_filling_all_fifteen_cells_awards_the_grand_and_resets_it(): void
    {
        $config = $this->allReels($this->config(), 'nine', baleBonusChance: 1.0);
        $jackpots = new InMemoryJackpotBank($config['jackpots']);
        $jackpots->contribute(1_000_000);
        $grandBefore = (int) floor($jackpots->progressiveValues()['grand']);

        $game = HayLinkGame::fromConfig($config, $jackpots, new Randomizer(new Mt19937(7)));
        $state = $game->newState();
        $state->baleBonus = [
            'cells' => [...array_fill(0, 14, ['type' => 'credits', 'amount' => 50]), null],
            'respins_left' => 3,
            'during_free_games' => false,
        ];

        $outcome = $game->play($state);

        $this->assertTrue($outcome['grand_won']);
        $this->assertSame($grandBefore, $outcome['grand']);
        $this->assertTrue($outcome['values_replaced_by_grand']);
        $this->assertSame($grandBefore, $outcome['win']);
        $this->assertEquals($config['jackpots']['grand']['seed'], $jackpots->progressiveValues()['grand']);
    }

    public function test_the_grand_can_be_paid_on_top_of_the_bale_values(): void
    {
        $config = $this->allReels($this->config(), 'nine', baleBonusChance: 1.0);
        $config['bale_bonus']['grand_replaces_values'] = false;
        $game = $this->game(fn () => $config);
        $state = $game->newState();
        $state->baleBonus = [
            'cells' => [...array_fill(0, 14, ['type' => 'credits', 'amount' => 50]), null],
            'respins_left' => 3,
            'during_free_games' => false,
        ];

        $outcome = $game->play($state);

        $this->assertFalse($outcome['values_replaced_by_grand']);
        $this->assertSame($outcome['grand'] + 700 + $outcome['cells'][14]['amount'], $outcome['win']);
    }

    public function test_higher_denominations_play_fewer_lines(): void
    {
        $game = $this->game(fn (array $config) => $this->allReels($config, 'nine', baleBonusChance: 0.0));
        $state = $game->newState();
        $game->changeDenomination($state, 100);

        $outcome = $game->play($state);

        $this->assertSame(5, $game->lines($state));
        $this->assertSame(5, $game->totalBet($state));
        $this->assertSame([1, 2, 3, 4, 5], array_column($outcome['line_wins'], 'line'));
    }

    public function test_progressives_contribute_and_pay_in_cents_whatever_the_denomination(): void
    {
        $config = $this->allReels($this->config(), 'nine', baleBonusChance: 0.0);
        $jackpots = new InMemoryJackpotBank($config['jackpots']);
        $game = HayLinkGame::fromConfig($config, $jackpots, new Randomizer(new Mt19937(7)));
        $state = $game->newState();
        $game->changeDenomination($state, 25);

        $game->play($state);

        $this->assertEqualsWithDelta($config['jackpots']['grand']['seed'] + 25 * 25 * $config['jackpots']['grand']['contribution'], $jackpots->progressiveValues()['grand'], 0.0001);

        $state->baleBonus = [
            'cells' => [['type' => JackpotTier::Major->value, 'amount' => 0], ...array_fill(0, 14, null)],
            'respins_left' => 1,
            'during_free_games' => false,
        ];
        $majorCents = (int) floor($jackpots->progressiveValues()['major']);

        $outcome = $game->play($state);

        $this->assertSame(intdiv($majorCents, 25), $outcome['win']);
    }

    public function test_the_major_chance_grows_with_the_bet_and_as_the_major_climbs(): void
    {
        $config = $this->config();
        $jackpots = new InMemoryJackpotBank($config['jackpots']);
        $game = HayLinkGame::fromConfig($config, $jackpots, new Randomizer(new Mt19937(1)));
        $state = $game->newState();

        $minBetAtSeed = $game->majorChance($state);
        $state->creditsPerLine = 10;
        $maxBetAtSeed = $game->majorChance($state);

        $this->assertEqualsWithDelta($config['bale_bonus']['major_chance']['per_bet_cent'] * 50, $minBetAtSeed, 1e-12);
        $this->assertEqualsWithDelta($minBetAtSeed * 10, $maxBetAtSeed, 1e-12);

        $jackpots->contribute(1_000_000_000);

        $this->assertEquals($config['jackpots']['major']['cap'], $jackpots->progressiveValues()['major']);
        $this->assertEqualsWithDelta($maxBetAtSeed * $config['bale_bonus']['major_chance']['at_cap_multiplier'], $game->majorChance($state), 1e-12);
    }

    public function test_a_pending_major_lands_on_a_new_bale(): void
    {
        $game = $this->game(function (array $config) {
            $config = $this->allReels($config, 'nine', baleBonusChance: 0.0);
            $config['bale_bonus']['landing_chances'] = [7 => 1.0, 8 => 0.0];
            $config['bale_bonus']['pending_landing_chance'] = 1.0;

            return $config;
        });
        $state = $game->newState();
        $state->baleBonus = [
            'cells' => [...array_fill(0, 6, ['type' => 'credits', 'amount' => 50]), ...array_fill(0, 9, null)],
            'respins_left' => 3,
            'during_free_games' => false,
            'profile' => 1,
            'major_pending' => true,
            'minis_pending' => 0,
        ];

        $outcome = $game->play($state);

        $this->assertSame(['type' => 'major', 'amount' => 0], $outcome['cells'][$outcome['landed'][0]]);
        $this->assertFalse($state->baleBonus['major_pending']);
    }

    public function test_pending_minis_land_until_they_run_out(): void
    {
        $game = $this->game(function (array $config) {
            $config = $this->allReels($config, 'nine', baleBonusChance: 0.0);
            $config['bale_bonus']['landing_chances'] = [7 => 1.0, 8 => 1.0, 9 => 1.0, 10 => 0.0];
            $config['bale_bonus']['pending_landing_chance'] = 1.0;
            $config['bale_bonus']['value_profiles'] = [1 => [1 => 1]];
            $config['bale_bonus']['profile_jackpots'] = [];

            return $config;
        });
        $state = $game->newState();
        $state->baleBonus = [
            'cells' => [...array_fill(0, 6, ['type' => 'credits', 'amount' => 50]), ...array_fill(0, 9, null)],
            'respins_left' => 3,
            'during_free_games' => false,
            'profile' => 1,
            'major_pending' => false,
            'minis_pending' => 2,
        ];

        $outcome = $game->play($state);
        $types = array_map(fn (int $index): string => $outcome['cells'][$index]['type'], $outcome['landed']);

        $counts = array_count_values($types);
        ksort($counts);

        $this->assertSame(['credits' => 1, 'mini' => 2], $counts);
        $this->assertSame(0, $state->baleBonus['minis_pending']);
    }

    public function test_higher_bets_get_more_minis(): void
    {
        $game = $this->game();
        $state = $game->newState();

        $count = function (int $creditsPerLine) use ($game, $state): int {
            $state->creditsPerLine = $creditsPerLine;

            return array_sum(array_map(fn () => $game->rollMinis($state), range(1, 4000)));
        };

        $low = $count(1);
        $high = $count(10);

        $this->assertGreaterThan($low * 5, $high);
        $this->assertLessThanOrEqual(7, max(array_map(fn () => $game->rollMinis($state), range(1, 2000))));
    }

    public function test_credit_bale_values_are_scaled_down_at_higher_bets(): void
    {
        $game = $this->game(function (array $config) {
            $config = $this->allReels($config, 'bale', baleBonusChance: 0.0);
            $config['bale_values']['base'] = [['multiplier' => 2, 'weight' => 1]];
            $config['credit_value_scale'][10] = 0.5;

            return $config;
        });
        $state = $game->newState();
        $state->creditsPerLine = 10;

        $outcome = $game->play($state);

        $this->assertSame(500, $outcome['bales'][0]['amount']);
    }

    public function test_free_games_doors_all_reveal_the_same_symbol(): void
    {
        $game = $this->game(fn (array $config) => $this->allReels($config, 'door', baleBonusChance: 0.0));
        $state = $game->newState();
        $state->freeGames = ['remaining' => 3, 'played' => 3, 'total' => 6, 'win' => 0];

        $outcome = $game->play($state);

        $this->assertCount(15, $outcome['doors']['positions']);
        $this->assertSame([$outcome['doors']['symbol']], array_values(array_unique(array_merge(...$outcome['grid']))));
        $this->assertNotContains('door', array_merge(...$state->grid));
    }

    public function test_doors_can_reveal_wilds(): void
    {
        $game = $this->game(function (array $config) {
            $config = $this->allReels($config, 'door', baleBonusChance: 0.0);
            $config['door_reveals'] = ['farmer' => 1];

            return $config;
        });
        $state = $game->newState();
        $state->freeGames = ['remaining' => 3, 'played' => 3, 'total' => 6, 'win' => 0];

        $outcome = $game->play($state);

        $this->assertSame('farmer', $outcome['doors']['symbol']);
        $this->assertSame(['farmer'], array_values(array_unique(array_merge(...$outcome['grid']))));
    }

    public function test_doors_revealing_bales_can_trigger_bale_bonus_during_free_games(): void
    {
        $game = $this->game(function (array $config) {
            $config = $this->allReels($config, 'door', baleBonusChance: 0.0);
            $config['door_reveals'] = ['bale' => 1];

            return $config;
        });
        $state = $game->newState();
        $state->freeGames = ['remaining' => 3, 'played' => 3, 'total' => 6, 'win' => 0];

        $outcome = $game->play($state);

        $this->assertTrue($outcome['triggered']['bale_bonus']);
        $this->assertCount(15, $outcome['bales']);
        $this->assertTrue($state->baleBonus['during_free_games']);
    }

    public function test_base_games_have_no_doors_and_report_none(): void
    {
        $outcome = $this->game()->play($this->game()->newState());

        $this->assertNull($outcome['doors']);
        $this->assertNotContains('door', array_merge(...$outcome['grid']));
    }

    public function test_only_one_ultra_high_prize_lands_per_screen(): void
    {
        $game = $this->game(function (array $config) {
            $config = $this->allReels($config, 'bale', baleBonusChance: 0.0);
            $config['bale_values']['base'] = [['multiplier' => 250, 'weight' => 1000], ['jackpot' => 'major', 'weight' => 1000], ['multiplier' => 1, 'weight' => 1]];

            return $config;
        });
        $state = $game->newState();

        $outcome = $game->play($state);
        $capped = array_filter($outcome['bales'], fn (array $bale) => $bale['type'] === 'major' || $bale['amount'] >= 100 * 50);

        $this->assertCount(15, $outcome['bales']);
        $this->assertCount(1, $capped);
    }

    public function test_no_further_ultra_high_prize_lands_once_one_is_held(): void
    {
        $game = $this->game(function (array $config) {
            $config = $this->allReels($config, 'nine', baleBonusChance: 1.0);
            $config['bale_bonus']['value_profiles'] = [4 => [100 => 1000, 2 => 1]];
            $config['bale_bonus']['profile_jackpots'] = [['jackpot' => 'major', 'weight' => 1000]];

            return $config;
        });
        $state = $game->newState();
        $state->baleBonus = [
            'cells' => [['type' => 'credits', 'amount' => 250 * 50], ...array_fill(0, 14, null)],
            'respins_left' => 3,
            'during_free_games' => false,
            'profile' => 4,
        ];

        $outcome = $game->play($state);

        foreach ($outcome['landed'] as $index) {
            $this->assertSame(['type' => 'credits', 'amount' => 100], $outcome['cells'][$index]);
        }
        $this->assertCount(14, $outcome['landed']);
    }

    public function test_respins_land_bales_by_their_nth_bale_chance(): void
    {
        $game = $this->game(function (array $config) {
            $config = $this->allReels($config, 'nine', baleBonusChance: 0.0);
            $config['bale_bonus']['landing_chances'] = [7 => 1.0, 8 => 1.0, 9 => 0.0];

            return $config;
        });
        $state = $game->newState();
        $state->baleBonus = [
            'cells' => [...array_fill(0, 6, ['type' => 'credits', 'amount' => 50]), ...array_fill(0, 9, null)],
            'respins_left' => 1,
            'during_free_games' => false,
            'profile' => 1,
        ];

        $outcome = $game->play($state);

        $this->assertCount(2, $outcome['landed']);
        $this->assertSame(3, $outcome['respins_left']);
        $this->assertSame(1, $outcome['profile']);

        $game->play($state);
        $game->play($state);
        $final = $game->play($state);

        $this->assertTrue($final['finished']);
        $this->assertCount(8, array_filter($final['cells']));
    }

    public function test_low_tier_bale_bonus_only_lands_low_values(): void
    {
        $game = $this->game(fn (array $config) => $this->allReels($config, 'nine', baleBonusChance: 1.0));
        $state = $game->newState();
        $state->baleBonus = [
            'cells' => [['type' => 'credits', 'amount' => 50], ...array_fill(0, 14, null)],
            'respins_left' => 3,
            'during_free_games' => false,
            'profile' => 1,
        ];

        $outcome = $game->play($state);

        foreach ($outcome['landed'] as $index) {
            $cell = $outcome['cells'][$index];
            $this->assertTrue($cell['type'] !== 'credits' || $cell['amount'] <= 5 * 50, 'Tier 1 landed '.json_encode($cell));
        }
    }

    public function test_high_triggering_bales_favour_high_value_tiers(): void
    {
        $game = $this->game();
        $low = array_fill(0, 6, ['type' => 'credits', 'amount' => 50]);
        $high = array_fill(0, 6, ['type' => 'credits', 'amount' => 10 * 50]);

        $lowWeights = $game->valueProfileWeights($low, 50);
        $highWeights = $game->valueProfileWeights($high, 50);

        $this->assertGreaterThan($lowWeights[7], $highWeights[7]);
        $this->assertGreaterThan($highWeights[1], $lowWeights[1]);
        $this->assertSame(30.0, $lowWeights[1]);

        $chosen = array_count_values(array_map(fn () => $game->chooseValueProfile($high, 50), range(1, 2000)));
        $this->assertGreaterThan(($chosen[1] ?? 0) + ($chosen[2] ?? 0), ($chosen[6] ?? 0) + ($chosen[7] ?? 0));
    }

    public function test_major_bales_are_paid_from_the_progressive_when_collected(): void
    {
        $config = $this->allReels($this->config(), 'nine', baleBonusChance: 0.0);
        $jackpots = new InMemoryJackpotBank($config['jackpots']);
        $game = HayLinkGame::fromConfig($config, $jackpots, new Randomizer(new Mt19937(7)));
        $state = $game->newState();
        $state->baleBonus = [
            'cells' => [['type' => JackpotTier::Major->value, 'amount' => 0], ...array_fill(0, 14, null)],
            'respins_left' => 1,
            'during_free_games' => false,
        ];

        $outcome = $game->play($state);

        $this->assertSame($config['jackpots']['major']['seed'], $outcome['cells'][0]['amount']);
        $this->assertSame($config['jackpots']['major']['seed'], $outcome['win']);
    }

    public function test_three_moons_award_free_games_that_are_played_without_a_bet(): void
    {
        $game = $this->game(fn (array $config) => $this->allReels($config, 'moon', baleBonusChance: 0.0));
        $state = $game->newState();

        $outcome = $game->play($state);

        $this->assertSame(6, $outcome['triggered']['free_games']);
        $this->assertSame(6, $state->freeGames['remaining']);

        $creditsBefore = $state->credits;
        $freeOutcome = $game->play($state);

        $this->assertSame('free', $freeOutcome['mode']);
        $this->assertSame($creditsBefore + $freeOutcome['win'], $state->credits);
        $this->assertSame(6, $freeOutcome['triggered']['free_games']);
        $this->assertSame(11, $state->freeGames['remaining']);
        $this->assertSame(12, $state->freeGames['total']);
    }

    public function test_free_games_complete_after_the_last_one_is_played(): void
    {
        $game = $this->game(fn (array $config) => $this->allReels($config, 'nine', baleBonusChance: 0.0));
        $state = $game->newState();
        $state->freeGames = ['remaining' => 1, 'played' => 5, 'total' => 6, 'win' => 500];

        $outcome = $game->play($state);

        $this->assertSame(500 + $outcome['win'], $outcome['free_games_completed']);
        $this->assertNull($state->freeGames);
        $this->assertFalse($state->isInFeature());
    }

    /**
     * @return array<string, mixed>
     */
    private function config(): array
    {
        return require __DIR__.'/../../../config/hay_link.php';
    }

    /**
     * @param  (callable(array<string, mixed>): array<string, mixed>)|null  $configure
     */
    private function game(?callable $configure = null): HayLinkGame
    {
        $config = $this->config();

        if ($configure !== null) {
            $config = $configure($config);
        }

        return HayLinkGame::fromConfig($config, new InMemoryJackpotBank($config['jackpots']), new Randomizer(new Mt19937(42)));
    }

    /**
     * Replace every reel strip with a single symbol so outcomes are certain.
     *
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    private function allReels(array $config, string $symbol, float $baleBonusChance): array
    {
        $config['reels']['base'] = array_fill(0, 5, ['symbols' => [$symbol => 10]]);
        $config['reels']['free'] = array_fill(0, 5, ['symbols' => [$symbol => 10]]);
        $config['bale_bonus']['landing_chances'] = array_fill(1, 15, $baleBonusChance);

        return $config;
    }
}
