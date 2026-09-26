<?php

namespace Tests\Unit\HayLink;

use App\Games\HayLink\PaylineEvaluator;
use App\Games\HayLink\Symbol;
use PHPUnit\Framework\TestCase;

class PaylineEvaluatorTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $config;

    private PaylineEvaluator $evaluator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->config = require __DIR__.'/../../../config/hay_link.php';
        $this->evaluator = new PaylineEvaluator($this->config['paylines'], $this->config['paytable'], $this->config['scatter_pays']);
    }

    public function test_config_has_fifty_unique_paylines(): void
    {
        $paylines = $this->config['paylines'];

        $this->assertCount(50, $paylines);
        $this->assertCount(50, array_unique(array_map('json_encode', $paylines)));

        foreach ($paylines as $rows) {
            $this->assertCount(5, $rows);
            $this->assertSame([], array_diff($rows, [0, 1, 2]));
        }
    }

    public function test_five_of_a_kind_on_the_middle_line_pays_the_line_bet_multiple(): void
    {
        $grid = $this->grid([
            ['nine', 'tractor', 'ten'],
            ['jack', 'tractor', 'ten'],
            ['queen', 'tractor', 'king'],
            ['jack', 'tractor', 'nine'],
            ['ten', 'tractor', 'queen'],
        ]);

        $wins = $this->evaluator->lineWins($grid, creditsPerLine: 2, lineCount: 50);
        $middleLine = collect($wins)->firstWhere('line', 1);

        $this->assertSame('tractor', $middleLine['symbol']);
        $this->assertSame(5, $middleLine['count']);
        $this->assertSame($this->config['paytable']['tractor'][5] * 2, $middleLine['win']);
        $this->assertSame([[0, 1], [1, 1], [2, 1], [3, 1], [4, 1]], $middleLine['positions']);
    }

    public function test_farmer_wild_substitutes_on_a_payline(): void
    {
        $grid = $this->grid([
            ['nine', 'dog', 'ten'],
            ['jack', 'farmer', 'ten'],
            ['queen', 'farmer', 'king'],
            ['jack', 'dog', 'nine'],
            ['ten', 'moon', 'queen'],
        ]);

        $middleLine = collect($this->evaluator->lineWins($grid, 1, 50))->firstWhere('line', 1);

        $this->assertSame('dog', $middleLine['symbol']);
        $this->assertSame(4, $middleLine['count']);
        $this->assertSame($this->config['paytable']['dog'][4], $middleLine['win']);
    }

    public function test_scatters_and_bales_do_not_pay_on_lines_and_are_not_substituted(): void
    {
        $grid = $this->grid([
            ['moon', 'bale', 'moon'],
            ['farmer', 'farmer', 'farmer'],
            ['moon', 'bale', 'moon'],
            ['farmer', 'farmer', 'farmer'],
            ['bale', 'moon', 'bale'],
        ]);

        $this->assertSame([], $this->evaluator->lineWins($grid, 1, 50));
    }

    public function test_line_wins_require_the_symbol_to_start_on_the_first_reel(): void
    {
        $grid = $this->grid([
            ['nine', 'ten', 'jack'],
            ['king', 'king', 'king'],
            ['king', 'king', 'king'],
            ['king', 'king', 'king'],
            ['king', 'king', 'king'],
        ]);

        $this->assertSame([], $this->evaluator->lineWins($grid, 1, 50));
    }

    public function test_scatter_pays_multiply_the_total_bet(): void
    {
        $grid = $this->grid([
            ['moon', 'nine', 'ten'],
            ['jack', 'ten', 'moon'],
            ['queen', 'king', 'king'],
            ['moon', 'nine', 'nine'],
            ['ten', 'jack', 'queen'],
        ]);

        $scatter = $this->evaluator->scatterWin($grid, totalBet: 100);

        $this->assertSame(3, $scatter['count']);
        $this->assertSame($this->config['scatter_pays'][3] * 100, $scatter['win']);
        $this->assertSame([[0, 0], [1, 2], [3, 0]], $scatter['positions']);
    }

    /**
     * @param  list<list<string>>  $symbols
     * @return list<list<Symbol>>
     */
    private function grid(array $symbols): array
    {
        return array_map(fn (array $column): array => array_map(Symbol::from(...), $column), $symbols);
    }
}
