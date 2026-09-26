<?php

namespace App\Games\HayLink\Jackpots;

use App\Games\HayLink\JackpotTier;
use App\Models\Jackpot;
use Illuminate\Support\Facades\DB;

/**
 * Progressive jackpots shared by every player, stored in the jackpots table.
 */
final class DatabaseJackpotBank implements JackpotBank
{
    /**
     * @param  array{major: array{seed: int, contribution: float, cap?: int}, grand: array{seed: int, contribution: float, cap?: int}}  $config
     */
    public function __construct(private readonly array $config) {}

    private bool $seeded = false;

    public function contribute(int $wagerCents): void
    {
        $this->ensureSeeded();

        foreach (['major', 'grand'] as $tier) {
            Jackpot::query()->where('tier', $tier)->increment('value', $wagerCents * $this->config[$tier]['contribution']);

            if (isset($this->config[$tier]['cap'])) {
                Jackpot::query()->where('tier', $tier)->where('value', '>', $this->config[$tier]['cap'])->update(['value' => $this->config[$tier]['cap']]);
            }
        }
    }

    public function progressiveValues(): array
    {
        $this->ensureSeeded();

        $values = Jackpot::query()->pluck('value', 'tier');

        return [
            'major' => (float) $values['major'],
            'grand' => (float) $values['grand'],
        ];
    }

    public function award(JackpotTier $tier): int
    {
        $this->ensureSeeded();

        return DB::transaction(function () use ($tier): int {
            $jackpot = Jackpot::query()->where('tier', $tier->value)->lockForUpdate()->firstOrFail();
            $won = (int) floor($jackpot->value);

            $jackpot->update(['value' => $this->config[$tier->value]['seed']]);

            return $won;
        });
    }

    public function resetToSeed(JackpotTier $tier): void
    {
        $this->ensureSeeded();

        Jackpot::query()->where('tier', $tier->value)->update(['value' => $this->config[$tier->value]['seed']]);
    }

    private function ensureSeeded(): void
    {
        if ($this->seeded) {
            return;
        }

        $this->seeded = true;

        foreach (['major', 'grand'] as $tier) {
            Jackpot::query()->firstOrCreate(['tier' => $tier], ['value' => $this->config[$tier]['seed']]);
        }
    }
}
