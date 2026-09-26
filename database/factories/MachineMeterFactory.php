<?php

namespace Database\Factories;

use App\Models\MachineMeter;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MachineMeter>
 */
class MachineMeterFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'coin_in_cents' => fake()->numberBetween(100_000, 1_000_000),
            'coin_out_cents' => fake()->numberBetween(80_000, 950_000),
            'games_played' => fake()->numberBetween(1_000, 10_000),
            'free_games_triggered' => fake()->numberBetween(5, 60),
            'bale_bonus_triggered' => fake()->numberBetween(8, 90),
            'major_hits' => 0,
            'grand_hits' => 0,
            'progressives_paid_cents' => 0,
            'deposits_cents' => fake()->numberBetween(10_000, 500_000),
        ];
    }
}
