<?php

namespace Tests\Feature;

use App\Http\Controllers\AttendantController;
use App\Http\Middleware\EnsureAttendantUnlocked;
use App\Models\Jackpot;
use App\Models\MachineMeter;
use App\Models\MachineSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class AttendantControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_panel_is_locked_until_the_pin_is_entered(): void
    {
        $this->get(route('attendant.panel'))->assertOk()->assertSee('LOCKED')->assertDontSee('Save settings');

        $this->putJson(route('attendant.settings'), $this->validSettings())->assertForbidden();

        $this->postJson(route('attendant.unlock'), ['pin' => '0000'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['pin' => 'Incorrect attendant PIN.']);

        $this->postJson(route('attendant.unlock'), ['pin' => config('hay_link.attendant.pin')])->assertOk();

        $this->get(route('attendant.panel'))->assertOk()->assertSee('UNLOCKED')->assertSee('Save settings');

        $this->postJson(route('attendant.lock'))->assertOk();
        $this->putJson(route('attendant.settings'), $this->validSettings())->assertForbidden();
    }

    public function test_saved_settings_change_the_machine(): void
    {
        $this->unlocked()
            ->putJson(route('attendant.settings'), $this->validSettings([
                'rtp_program' => 90,
                'denominations_enabled' => [5, 25],
                'max_credits_per_line' => 3,
                'max_deposit_dollars' => 250,
                'autoplay_enabled' => false,
            ]))
            ->assertOk()
            ->assertJsonPath('settings.rtp_program', 90)
            ->assertJsonPath('settings.max_deposit_cents', 25000);

        $this->assertSame(90, MachineSetting::query()->sole()->settings['rtp_program']);

        $this->getJson(route('machine.config'))
            ->assertOk()
            ->assertJsonPath('game.creditsPerLineOptions', [1, 2, 3])
            ->assertJsonPath('game.autoplayEnabled', false)
            ->assertJsonPath('game.maxDepositCents', 25000)
            ->assertJsonPath('game.state.denomination', 5)
            ->assertJsonPath('game.state.credits', 2000);

        $this->assertSame(['5', '25'], array_map('strval', array_keys($this->getJson(route('machine.config'))->json('game.denominations'))));

        $this->postJson(route('machine.denomination'), ['denomination' => 1])->assertJsonValidationErrors('denomination');
        $this->postJson(route('machine.play'), ['credits_per_line' => 10])->assertJsonValidationErrors('credits_per_line');
        $this->postJson(route('machine.reset'), ['deposit' => 300])->assertJsonValidationErrors(['deposit' => 'Deposits are limited to $250.00.']);
    }

    public function test_settings_are_validated(): void
    {
        $this->unlocked()
            ->putJson(route('attendant.settings'), $this->validSettings([
                'rtp_program' => 99,
                'denominations_enabled' => [],
                'grand_seed_dollars' => 100,
                'major_seed_dollars' => 500,
            ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['rtp_program', 'denominations_enabled', 'grand_seed_dollars']);
    }

    public function test_a_progressive_can_be_reset_to_its_new_reset_value(): void
    {
        Jackpot::factory()->create(['tier' => 'major', 'value' => 61_234.5]);
        Jackpot::factory()->grand()->create();

        $this->unlocked()->putJson(route('attendant.settings'), $this->validSettings(['major_seed_dollars' => 750]))->assertOk();

        $this->postJson(route('attendant.progressives.reset', 'major'))
            ->assertOk()
            ->assertJsonPath('progressives.major', 75000);

        $this->postJson(route('attendant.progressives.reset', 'mini'))->assertNotFound();
    }

    public function test_meters_record_play_and_deposits_and_can_be_cleared(): void
    {
        $this->postJson(route('machine.reset'), ['deposit' => 40])->assertOk();
        $win = $this->postJson(route('machine.play'), ['credits_per_line' => 2])->json('outcome.win');

        $meters = MachineMeter::query()->sole();
        $this->assertSame(100, $meters->coin_in_cents);
        $this->assertSame($win, $meters->coin_out_cents);
        $this->assertSame(1, $meters->games_played);
        $this->assertSame(4000, $meters->deposits_cents);

        $this->unlocked()->postJson(route('attendant.meters.clear'))->assertOk()->assertJsonPath('meters.games_played', 0);
        $this->assertNotNull(MachineMeter::query()->sole()->cleared_at);
    }

    public function test_a_simulation_is_stored_and_shown_on_the_par_sheet(): void
    {
        $this->get(route('machine.par-sheet'))->assertOk()->assertSee('PAR Sheet')->assertSee('No simulation yet');

        $this->unlocked()
            ->postJson(route('attendant.simulate'), ['spins' => 10000, 'denomination' => 1, 'credits_per_line' => 1])
            ->assertOk()
            ->assertJsonPath('simulation.spins', 10000)
            ->assertJsonPath('simulation.rtp_program', 94);

        $this->assertNotNull(Cache::get(AttendantController::SIMULATION_CACHE_KEY));

        $this->get(route('machine.par-sheet'))->assertOk()->assertSee('Measured RTP')->assertDontSee('different settings');

        $this->putJson(route('attendant.settings'), $this->validSettings(['rtp_program' => 96]))->assertOk();
        $this->get(route('machine.par-sheet'))->assertOk()->assertSee('different settings');
    }

    public function test_simulations_require_the_attendant(): void
    {
        $this->postJson(route('attendant.simulate'), ['spins' => 10000, 'denomination' => 1, 'credits_per_line' => 1])->assertForbidden();
    }

    private function unlocked(): static
    {
        return $this->withSession([EnsureAttendantUnlocked::SESSION_KEY => true]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function validSettings(array $overrides = []): array
    {
        return [
            'rtp_program' => 94,
            'denominations_enabled' => [1, 2, 5, 10, 25, 50, 100],
            'max_credits_per_line' => 10,
            'major_seed_dollars' => 500,
            'major_cap_dollars' => 1000,
            'grand_seed_dollars' => 10000,
            'major_contribution_percent' => 0.5,
            'grand_contribution_percent' => 0.25,
            'jackpot_max_bet_boost' => 1.6,
            'free_games_awarded' => 6,
            'grand_replaces_values' => true,
            'max_deposit_dollars' => 100000,
            'autoplay_enabled' => true,
            ...$overrides,
        ];
    }
}
