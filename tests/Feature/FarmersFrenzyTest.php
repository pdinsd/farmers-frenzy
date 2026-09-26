<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FarmersFrenzyTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_farmers_frenzy_machine_renders_with_its_own_artwork(): void
    {
        $this->withoutVite();

        $this->get(route('machine.theme', ['theme' => 'farmers-frenzy']))
            ->assertOk()
            ->assertSee('Hay Link · Farmers Frenzy', false)
            ->assertSee('images/farmers-frenzy/logo.png')
            ->assertSee('images/farmers-frenzy/scene.jpg')
            ->assertSee('/farmers-frenzy/play')
            ->assertSee('Tractor');
    }

    public function test_an_unknown_theme_is_not_found(): void
    {
        $this->get('/lucky-dragon')->assertNotFound();
        $this->postJson('/lucky-dragon/play')->assertNotFound();
    }

    public function test_the_home_page_is_farmers_frenzy(): void
    {
        $this->withoutVite();

        $this->get(route('machine.show'))->assertOk()->assertSee('Hay Link · Farmers Frenzy', false);
    }

    public function test_the_game_config_carries_the_theme_symbol_names(): void
    {
        $this->getJson('/farmers-frenzy/config')
            ->assertOk()
            ->assertJsonPath('game.symbolNames.farmer', 'Farmer')
            ->assertJsonPath('game.symbolNames.milk_bottle', 'Milk Bottle')
            ->assertJsonPath('game.symbolPlurals.bale', 'Hay Bales');

    }

    public function test_the_par_sheet_uses_the_theme_names(): void
    {
        $this->get('/farmers-frenzy/par-sheet')
            ->assertOk()
            ->assertSee('Farmers Frenzy · Hay Link · 5×3 reels')
            ->assertSee('Farmer (wild)')
            ->assertSee('Milk Bottle');
    }
}
