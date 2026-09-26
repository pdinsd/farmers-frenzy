<?php

namespace App\Games\HayLink;

/**
 * Everything a single machine remembers between plays. Stored in the player's session.
 *
 * @phpstan-type BaleValue array{type: string, amount: int}
 * @phpstan-type FreeGames array{remaining: int, played: int, total: int, win: int}
 * @phpstan-type BaleBonus array{cells: list<BaleValue|null>, respins_left: int, during_free_games: bool, profile?: int, major_pending?: bool, minis_pending?: int}
 */
final class GameState
{
    /**
     * @param  int  $denomination  Cents per credit.
     * @param  int  $residualCents  Cents left over after converting the balance to whole credits.
     * @param  list<list<string>>|null  $grid  The symbols left on screen by the last spin, indexed [reel][row].
     * @param  list<array{reel: int, row: int, type: string, amount: int}>  $bales
     * @param  FreeGames|null  $freeGames
     * @param  BaleBonus|null  $baleBonus
     */
    public function __construct(
        public int $credits,
        public int $creditsPerLine,
        public int $denomination = 1,
        public int $residualCents = 0,
        public int $lastWin = 0,
        public ?array $grid = null,
        public array $bales = [],
        public ?array $freeGames = null,
        public ?array $baleBonus = null,
    ) {}

    /**
     * @param  array{credits: int, credits_per_line: int, denomination?: int, residual_cents?: int, last_win?: int, grid?: list<list<string>>|null, bales?: list<array{reel: int, row: int, type: string, amount: int}>, free_games?: FreeGames|null, bale_bonus?: BaleBonus|null}  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            credits: $data['credits'],
            creditsPerLine: $data['credits_per_line'],
            denomination: $data['denomination'] ?? 1,
            residualCents: $data['residual_cents'] ?? 0,
            lastWin: $data['last_win'] ?? 0,
            grid: $data['grid'] ?? null,
            bales: $data['bales'] ?? [],
            freeGames: $data['free_games'] ?? null,
            baleBonus: $data['bale_bonus'] ?? null,
        );
    }

    /**
     * @return array{credits: int, credits_per_line: int, denomination: int, residual_cents: int, last_win: int, grid: list<list<string>>|null, bales: list<array{reel: int, row: int, type: string, amount: int}>, free_games: FreeGames|null, bale_bonus: BaleBonus|null}
     */
    public function toArray(): array
    {
        return [
            'credits' => $this->credits,
            'credits_per_line' => $this->creditsPerLine,
            'denomination' => $this->denomination,
            'residual_cents' => $this->residualCents,
            'last_win' => $this->lastWin,
            'grid' => $this->grid,
            'bales' => $this->bales,
            'free_games' => $this->freeGames,
            'bale_bonus' => $this->baleBonus,
        ];
    }

    /**
     * The bet and denomination are locked while a feature is in progress.
     */
    public function isInFeature(): bool
    {
        return $this->freeGames !== null || $this->baleBonus !== null;
    }
}
