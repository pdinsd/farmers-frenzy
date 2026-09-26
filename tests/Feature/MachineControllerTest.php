<?php

namespace Tests\Feature;

use App\Models\Jackpot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MachineControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_machine_page_renders(): void
    {
        $this->withoutVite();

        $this->get(route('machine.show'))
            ->assertOk()
            ->assertSee('id="machine"', false)
            ->assertSee('images/farmers-frenzy/logo.png');
    }

    public function test_a_spin_deducts_the_bet_and_remembers_the_machine_state(): void
    {
        $response = $this->postJson(route('machine.play'), ['credits_per_line' => 2]);

        $response->assertOk()
            ->assertJsonPath('outcome.mode', 'base')
            ->assertJsonPath('state.total_bet', 100)
            ->assertJsonStructure(['outcome' => ['grid', 'line_wins', 'scatter', 'bales', 'win', 'triggered'], 'state' => ['credits', 'jackpots']]);

        $win = $response->json('outcome.win');
        $this->assertSame(10000 - 100 + $win, $response->json('state.credits'));

        $this->getJson(route('machine.state'))->assertJsonPath('state.credits', 10000 - 100 + $win);
    }

    public function test_a_spin_contributes_to_the_progressive_jackpots(): void
    {
        $this->postJson(route('machine.play'), ['credits_per_line' => 10])->assertOk();

        $this->assertEqualsWithDelta(
            config('hay_link.jackpots.grand.seed') + 500 * config('hay_link.jackpots.grand.contribution'),
            Jackpot::query()->where('tier', 'grand')->value('value'),
            0.0001,
        );
    }

    public function test_an_unsupported_bet_is_rejected(): void
    {
        $this->postJson(route('machine.play'), ['credits_per_line' => 7])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('credits_per_line');
    }

    public function test_a_spin_without_enough_credits_is_rejected(): void
    {
        $this->withSession(['farmers_frenzy.state' => ['credits' => 10, 'credits_per_line' => 1]])
            ->postJson(route('machine.play'))
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Insufficient credits for this bet.');
    }

    public function test_the_bet_cannot_change_during_a_feature(): void
    {
        $this->withSession(['farmers_frenzy.state' => [
            'credits' => 5000,
            'credits_per_line' => 1,
            'free_games' => ['remaining' => 3, 'played' => 3, 'total' => 6, 'win' => 0],
        ]])
            ->postJson(route('machine.play'), ['credits_per_line' => 10])
            ->assertOk()
            ->assertJsonPath('outcome.mode', 'free')
            ->assertJsonPath('state.total_bet', 50);
    }

    public function test_changing_denomination_converts_the_balance_and_plays_fewer_lines(): void
    {
        $this->postJson(route('machine.denomination'), ['denomination' => 25])
            ->assertOk()
            ->assertJsonPath('state.denomination', 25)
            ->assertJsonPath('state.credits', 400)
            ->assertJsonPath('state.lines', 25)
            ->assertJsonPath('state.total_bet', 25);

        $this->postJson(route('machine.denomination'), ['denomination' => 100])
            ->assertOk()
            ->assertJsonPath('state.credits', 100)
            ->assertJsonPath('state.lines', 5)
            ->assertJsonPath('state.jackpots.major', 500);
    }

    public function test_leftover_cents_are_kept_when_changing_denomination(): void
    {
        $this->withSession(['farmers_frenzy.state' => ['credits' => 1037, 'credits_per_line' => 1]])
            ->postJson(route('machine.denomination'), ['denomination' => 25])
            ->assertJsonPath('state.credits', 41);

        $this->postJson(route('machine.denomination'), ['denomination' => 1])
            ->assertJsonPath('state.credits', 1037);
    }

    public function test_the_denomination_cannot_change_during_a_feature(): void
    {
        $this->withSession(['farmers_frenzy.state' => [
            'credits' => 5000,
            'credits_per_line' => 1,
            'free_games' => ['remaining' => 3, 'played' => 3, 'total' => 6, 'win' => 0],
        ]])
            ->postJson(route('machine.denomination'), ['denomination' => 100])
            ->assertOk()
            ->assertJsonPath('state.denomination', 1)
            ->assertJsonPath('state.credits', 5000);
    }

    public function test_an_unsupported_denomination_is_rejected(): void
    {
        $this->postJson(route('machine.denomination'), ['denomination' => 3])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('denomination');
    }

    public function test_memory_reset_starts_again_with_the_deposited_money(): void
    {
        $this->withSession(['farmers_frenzy.state' => [
            'credits' => 10,
            'credits_per_line' => 5,
            'free_games' => ['remaining' => 3, 'played' => 3, 'total' => 6, 'win' => 400],
        ]])
            ->postJson(route('machine.reset'), ['deposit' => 250])
            ->assertOk()
            ->assertJsonPath('state.credits', 25000)
            ->assertJsonPath('state.credits_per_line', 1)
            ->assertJsonPath('state.last_win', 0)
            ->assertJsonPath('state.free_games', null)
            ->assertJsonPath('state.bale_bonus', null);
    }

    public function test_memory_reset_keeps_the_current_denomination(): void
    {
        $this->withSession(['farmers_frenzy.state' => ['credits' => 10, 'credits_per_line' => 1, 'denomination' => 25]])
            ->postJson(route('machine.reset'), ['deposit' => '50.10'])
            ->assertOk()
            ->assertJsonPath('state.denomination', 25)
            ->assertJsonPath('state.credits', 200);

        $this->postJson(route('machine.denomination'), ['denomination' => 1])
            ->assertJsonPath('state.credits', 5010);
    }

    public function test_memory_reset_requires_a_valid_deposit(): void
    {
        $this->postJson(route('machine.reset'))->assertJsonValidationErrors('deposit');
        $this->postJson(route('machine.reset'), ['deposit' => 0.5])->assertJsonValidationErrors(['deposit' => 'Deposit at least $1.00.']);
        $this->postJson(route('machine.reset'), ['deposit' => 100001])->assertJsonValidationErrors('deposit');
        $this->postJson(route('machine.reset'), ['deposit' => 12.345])->assertJsonValidationErrors('deposit');
        $this->postJson(route('machine.reset'), ['deposit' => 'lots'])->assertJsonValidationErrors('deposit');
    }
}
