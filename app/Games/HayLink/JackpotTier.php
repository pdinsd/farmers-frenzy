<?php

namespace App\Games\HayLink;

enum JackpotTier: string
{
    case Mini = 'mini';
    case Minor = 'minor';
    case Major = 'major';
    case Grand = 'grand';

    /**
     * Major and Grand are shared progressives; Mini and Minor are fixed multiples of the bet.
     */
    public function isProgressive(): bool
    {
        return $this === self::Major || $this === self::Grand;
    }
}
