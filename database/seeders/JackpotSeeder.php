<?php

namespace Database\Seeders;

use App\Models\Jackpot;
use Illuminate\Database\Seeder;

class JackpotSeeder extends Seeder
{
    /**
     * Reset the progressive jackpots to their seed values.
     */
    public function run(): void
    {
        foreach (['major', 'grand'] as $tier) {
            Jackpot::query()->updateOrCreate(
                ['tier' => $tier],
                ['value' => config("hay_link.jackpots.{$tier}.seed")],
            );
        }
    }
}
