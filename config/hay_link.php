<?php

/*
|--------------------------------------------------------------------------
| Farmers Frenzy (Hay Link) game math
|--------------------------------------------------------------------------
|
| Game amounts are in credits of the selected denomination (cents per
| credit). Progressive jackpots and the starting balance are in cents.
| Line pays are multiples of the line bet (credits per line); scatter pays
| and bale values are multiples of the total bet.
|
*/

$reelSymbols = [
    'tractor' => 4, 'barn' => 5, 'dog' => 5, 'milk_bottle' => 5,
    'king' => 6, 'queen' => 6, 'jack' => 7, 'ten' => 9, 'nine' => 9,
    'moon' => 2, 'bale' => 2,
];

/*
 * Free games reels carry more bales and stacked doors (see `reels.free`),
 * so Bale Bonus triggers in about half of all free games features.
 */
$freeReelSymbols = [...$reelSymbols, 'bale' => 19];

/*
 * Credit bales, as multiples of the total bet. The common 1x-5x balls
 * land far more often than the rare 50x, 100x and 250x balls.
 */
$creditBales = [
    ['multiplier' => 1, 'weight' => 3600],
    ['multiplier' => 2, 'weight' => 2800],
    ['multiplier' => 3, 'weight' => 1500],
    ['multiplier' => 4, 'weight' => 900],
    ['multiplier' => 5, 'weight' => 800],
    ['multiplier' => 10, 'weight' => 380],
    ['multiplier' => 15, 'weight' => 160],
    ['multiplier' => 20, 'weight' => 70],
    ['multiplier' => 25, 'weight' => 50],
    ['multiplier' => 50, 'weight' => 20],
    ['multiplier' => 100, 'weight' => 5],
    ['multiplier' => 250, 'weight' => 1],
];

