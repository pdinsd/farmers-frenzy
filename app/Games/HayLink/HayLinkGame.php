<?php

namespace App\Games\HayLink;

use App\Games\HayLink\Jackpots\JackpotBank;
use Random\Randomizer;

/**
 * The Farmers Frenzy machine: a 5x3, 50 line game with stacked Farmer wilds, Moon
 * scatter free games and the Hay Link Bale Bonus bale feature.
 */
final class HayLinkGame
{
    public const CELLS = 15;

    /**
     * @param  array<string, mixed>  $config  The `hay_link` config array.
     */
    public function __construct(
        private readonly array $config,
        private readonly ReelSet $baseReels,
        private readonly ReelSet $freeReels,
        private readonly PaylineEvaluator $evaluator,
        private readonly BaleValues $baleValues,
        private readonly JackpotBank $jackpots,
        private readonly Randomizer $randomizer,
    ) {}

    /**
     * @param  array<string, mixed>  $config  The `hay_link` config array.
     */
    public static function fromConfig(array $config, JackpotBank $jackpots, Randomizer $randomizer): self
    {
        return new self(
            config: $config,
            baseReels: ReelSet::fromSpec($config['reels']['base'], $config['strip_seed']),
            freeReels: ReelSet::fromSpec($config['reels']['free'], $config['strip_seed']),
            evaluator: new PaylineEvaluator($config['paylines'], $config['paytable'], $config['scatter_pays']),
            baleValues: new BaleValues(self::baleTables($config), $config['jackpots'], $config['bale_bonus']['capped_min_multiplier']),
            jackpots: $jackpots,
            randomizer: $randomizer,
        );
    }

    /**
     * A freshly reset machine, showing the same screen as the reference artwork.
     *
     * @param  int|null  $balanceCents  Money deposited, in cents; defaults to the configured starting balance.
     * @param  int|null  $denomination  Cents per credit; defaults to the lowest denomination.
     */
    public function newState(?int $balanceCents = null, ?int $denomination = null): GameState
    {
        $balanceCents ??= $this->config['starting_balance'];
        $denomination = isset($this->config['denominations'][$denomination]) ? $denomination : array_key_first($this->config['denominations']);
        $creditsPerLine = $this->config['credits_per_line_options'][0];
        $totalBet = $creditsPerLine * $this->config['denominations'][$denomination];

        return new GameState(
            credits: intdiv($balanceCents, $denomination),
            creditsPerLine: $creditsPerLine,
            denomination: $denomination,
            residualCents: $balanceCents % $denomination,
            grid: [
                ['queen', 'moon', 'ten'],
                ['king', 'bale', 'bale'],
                ['tractor', 'nine', 'dog'],
                ['farmer', 'farmer', 'jack'],
                ['ten', 'milk_bottle', 'queen'],
            ],
            bales: [
                ['reel' => 1, 'row' => 1, 'type' => 'credits', 'amount' => 10 * $totalBet],
                ['reel' => 1, 'row' => 2, 'type' => 'credits', 'amount' => $totalBet],
            ],
        );
    }

    /**
     * How many paylines are played at the machine's current denomination.
     */
    public function lines(GameState $state): int
    {
        return $this->config['denominations'][$state->denomination];
    }

    public function totalBet(GameState $state): int
    {
        return $state->creditsPerLine * $this->lines($state);
    }

    /**
     * Bring a saved machine state back within the current settings, e.g. after the
     * attendant disables its denomination or lowers the maximum bet. Left alone
     * during a feature so the feature finishes at the bet it started with.
     */
    public function normalize(GameState $state): void
    {
        if ($state->isInFeature()) {
            return;
        }

        if (! isset($this->config['denominations'][$state->denomination])) {
            $this->changeDenomination($state, array_key_first($this->config['denominations']));
        }

        $options = $this->config['credits_per_line_options'];

        if (! in_array($state->creditsPerLine, $options, true)) {
            $allowed = array_filter($options, fn (int $option): bool => $option <= $state->creditsPerLine);
            $state->creditsPerLine = $allowed === [] ? min($options) : max($allowed);
        }
    }

