<?php

namespace App\Games\HayLink;

enum Symbol: string
{
    case Farmer = 'farmer';
    case Moon = 'moon';
    case Tractor = 'tractor';
    case Barn = 'barn';
    case Dog = 'dog';
    case MilkBottle = 'milk_bottle';
    case King = 'king';
    case Queen = 'queen';
    case Jack = 'jack';
    case Ten = 'ten';
    case Nine = 'nine';
    case Bale = 'bale';
    case Door = 'door';

    /**
     * The Farmer substitutes for every symbol except the Moon scatter and bales.
     */
    public function isWild(): bool
    {
        return $this === self::Farmer;
    }

    public function isScatter(): bool
    {
        return $this === self::Moon;
    }

    public function isBale(): bool
    {
        return $this === self::Bale;
    }

    /**
     * Free games doors land closed and are all revealed as one symbol before the spin is evaluated.
     */
    public function isDoor(): bool
    {
        return $this === self::Door;
    }

    /**
     * Whether a wild may stand in for this symbol on a payline.
     */
    public function isSubstitutable(): bool
    {
        return ! $this->isScatter() && ! $this->isBale();
    }
}
