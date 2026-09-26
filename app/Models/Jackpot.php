<?php

namespace App\Models;

use Database\Factories\JackpotFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A shared progressive jackpot (Major or Grand). Values are in cents.
 */
#[Fillable(['tier', 'value'])]
class Jackpot extends Model
{
    /** @use HasFactory<JackpotFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'value' => 'float',
        ];
    }
}