    /**
     * Switch denomination, converting the balance to credits of the new value.
     * Cents that do not make up a whole credit are kept aside, not lost.
     */
    public function changeDenomination(GameState $state, int $denomination): void
    {
        if ($state->isInFeature() || ! isset($this->config['denominations'][$denomination])) {
            return;
        }

        $balanceCents = $state->credits * $state->denomination + $state->residualCents;

        $state->denomination = $denomination;
        $state->credits = intdiv($balanceCents, $denomination);
        $state->residualCents = $balanceCents % $denomination;
        $state->lastWin = 0;
        $state->bales = [];
    }

    /**
     * Press SPIN: plays the next Bale Bonus respin, the next free game, or a paid base game.
     *
     * @return array<string, mixed>
     */
    public function play(GameState $state): array
    {
        if ($state->baleBonus !== null) {
            return $this->respin($state);
        }

        if ($state->freeGames !== null) {
            return $this->spinReels($state, 'free');
        }

        $totalBet = $this->totalBet($state);

        if ($state->credits < $totalBet) {
            throw new InsufficientCreditsException($state->credits, $totalBet);
        }

        $state->credits -= $totalBet;
        $this->jackpots->contribute($totalBet * $state->denomination);

        return $this->spinReels($state, 'base');
    }

    /**
     * Current jackpot meter values in credits of the current denomination.
     *
     * @return array{grand: float, major: float, minor: int, mini: int}
     */
    public function jackpotValues(GameState $state): array
    {
        $progressives = $this->jackpots->progressiveValues();
        $totalBet = $this->totalBet($state);

        return [
            'grand' => $progressives['grand'] / $state->denomination,
            'major' => $progressives['major'] / $state->denomination,
            'minor' => $this->config['jackpots']['minor']['bet_multiplier'] * $totalBet,
            'mini' => $this->config['jackpots']['mini']['bet_multiplier'] * $totalBet,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function spinReels(GameState $state, string $mode): array
    {
        $totalBet = $this->totalBet($state);
        $reels = $mode === 'free' ? $this->freeReels : $this->baseReels;
        $grid = $reels->spin($this->randomizer)['grid'];
        $doors = $this->revealDoors($grid);

        $lineWins = $this->evaluator->lineWins($grid, $state->creditsPerLine, $this->lines($state));
        $scatter = $this->evaluator->scatterWin($grid, $totalBet);
        $win = array_sum(array_column($lineWins, 'win')) + $scatter['win'];

        $bales = [];
        $cappedLanded = false;

        foreach ($this->evaluator->positionsOf($grid, Symbol::Bale) as [$reel, $row]) {
            $value = $this->baleValues->pick('base', $totalBet, $this->randomizer, $this->jackpotBoost($state), allowCapped: ! $cappedLanded, creditScale: $this->creditScale($state));
            $cappedLanded = $cappedLanded || $this->baleValues->isCapped($value, $totalBet, $this->creditScale($state));
            $bales[] = ['reel' => $reel, 'row' => $row, ...$value];
        }

        $state->credits += $win;
        $state->grid = array_map(fn (array $column): array => array_map(fn (Symbol $symbol): string => $symbol->value, $column), $grid);
        $state->bales = $bales;

        $freeGamesAwarded = 0;

        if ($mode === 'free') {
            $state->freeGames['remaining']--;
            $state->freeGames['played']++;
            $state->freeGames['win'] += $win;
        }

        if ($scatter['count'] >= $this->config['free_games']['trigger_count']) {
            $freeGamesAwarded = $this->awardFreeGames($state);
        }

        $baleBonusTriggered = count($bales) >= $this->config['bale_bonus']['trigger_count'];

        if ($baleBonusTriggered) {
            $state->baleBonus = $this->startBaleBonus($state, $bales, $mode === 'free');
        }

        $state->lastWin = $mode === 'free' ? $state->freeGames['win'] : $win;

        return [
            'mode' => $mode,
            'grid' => $state->grid,
            'doors' => $doors,
            'bales' => $bales,
            'line_wins' => $lineWins,
            'scatter' => $scatter,
            'win' => $win,
            'triggered' => [
                'free_games' => $freeGamesAwarded,
                'bale_bonus' => $baleBonusTriggered,
            ],
            'free_games_completed' => $this->completeFreeGamesIfFinished($state),
        ];
    }

    /**
     * How much more often Mini, Minor and Major bales land at the current bet level.
     */
    public function creditScale(GameState $state): float
    {
        return (float) ($this->config['credit_value_scale'][$state->creditsPerLine] ?? 1.0);
    }

    /**
     * The chance that a Bale Bonus started now will contain the Major:
     * proportional to the bet in cents, and rising as the Major climbs from its
     * seed towards its cap.
     */
    public function majorChance(GameState $state): float
    {
        $settings = $this->config['bale_bonus']['major_chance'];
        $major = $this->config['jackpots']['major'];
        $value = $this->jackpots->progressiveValues()['major'];
        $climb = $major['cap'] > $major['seed'] ? max(0.0, min(1.0, ($value - $major['seed']) / ($major['cap'] - $major['seed']))) : 1.0;
        $betCents = $this->totalBet($state) * $state->denomination;

        return min(1.0, $settings['per_bet_cent'] * $betCents * (1 + ($settings['at_cap_multiplier'] - 1) * $climb));
    }

    /**
     * How many Minis a Bale Bonus started now will contain, by bet level.
     */
    public function rollMinis(GameState $state): int
    {
        $minis = $this->config['bale_bonus']['minis'];

        if ($this->randomizer->nextFloat() >= ($minis['chance'][$state->creditsPerLine] ?? 0.0)) {
            return 0;
        }

        $count = 1;

        while ($count < $minis['max'] && $this->randomizer->nextFloat() < ($minis['extra_chance'][$state->creditsPerLine] ?? 0.0)) {
            $count++;
        }

        return $count;
    }

    public function jackpotBoost(GameState $state): float
    {
        return (float) ($this->config['jackpot_bet_scaling'][$state->creditsPerLine] ?? 1.0);
    }

    /**
     * Open every door on screen to reveal one shared symbol, replacing the doors in the grid.
     * Returns the revealed symbol and where the doors were, or null when no doors landed.
     *
     * @param  list<list<Symbol>>  $grid
     * @return array{symbol: string, positions: list<array{0: int, 1: int}>}|null
     */
    private function revealDoors(array &$grid): ?array
    {
        $positions = $this->evaluator->positionsOf($grid, Symbol::Door);

        if ($positions === []) {
            return null;
        }

        $weights = $this->config['door_reveals'];
        $roll = $this->randomizer->getInt(1, array_sum($weights));
        $revealed = array_key_last($weights);

        foreach ($weights as $symbol => $weight) {
            $roll -= $weight;

            if ($roll <= 0) {
                $revealed = $symbol;
                break;
            }
        }

        foreach ($positions as [$reel, $row]) {
            $grid[$reel][$row] = Symbol::from($revealed);
        }

        return ['symbol' => $revealed, 'positions' => $positions];
    }

    private function awardFreeGames(GameState $state): int
    {
        if ($state->freeGames === null) {
            $awarded = $this->config['free_games']['awarded'];
            $state->freeGames = ['remaining' => $awarded, 'played' => 0, 'total' => $awarded, 'win' => 0];

            return $awarded;
        }

        $awarded = $this->config['free_games']['retrigger_awarded'];
        $state->freeGames['remaining'] += $awarded;
        $state->freeGames['total'] += $awarded;

        return $awarded;
    }

    /**
     * The weighted value tables bales are drawn from: `base` for bales
     * on the reels, and one `profile_N` table per Bale Bonus value tier.
     *
     * @param  array<string, mixed>  $config
     * @return array<string, list<array{multiplier?: int, jackpot?: string, weight: int|float}>>
     */
    public static function baleTables(array $config): array
    {
        $tables = ['base' => $config['bale_values']['base']];

        foreach ($config['bale_bonus']['value_profiles'] as $profile => $weights) {
            $tables["profile_{$profile}"] = [
                ...array_map(fn (int $multiplier, int|float $weight): array => ['multiplier' => $multiplier, 'weight' => $weight], array_keys($weights), $weights),
                ...$config['bale_bonus']['profile_jackpots'],
            ];
        }

        return $tables;
    }

    /**
     * @param  list<array{reel: int, row: int, type: string, amount: int}>  $bales
     * @return array{cells: list<array{type: string, amount: int}|null>, respins_left: int, during_free_games: bool, profile: int, major_pending: bool, minis_pending: int}
     */
    private function startBaleBonus(GameState $state, array $bales, bool $duringFreeGames): array
    {
        $totalBet = $this->totalBet($state);
        $cells = array_fill(0, self::CELLS, null);

        foreach ($bales as $bale) {
            $cells[$bale['reel'] * ReelSet::ROWS + $bale['row']] = ['type' => $bale['type'], 'amount' => $bale['amount']];
        }

        return [
            'cells' => $cells,
            'respins_left' => $this->config['bale_bonus']['respins'],
            'during_free_games' => $duringFreeGames,
            'profile' => $this->chooseValueProfile($bales, $totalBet),
            'major_pending' => $this->randomizer->nextFloat() < $this->majorChance($state),
            'minis_pending' => $this->rollMinis($state),
        ];
    }

    /**
     * Pick the value tier for a Bale Bonus. High balls drop more high balls:
     * the richer the triggering bales, the more the odds slide towards the
     * high tiers.
     *
     * @param  list<array{reel: int, row: int, type: string, amount: int}>  $bales
     */
    public function chooseValueProfile(array $bales, int $totalBet): int
    {
        $weights = $this->valueProfileWeights($bales, $totalBet);
        $roll = $this->randomizer->nextFloat() * array_sum($weights);

        foreach ($weights as $profile => $weight) {
            $roll -= $weight;

            if ($roll <= 0) {
                return $profile;
            }
        }

        return array_key_last($weights);
    }

    /**
     * The chance weight of each value tier for these triggering bales.
     *
     * @param  list<array{type: string, amount: int}>  $bales
     * @return array<int, float>
     */
    public function valueProfileWeights(array $bales, int $totalBet): array
    {
        $selection = $this->config['bale_bonus']['profile_selection'];
        $multiples = array_map(
            fn (array $bale): float => $bale['type'] === 'credits' ? $bale['amount'] / $totalBet : $selection['jackpot_average'],
            $bales,
        );
        $average = $multiples === [] ? $selection['cold_average'] : array_sum($multiples) / count($multiples);
        $heat = max(0.0, min(1.0, ($average - $selection['cold_average']) / ($selection['hot_average'] - $selection['cold_average'])));

        return array_combine(array_keys($selection['cold']), array_map(
            fn (int|float $cold, int|float $hot): float => $cold * (1 - $heat) + $hot * $heat,
            $selection['cold'],
            $selection['hot'],
        ));
    }

    /**
     * One Bale Bonus respin. The next bale (the 7th, 8th, ... on screen)
     * lands with its own chance in a random empty window; each landing lets the
     * following bale roll too. Any new bale resets the respins; the
     * feature ends when respins run out or all 15 windows are filled.
     *
     * @return array<string, mixed>
     */
    private function respin(GameState $state): array
    {
        $totalBet = $this->totalBet($state);
        $round = $state->baleBonus;
        $landed = [];
        $creditScale = $this->creditScale($state);
        $cappedLanded = array_filter($round['cells'], fn (?array $cell): bool => $this->baleValues->isCapped($cell, $totalBet, $creditScale)) !== [];
        $empty = array_keys(array_filter($round['cells'], fn (?array $cell): bool => $cell === null));
        $pendingChance = $this->config['bale_bonus']['pending_landing_chance'];

        while ($empty !== [] && $this->randomizer->nextFloat() < $this->landingChance(self::CELLS - count($empty) + 1)) {
            $pick = $this->randomizer->getInt(0, count($empty) - 1);
            $index = $empty[$pick];
            array_splice($empty, $pick, 1);

            if (($round['major_pending'] ?? false) && ! $cappedLanded && $this->randomizer->nextFloat() < $pendingChance) {
                $value = ['type' => JackpotTier::Major->value, 'amount' => 0];
                $round['major_pending'] = false;
            } elseif (($round['minis_pending'] ?? 0) > 0 && $this->randomizer->nextFloat() < $pendingChance) {
                $value = ['type' => JackpotTier::Mini->value, 'amount' => $this->config['jackpots']['mini']['bet_multiplier'] * $totalBet];
                $round['minis_pending']--;
            } else {
                $value = $this->baleValues->pick(
                    'profile_'.($round['profile'] ?? 4),
                    $totalBet,
                    $this->randomizer,
                    $this->jackpotBoost($state),
                    allowCapped: ! $cappedLanded && ! ($round['major_pending'] ?? false),
                    creditScale: $creditScale,
                );
            }

            $cappedLanded = $cappedLanded || $this->baleValues->isCapped($value, $totalBet, $creditScale);
            $round['cells'][$index] = $value;
            $landed[] = $index;
        }

        sort($landed);

        $round['respins_left'] = $landed === [] ? $round['respins_left'] - 1 : $this->config['bale_bonus']['respins'];

        $filled = count(array_filter($round['cells'], fn (?array $cell): bool => $cell !== null));
        $grandWon = $filled === self::CELLS;
        $finished = $grandWon || $round['respins_left'] === 0;

        $outcome = [
            'mode' => 'bale_bonus',
            'cells' => $round['cells'],
            'landed' => $landed,
            'during_free_games' => $round['during_free_games'],
            'profile' => $round['profile'] ?? null,
            'respins_left' => $round['respins_left'],
            'finished' => $finished,
            'grand_won' => $grandWon,
            'grand' => 0,
            'values_replaced_by_grand' => false,
            'win' => 0,
            'free_games_completed' => null,
        ];

        if (! $finished) {
            $state->baleBonus = $round;
            $state->lastWin = $this->cellsTotal($round['cells']);

            return $outcome;
        }

        $valuesReplacedByGrand = $grandWon && $this->config['bale_bonus']['grand_replaces_values'];

        if (! $valuesReplacedByGrand) {
            foreach ($round['cells'] as $index => $cell) {
                if (($cell['type'] ?? null) === JackpotTier::Major->value) {
                    $round['cells'][$index]['amount'] = $this->awardProgressive($state, JackpotTier::Major);
                }
            }
        }

        $grand = $grandWon ? $this->awardProgressive($state, JackpotTier::Grand) : 0;
        $win = ($valuesReplacedByGrand ? 0 : $this->cellsTotal($round['cells'])) + $grand;

        $state->credits += $win;
        $state->baleBonus = null;

        if ($round['during_free_games']) {
            $state->freeGames['win'] += $win;
            $state->lastWin = $state->freeGames['win'];
        } else {
            $state->lastWin = $win;
        }

        return [
            ...$outcome,
            'cells' => $round['cells'],
            'grand' => $grand,
            'values_replaced_by_grand' => $valuesReplacedByGrand,
            'win' => $win,
            'free_games_completed' => $this->completeFreeGamesIfFinished($state),
        ];
    }

    /**
     * Close out free games once the last one has been played and no Bale Bonus is pending.
     * Returns the total free games win, or null when free games are not finishing.
     */
    private function completeFreeGamesIfFinished(GameState $state): ?int
    {
        if ($state->freeGames === null || $state->freeGames['remaining'] > 0 || $state->baleBonus !== null) {
            return null;
        }

        $total = $state->freeGames['win'];
        $state->freeGames = null;
        $state->lastWin = $total;

        return $total;
    }

    /**
     * Pay a progressive jackpot (held in cents) as credits of the current denomination.
     */
    private function awardProgressive(GameState $state, JackpotTier $tier): int
    {
        $cents = $this->jackpots->award($tier) + $state->residualCents;
        $state->residualCents = $cents % $state->denomination;

        return intdiv($cents, $state->denomination);
    }

    /**
     * The chance that the Nth bale on screen lands on a respin.
     */
    private function landingChance(int $nthBale): float
    {
        $chances = $this->config['bale_bonus']['landing_chances'];

        return (float) ($chances[$nthBale] ?? ($nthBale < min(array_keys($chances)) ? $chances[min(array_keys($chances))] : 0.0));
    }

    /**
     * @param  list<array{type: string, amount: int}|null>  $cells
     */
    private function cellsTotal(array $cells): int
    {
        return array_sum(array_map(fn (?array $cell): int => $cell['amount'] ?? 0, $cells));
    }
}
