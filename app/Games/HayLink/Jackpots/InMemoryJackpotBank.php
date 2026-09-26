<?php

namespace App\Games\HayLink\Jackpots;

use App\Games\HayLink\JackpotTier;

/**
 * A jackpot bank that lives only for the current process, used for simulations and tests.
 */
final class InMemoryJackpotBank implements JackpotBank
{
    /** @var array{major: float, grand: float} */
    private array $values;

    /**
     * @param  array{major: array{seed: int, contribution: float, cap?: int}, grand: array{seed: int, contribution: float, cap?: int}}  $config
     */
    public function __construct(private readonly array $config)
    {
        $this->values = [
            'major' => (float) $config['major']['seed'],
            'grand' => (float) $config['grand']['seed'],
        ];
    }

    public function contribute(int $wagerCents): void
    {
        foreach (['major', 'grand'] as $tier) {
            $this->values[$tier] = min(
                $this->values[$tier] + $wagerCents * $this->config[$tier]['contribution'],
                (float) ($this->config[$tier]['cap'] ?? PHP_INT_MAX),
            );
        }
    }

    public function progressiveValues(): array
    {
        return $this->values;
    }

    public function award(JackpotTier $tier): int
    {
        $won = (int) floor($this->values[$tier->value]);
        $this->values[$tier->value] = (float) $this->config[$tier->value]['seed'];

        return $won;
    }

    public function resetToSeed(JackpotTier $tier): void
    {
        $this->values[$tier->value] = (float) $this->config[$tier->value]['seed'];
    }
}
