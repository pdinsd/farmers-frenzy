<?php

namespace App\Console\Commands;

use App\Games\HayLink\Simulator;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('hay-link:simulate {spins=100000 : Number of paid base game spins} {--seed= : Seed for a reproducible run} {--credits-per-line=1} {--denomination=1 : Cents per credit} {--rtp= : RTP program to simulate instead of the machine setting} {--extra-low= : Calibrate: extra low symbols per base reel} {--landing-scale=1 : Calibrate: Bale Bonus landing chance scale} {--credit-scale= : Calibrate: credit bale value scale for every bet level}')]
#[Description('Simulate Farmers Frenzy play and report the return to player and feature frequencies')]
class SimulateHayLink extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(Simulator $simulator): int
    {
        $spins = (int) $this->argument('spins');
        $seed = $this->option('seed');
        $settings = $this->option('rtp') === null ? [] : ['rtp_program' => (int) $this->option('rtp')];

        if ($this->option('extra-low') !== null) {
            $settings['program_override'] = ['extra_low_symbols' => (int) $this->option('extra-low'), 'landing_chance_scale' => (float) $this->option('landing-scale')];
        }

        if ($this->option('credit-scale') !== null) {
            $settings['credit_scale_override'] = (float) $this->option('credit-scale');
        }

        $bar = $this->output->createProgressBar($spins);
        $result = $simulator->run(
            spins: $spins,
            creditsPerLine: (int) $this->option('credits-per-line'),
            denomination: (int) $this->option('denomination'),
            settings: $settings,
            seed: $seed === null ? null : (int) $seed,
            onProgress: fn (int $done) => $bar->setProgress($done),
        );
        $bar->finish();
        $this->newLine(2);

        $this->table(['Source', 'RTP %'], [
            ...array_map(fn (string $source, float $rtp): array => [str_replace('_', ' ', $source), number_format($rtp, 2)], array_keys($result['rtp_by_source']), $result['rtp_by_source']),
            ['TOTAL', number_format($result['rtp'], 2).' ± '.number_format($result['rtp_confidence'], 2)],
            ['excluding Major & Grand', number_format($result['rtp_excluding_progressives'], 2)],
        ]);

        $this->table(['Event', 'Count', '1 in'], array_map(
            fn (string $event, int $count): array => [str_replace('_', ' ', $event), number_format($count), $count > 0 ? number_format($spins / $count, 1) : '-'],
            array_keys($result['counts']),
            $result['counts'],
        ));

        $this->line("Program {$result['rtp_program']}%: {$spins} spins at {$result['total_bet']} credits, {$result['denomination']}c, {$result['lines']} lines.");
        $this->line('Hit frequency '.number_format($result['hit_frequency'], 2).'%, SD '.number_format($result['standard_deviation'], 2).', volatility index '.number_format($result['volatility_index'], 2).', biggest win '.number_format($result['biggest_win_multiple'], 1).'x bet.');
        $this->line('Progressive contributions (not included above): '.number_format($result['progressive_contribution'], 2).'% of wagers.');

        return self::SUCCESS;
    }
}
