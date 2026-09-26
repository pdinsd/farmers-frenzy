<?php

namespace App\Providers;

use App\Games\HayLink\HayLinkGame;
use App\Games\HayLink\Jackpots\DatabaseJackpotBank;
use App\Games\HayLink\Jackpots\JackpotBank;
use App\Games\HayLink\MachineConfig;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Random\Randomizer;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(Randomizer::class, fn (): Randomizer => new Randomizer);

        $this->app->scoped(MachineConfig::class, fn (): MachineConfig => new MachineConfig(config('hay_link')));

        $this->app->scoped(JackpotBank::class, fn (Application $app): JackpotBank => new DatabaseJackpotBank(
            $app->make(MachineConfig::class)->effective()['jackpots'],
        ));

        $this->app->bind(HayLinkGame::class, fn (Application $app): HayLinkGame => HayLinkGame::fromConfig(
            $app->make(MachineConfig::class)->effective(),
            $app->make(JackpotBank::class),
            $app->make(Randomizer::class),
        ));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        URL::defaults(['theme' => 'farmers-frenzy']);
    }
}