return [

    /*
     * Denominations in cents per credit, and how many paylines each one plays.
     * Higher denominations play fewer lines. Lines are taken from the start of
     * the `paylines` list below.
     */
    'denominations' => [1 => 50, 2 => 50, 5 => 50, 10 => 50, 25 => 25, 50 => 10, 100 => 5],

    /*
     * Starting balance in cents ($100.00).
     */
    'starting_balance' => 10000,

    'credits_per_line_options' => [1, 2, 3, 5, 10],

    /*
     * Rows are 0 = top, 1 = middle, 2 = bottom, one entry per reel.
     */
    'paylines' => [
        [1, 1, 1, 1, 1], [0, 0, 0, 0, 0], [2, 2, 2, 2, 2], [0, 1, 2, 1, 0], [2, 1, 0, 1, 2],
        [0, 0, 1, 2, 2], [2, 2, 1, 0, 0], [1, 0, 0, 0, 1], [1, 2, 2, 2, 1], [1, 0, 1, 2, 1],
        [1, 2, 1, 0, 1], [0, 1, 0, 1, 0], [2, 1, 2, 1, 2], [0, 1, 1, 1, 0], [2, 1, 1, 1, 2],
        [1, 1, 0, 1, 1], [1, 1, 2, 1, 1], [0, 0, 1, 0, 0], [2, 2, 1, 2, 2], [0, 2, 0, 2, 0],
        [2, 0, 2, 0, 2], [1, 0, 2, 0, 1], [1, 2, 0, 2, 1], [0, 0, 2, 0, 0], [2, 2, 0, 2, 2],
        [0, 2, 2, 2, 0], [2, 0, 0, 0, 2], [0, 1, 2, 2, 2], [2, 1, 0, 0, 0], [0, 0, 0, 1, 2],
        [2, 2, 2, 1, 0], [1, 0, 0, 1, 2], [1, 2, 2, 1, 0], [0, 0, 1, 1, 1], [2, 2, 1, 1, 1],
        [1, 0, 1, 0, 1], [1, 2, 1, 2, 1], [0, 1, 1, 1, 1], [2, 1, 1, 1, 1], [1, 1, 1, 1, 0],
        [1, 1, 1, 1, 2], [0, 0, 0, 1, 1], [2, 2, 2, 1, 1], [1, 0, 0, 0, 0], [1, 2, 2, 2, 2],
        [0, 1, 0, 0, 0], [2, 1, 2, 2, 2], [0, 0, 0, 0, 1], [2, 2, 2, 2, 1], [1, 0, 1, 1, 1],
    ],

    /*
     * Multiples of the line bet for 3, 4 and 5 of a kind, left to right.
     */
    'paytable' => [
        'tractor' => [3 => 17, 4 => 58, 5 => 175],
        'barn' => [3 => 14, 4 => 42, 5 => 125],
        'dog' => [3 => 11, 4 => 35, 5 => 112],
        'milk_bottle' => [3 => 10, 4 => 28, 5 => 87],
        'king' => [3 => 6, 4 => 17, 5 => 58],
        'queen' => [3 => 4, 4 => 11, 5 => 42],
        'jack' => [3 => 3, 4 => 8, 5 => 42],
        'ten' => [3 => 3, 4 => 7, 5 => 33],
        'nine' => [3 => 2, 4 => 7, 5 => 33],
    ],

    /*
     * Cowgirl scatter pays, multiples of the total bet, for this many scatters anywhere.
     */
    'scatter_pays' => [3 => 2, 4 => 10, 5 => 50],

    'free_games' => [
        'trigger_count' => 3,
        'awarded' => 6,
        'retrigger_awarded' => 6,
    ],

    'bale_bonus' => [
        'trigger_count' => 6,
        'respins' => 3,
        /*
         * On each respin the next bale (the 7th, 8th, ... 15th on screen)
         * lands with this chance, in a random empty window. When it lands the
         * one after it rolls too, so several can land on one respin. Landing any
         * resets the respins, so every window effectively gets up to three tries:
         * the 15th (which wins the Grand) must be very rare. Counts below the
         * lowest key use the lowest key's chance.
         */
        'landing_chances' => [
            7 => 0.88,
            8 => 0.82,
            9 => 0.75,
            10 => 0.65,
            11 => 0.52,
            12 => 0.37,
            13 => 0.22,
            14 => 0.08,
            15 => 0.00005,
        ],
        /*
         * Ultra-high prizes: credit bales worth at least this multiple of the
         * bet, and Major bales. Only one may land per screen / Bale Bonus.
         */
        'capped_min_multiplier' => 100,
        /*
         * Filling all 15 positions pays the Grand in place of the individual
         * bale values. Set to false to pay the Grand on top of them.
         */
        'grand_replaces_values' => true,
        /*
         * Value tiers for bales that land during Bale Bonus. One tier is
         * chosen when the feature starts and every new bale draws from it:
         * tier 1 holds the lowest values (1x-3x), tier 4 the median mix and
         * tier 7 mostly medium and high values with some low ones. Weights are
         * keyed by multiple of the total bet.
         */
        'value_profiles' => [
            1 => [1 => 50, 2 => 32, 3 => 15, 4 => 2, 5 => 1],
            2 => [1 => 40, 2 => 30, 3 => 18, 4 => 7, 5 => 4, 10 => 1],
            3 => [1 => 30, 2 => 28, 3 => 20, 4 => 10, 5 => 8, 10 => 3, 15 => 1],
            4 => [1 => 22, 2 => 24, 3 => 20, 4 => 12, 5 => 10, 10 => 7, 15 => 3, 20 => 1.5, 25 => 0.5],
            5 => [1 => 15, 2 => 18, 3 => 17, 4 => 13, 5 => 12, 10 => 11, 15 => 7, 20 => 4, 25 => 2, 50 => 1],
            6 => [1 => 12, 2 => 14, 3 => 14, 4 => 13, 5 => 13, 10 => 13, 15 => 9, 20 => 6, 25 => 4, 50 => 1.2, 100 => 0.25, 250 => 0.05],
            7 => [1 => 9, 2 => 10, 3 => 11, 4 => 10, 5 => 12, 10 => 15, 15 => 13, 20 => 9, 25 => 7, 50 => 2.8, 100 => 0.8, 250 => 0.2],
        ],
        /*
         * Jackpot bales possible in every tier, weighted on the same scale
         * as the tier weights above (which each total about 100).
         */
        'profile_jackpots' => [
            ['jackpot' => 'minor', 'weight' => 0.3],
        ],
        /*
         * Whether a Bale Bonus will contain the Major is decided when it starts.
         * The chance is proportional to the bet in cents (so the Major is the same
         * share of RTP at every bet and denomination) and climbs as the Major
         * grows: at its cap it is `at_cap_multiplier` times the chance at its seed.
         */
        'major_chance' => [
            'per_bet_cent' => 0.000045,
            'at_cap_multiplier' => 6.0,
        ],
        /*
         * How many Minis a Bale Bonus will contain is also decided when it starts,
         * by bet level (credits per line): `chance` of at least one, then each
         * `extra_chance` adds another, up to `max`.
         */
        'minis' => [
            'chance' => [1 => 0.075, 2 => 0.2, 3 => 0.3, 5 => 0.5, 10 => 0.75],
            'extra_chance' => [1 => 0.15, 2 => 0.25, 3 => 0.3, 5 => 0.4, 10 => 0.5],
            'max' => 7,
        ],
        /*
         * A pending Major or Mini goes onto each newly landed bale with this
         * chance, so they turn up at random points in the feature.
         */
        'pending_landing_chance' => 0.5,
        /*
         * How the tier is chosen. High balls drop more high balls: the weights
         * slide from `cold` to `hot` as the average value of the triggering
         * bales rises from `cold_average` to `hot_average` times the bet
         * (Mini, Minor and Major bales count as `jackpot_average`).
         */
        'profile_selection' => [
            'cold' => [1 => 30, 2 => 25, 3 => 18, 4 => 12, 5 => 8, 6 => 5, 7 => 2],
            'hot' => [1 => 4, 2 => 6, 3 => 10, 4 => 16, 5 => 20, 6 => 22, 7 => 22],
            'cold_average' => 3.0,
            'hot_average' => 12.0,
            'jackpot_average' => 20.0,
        ],
    ],

    /*
     * Mini and Minor are multiples of the total bet. Major and Grand are shared
     * progressives: `seed` is in cents and `contribution` is the share of every
     * wager (in cents) added to the pool, whatever the denomination.
     */
    'jackpots' => [
        'mini' => ['bet_multiplier' => 20],
        'minor' => ['bet_multiplier' => 100],
        'major' => ['seed' => 50000, 'cap' => 100000, 'contribution' => 0.005],
        'grand' => ['seed' => 1000000, 'contribution' => 0.0025],
    ],

    /*
     * Weighted bale values. `multiplier` is a multiple of the total bet;
     * `jackpot` entries land a Mini, Minor or Major bale instead. Bales
     * that land during Bale Bonus use `bale_bonus.value_profiles` instead.
     */
    'bale_values' => [
        'base' => [
            ...$creditBales,
            ['jackpot' => 'mini', 'weight' => 100],
            ['jackpot' => 'minor', 'weight' => 20],
        ],
    ],

    /*
     * Multiplies the weight of Mini and Minor bales on the reels (and Minor
     * bales in Bale Bonus), keyed by credits per line. The denomination has
     * no effect.
     */
    'jackpot_bet_scaling' => [1 => 1.0, 2 => 1.1, 3 => 1.2, 5 => 1.35, 10 => 1.6],

    /*
     * Credit bale values are scaled by bet level (credits per line) so the
     * extra Minis, Minors and Majors at higher bets are paid for and every bet
     * returns the program's RTP. Calibrated by simulation.
     */
    'credit_value_scale' => [1 => 1.0, 2 => 0.93, 3 => 0.87, 5 => 0.72, 10 => 0.49],

    /*
     * Free games doors land closed and all open to reveal the same symbol.
     * Weights: 20% bales, 10% Farmer wilds, 70% split evenly between the
     * paying symbols.
     */
    'door_reveals' => [
        'bale' => 180,
        'farmer' => 90,
        'tractor' => 70,
        'barn' => 70,
        'dog' => 70,
        'milk_bottle' => 70,
        'king' => 70,
        'queen' => 70,
        'jack' => 70,
        'ten' => 70,
        'nine' => 70,
    ],

    /*
     * Reel strips are built from these symbol counts and shuffled with a fixed
     * seed, so every request sees the same physical strips. `stacks` places a
     * symbol in runs of `size` consecutive positions.
     */
    'strip_seed' => 20161,

    /*
    |--------------------------------------------------------------------------
    | RTP programs
    |--------------------------------------------------------------------------
    |
    | Like a real machine, the posted pays never change between programs: the
    | attendant's RTP choice swaps in different reel strips and bale odds.
    | `extra_low_symbols` is the total number of extra 9s and 10s spread across
    | the five base game reels (diluting the premiums, wilds and bales;
    | each one is worth roughly 0.4% RTP) and `landing_chance_scale` scales the
    | Bale Bonus landing chances. Each program is calibrated by simulation at
    | 1c, minimum bet, with the default jackpot settings.
    |
    */
    'rtp_programs' => [
        90 => ['extra_low_symbols' => 23, 'landing_chance_scale' => 1.0],
        91 => ['extra_low_symbols' => 21, 'landing_chance_scale' => 1.0],
        92 => ['extra_low_symbols' => 19, 'landing_chance_scale' => 1.0],
        93 => ['extra_low_symbols' => 17, 'landing_chance_scale' => 1.0],
        94 => ['extra_low_symbols' => 14, 'landing_chance_scale' => 1.0],
        95 => ['extra_low_symbols' => 12, 'landing_chance_scale' => 1.0],
        96 => ['extra_low_symbols' => 11, 'landing_chance_scale' => 1.0],
        97 => ['extra_low_symbols' => 9, 'landing_chance_scale' => 1.0],
    ],

    /*
    |--------------------------------------------------------------------------
    | Attendant
    |--------------------------------------------------------------------------
    |
    | The PIN unlocks the attendant panel. `defaults` are the machine settings
    | before an attendant changes anything (money in cents).
    |
    */
    'attendant' => [
        'pin' => env('HAY_LINK_ATTENDANT_PIN', '1234'),
        'defaults' => [
            'rtp_program' => 94,
            'max_deposit_cents' => 10_000_000,
            'autoplay_enabled' => true,
        ],
    ],

    'reels' => [
        'base' => [
            ['symbols' => [...$reelSymbols, 'moon' => 3], 'stacks' => ['bale' => ['count' => 2, 'size' => 2]]],
            ['symbols' => $reelSymbols, 'stacks' => ['farmer' => ['count' => 3, 'size' => 3], 'bale' => ['count' => 2, 'size' => 2]]],
            ['symbols' => [...$reelSymbols, 'moon' => 4], 'stacks' => ['farmer' => ['count' => 3, 'size' => 3], 'bale' => ['count' => 2, 'size' => 2]]],
            ['symbols' => $reelSymbols, 'stacks' => ['farmer' => ['count' => 3, 'size' => 3], 'bale' => ['count' => 2, 'size' => 2]]],
            ['symbols' => [...$reelSymbols, 'moon' => 3], 'stacks' => ['farmer' => ['count' => 3, 'size' => 3], 'bale' => ['count' => 2, 'size' => 2]]],
        ],
        'free' => [
            ['symbols' => $freeReelSymbols, 'stacks' => ['door' => ['count' => 6, 'size' => 3]]],
            ['symbols' => $freeReelSymbols, 'stacks' => ['farmer' => ['count' => 1, 'size' => 3], 'door' => ['count' => 6, 'size' => 3]]],
            ['symbols' => $freeReelSymbols, 'stacks' => ['farmer' => ['count' => 1, 'size' => 3], 'door' => ['count' => 6, 'size' => 3]]],
            ['symbols' => $freeReelSymbols, 'stacks' => ['farmer' => ['count' => 1, 'size' => 3], 'door' => ['count' => 6, 'size' => 3]]],
            ['symbols' => $freeReelSymbols, 'stacks' => ['farmer' => ['count' => 1, 'size' => 3], 'door' => ['count' => 6, 'size' => 3]]],
        ],
    ],

];
