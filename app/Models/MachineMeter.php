<?php

namespace App\Models;

use Database\Factories\MachineMeterFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * The machine's accounting meters (a single row), as an attendant would read them.
 */
#[Fillable([
    'coin_in_cents',
    'coin_out_cents',
    'line_wins_cents',
    'scatter_wins_cents',
    'free_games_wins_cents',
    'bale_bonus_wins_cents',
    'games_played',
    'winning_games',
    'free_games_triggered',
    'bale_bonus_triggered',
    'mini_hits',
    'minor_hits',
    'major_hits',
    'grand_hits',
    'progressives_paid_cents',
    'deposits_cents',
    'cleared_at',
])]
class MachineMeter extends Model
{
    /** @use HasFactory<MachineMeterFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'coin_in_cents' => 'integer',
            'coin_out_cents' => 'integer',
            'winning_games' => 'integer',
            'line_wins_cents' => 'integer',
            'scatter_wins_cents' => 'integer',
            'free_games_wins_cents' => 'integer',
            'bale_bonus_wins_cents' => 'integer',
            'mini_hits' => 'integer',
            'minor_hits' => 'integer',
            'games_played' => 'integer',
            'free_games_triggered' => 'integer',
            'bale_bonus_triggered' => 'integer',
            'major_hits' => 'integer',
            'grand_hits' => 'integer',
            'progressives_paid_cents' => 'integer',
            'deposits_cents' => 'integer',
            'cleared_at' => 'datetime',
        ];
    }

    /**
     * Actual return to player so far, as a percentage of coin in.
     */
    public function actualRtp(): ?float
    {
        return $this->coin_in_cents > 0 ? $this->coin_out_cents / $this->coin_in_cents * 100 : null;
    }
}
