<?php

namespace App\Games\HayLink;

use App\Models\MachineSetting;

/**
 * Combines the game math in `config/hay_link.php` with the attendant's
 * settings to produce the configuration the machine actually runs with.
 *
 * @phpstan-type Settings array{
 *     rtp_program: int,
 *     denominations_enabled: list<int>,
 *     max_credits_per_line: int,
 *     major_seed_cents: int,
 *     major_cap_cents: int,
 *     grand_seed_cents: int,
 *     major_contribution: float,
 *     grand_contribution: float,
 *     jackpot_max_bet_boost: float,
 *     free_games_awarded: int,
 *     grand_replaces_values: bool,
 *     max_deposit_cents: int,
 *     autoplay_enabled: bool,
 * }
 */
final class MachineConfig
{
    /** @var Settings|null */
    private ?array $settings = null;

    /**
     * @param  array<string, mixed>  $base  The `hay_link` config array.
     */
    public function __construct(private readonly array $base) {}

    /**
     * The settings a machine has before an attendant changes anything.
     *
     * @return Settings
     */
    public function defaults(): array
    {
        $jackpots = $this->base['jackpots'];

        return [
            'rtp_program' => $this->base['attendant']['defaults']['rtp_program'],
            'denominations_enabled' => array_keys($this->base['denominations']),
            'max_credits_per_line' => max($this->base['credits_per_line_options']),
            'major_seed_cents' => $jackpots['major']['seed'],
            'major_cap_cents' => $jackpots['major']['cap'],
            'grand_seed_cents' => $jackpots['grand']['seed'],
            'major_contribution' => $jackpots['major']['contribution'],
            'grand_contribution' => $jackpots['grand']['contribution'],
            'jackpot_max_bet_boost' => (float) max($this->base['jackpot_bet_scaling']),
            'free_games_awarded' => $this->base['free_games']['awarded'],
            'grand_replaces_values' => $this->base['bale_bonus']['grand_replaces_values'],
            'max_deposit_cents' => $this->base['attendant']['defaults']['max_deposit_cents'],
            'autoplay_enabled' => $this->base['attendant']['defaults']['autoplay_enabled'],
        ];
    }

    /**
     * The current settings: the defaults overlaid with whatever the attendant saved.
     *
     * @return Settings
     */
    public function settings(): array
    {
        return $this->settings ??= [...$this->defaults(), ...(MachineSetting::query()->first()?->settings ?? [])];
    }

    /**
     * Save attendant changes. Only known settings are stored.
     *
     * @param  array<string, mixed>  $changes
     */
    public function update(array $changes): void
    {
        $settings = [...$this->settings(), ...array_intersect_key($changes, $this->defaults())];

        MachineSetting::query()->updateOrCreate(['id' => 1], ['settings' => $settings]);

        $this->settings = $settings;
    }

    /**
     * A value from the unadjusted game config, e.g. every denomination the machine supports.
     */
    public function base(string $key): mixed
    {
        return data_get($this->base, $key);
    }

    /**
     * The RTP programs the attendant can choose between, keyed by target RTP percent.
     *
     * @return array<int, array{extra_low_symbols: int, landing_chance_scale: float}>
     */
    public function rtpPrograms(): array
    {
        return $this->base['rtp_programs'];
    }

    /**
     * The game configuration with the attendant's settings applied.
     *
     * @param  array<string, mixed>|null  $settings  Settings to apply instead of the saved ones (for simulations).
     *                                               A `program_override` reel program or a
     *                                               `credit_scale_override` may be given for calibration.
     * @return array<string, mixed>
     */
    public function effective(?array $settings = null): array
    {
        $settings = [...$this->settings(), ...($settings ?? [])];
        $config = $this->base;
        $program = $settings['program_override']
            ?? $this->base['rtp_programs'][$settings['rtp_program']]
            ?? $this->base['rtp_programs'][$this->defaults()['rtp_program']];

        $reelCount = count($config['reels']['base']);

        foreach (array_keys($config['reels']['base']) as $reel) {
            $extra = intdiv($program['extra_low_symbols'], $reelCount) + ($reel < $program['extra_low_symbols'] % $reelCount ? 1 : 0);
            $config['reels']['base'][$reel]['padding'] = ['nine' => intdiv($extra + 1, 2), 'ten' => intdiv($extra, 2)];
        }

        $config['bale_bonus']['landing_chances'] = array_map(
            fn (float $chance): float => min(1.0, $chance * $program['landing_chance_scale']),
            $config['bale_bonus']['landing_chances'],
        );

        $enabled = array_intersect_key($config['denominations'], array_flip($settings['denominations_enabled']));
        $config['denominations'] = $enabled === [] ? array_slice($config['denominations'], 0, 1, true) : $enabled;

        $allowedBets = array_values(array_filter($config['credits_per_line_options'], fn (int $option): bool => $option <= $settings['max_credits_per_line']));
        $config['credits_per_line_options'] = $allowedBets === [] ? [min($config['credits_per_line_options'])] : $allowedBets;

        $config['jackpots']['major'] = ['seed' => $settings['major_seed_cents'], 'cap' => max($settings['major_seed_cents'], $settings['major_cap_cents']), 'contribution' => $settings['major_contribution']];
        $config['jackpots']['grand'] = ['seed' => $settings['grand_seed_cents'], 'contribution' => $settings['grand_contribution']];

        $baseBoost = max($this->base['jackpot_bet_scaling']);
        $config['jackpot_bet_scaling'] = array_map(
            fn (float $boost): float => $baseBoost > 1.0 ? 1.0 + ($boost - 1.0) * ($settings['jackpot_max_bet_boost'] - 1.0) / ($baseBoost - 1.0) : 1.0,
            $this->base['jackpot_bet_scaling'],
        );

        if (isset($settings['credit_scale_override'])) {
            $config['credit_value_scale'] = array_fill_keys(array_keys($config['credit_value_scale']), (float) $settings['credit_scale_override']);
        }

        $config['free_games']['awarded'] = $settings['free_games_awarded'];
        $config['free_games']['retrigger_awarded'] = $settings['free_games_awarded'];
        $config['bale_bonus']['grand_replaces_values'] = $settings['grand_replaces_values'];

        return $config;
    }

    /**
     * A fingerprint of the settings that change the game math, used to tell
     * whether a stored simulation still matches the machine.
     */
    public function mathFingerprint(?array $settings = null): string
    {
        $settings = [...$this->settings(), ...($settings ?? [])];

        return md5(json_encode([
            $settings['rtp_program'],
            $settings['jackpot_max_bet_boost'],
            $settings['free_games_awarded'],
            $settings['grand_replaces_values'],
            $settings['major_seed_cents'],
            $settings['major_cap_cents'],
            $settings['grand_seed_cents'],
            $this->base['credit_value_scale'],
            $this->base['reels'],
            $this->base['paytable'],
            $this->base['bale_values'],
            $this->base['door_reveals'],
            $this->base['bale_bonus'],
        ]));
    }
}
