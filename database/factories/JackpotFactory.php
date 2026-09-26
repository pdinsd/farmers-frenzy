<?php

namespace Database\Factories;

use App\Models\Jackpot;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Jackpot>
 */
class JackpotFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tier' => 'major',
            'value' => config('hay_link.jackpots.major.seed'),
        ];
    }

    /**
     * Indicate that the jackpot is the Grand.
     */
    public function grand(): static
    {
        return $this->state(fn (array $attributes) => [
            'tier' => 'grand',
            'value' => config('hay_link.jackpots.grand.seed'),
        ]);
    }
}
