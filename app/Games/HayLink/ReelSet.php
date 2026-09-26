<?php

namespace App\Games\HayLink;

use Random\Engine\Mt19937;
use Random\Randomizer;

final class ReelSet
{
    public const ROWS = 3;

    /**
     * @param  list<list<Symbol>>  $strips
     */
    public function __construct(public readonly array $strips) {}

    /**
     * Build the strips from a reel specification, shuffled with a fixed seed so
     * the strips are identical on every request. Stacked symbols (Farmer wilds,
     * free games doors) occupy runs of `size` consecutive positions.
     *
     * `padding` symbols are inserted afterwards at evenly spaced points without
     * reshuffling, so RTP programs differ only by how diluted the strips are.
     *
     * @param  list<array{symbols: array<string, int>, stacks?: array<string, array{count: int, size: int}>, padding?: array<string, int>}>  $reelSpecs
     */
    public static function fromSpec(array $reelSpecs, int $seed): self
    {
        $shuffler = new Randomizer(new Mt19937($seed));
        $strips = [];

        foreach ($reelSpecs as $spec) {
            $blocks = [];

            foreach ($spec['symbols'] as $symbol => $count) {
                for ($i = 0; $i < $count; $i++) {
                    $blocks[] = [Symbol::from($symbol)];
                }
            }

            foreach ($spec['stacks'] ?? [] as $symbol => $stack) {
                for ($i = 0; $i < $stack['count']; $i++) {
                    $blocks[] = array_fill(0, $stack['size'], Symbol::from($symbol));
                }
            }

            $strips[] = self::pad(array_merge(...self::spread($blocks, $shuffler)), $spec['padding'] ?? []);
        }

        return new self($strips);
    }

    /**
     * Lay the blocks out so every symbol's blocks (singles and stacks alike)
     * are spread evenly along the strip, like a designed reel strip, with that
     * symbol's stacks spread evenly among its singles. The seed only sets where
     * each symbol starts, so identical symbols never clump together by chance.
     *
     * @param  list<list<Symbol>>  $blocks
     * @return list<list<Symbol>>
     */
    private static function spread(array $blocks, Randomizer $randomizer): array
    {
        $groups = [];

        foreach ($blocks as $block) {
            $groups[$block[0]->value][count($block)][] = $block;
        }

        $placed = [];

        foreach ($groups as $sizes) {
            $ordered = [];

            foreach ($sizes as $sameSize) {
                $phase = $randomizer->nextFloat();

                foreach ($sameSize as $index => $block) {
                    $ordered[] = ['order' => ($index + $phase) / count($sameSize), 'block' => $block];
                }
            }

            usort($ordered, fn (array $a, array $b): int => $a['order'] <=> $b['order']);
            $phase = $randomizer->nextFloat();

            foreach ($ordered as $index => $entry) {
                $placed[] = ['position' => ($index + $phase) / count($ordered), 'tiebreak' => $randomizer->nextFloat(), 'block' => $entry['block']];
            }
        }

        usort($placed, fn (array $a, array $b): int => [$a['position'], $a['tiebreak']] <=> [$b['position'], $b['tiebreak']]);

        return array_column($placed, 'block');
    }

    /**
     * Insert padding symbols at evenly spaced points, never inside a stack.
     *
     * @param  list<Symbol>  $strip
     * @param  array<string, int>  $padding
     * @return list<Symbol>
     */
    private static function pad(array $strip, array $padding): array
    {
        $extras = [];

        for ($round = 0; $round < max([0, ...$padding]); $round++) {
            foreach ($padding as $symbol => $count) {
                if ($round < $count) {
                    $extras[] = Symbol::from($symbol);
                }
            }
        }

        if ($extras === []) {
            return $strip;
        }

        $length = count($strip);
        $inserts = [];

        foreach ($extras as $index => $symbol) {
            $position = intdiv((2 * $index + 1) * $length, 2 * count($extras));

            while ($position > 0 && $position < $length && $strip[$position - 1] === $strip[$position]) {
                $position++;
            }

            $inserts[$position][] = $symbol;
        }

        $padded = [];

        foreach ($strip as $position => $symbol) {
            array_push($padded, ...($inserts[$position] ?? []), ...[$symbol]);
        }

        return [...$padded, ...($inserts[$length] ?? [])];
    }

    /**
     * Spin every reel to a random stop and return the visible window.
     *
     * @return array{grid: list<list<Symbol>>, stops: list<int>}
     */
    public function spin(Randomizer $randomizer): array
    {
        $stops = array_map(fn (array $strip): int => $randomizer->getInt(0, count($strip) - 1), $this->strips);

        return ['grid' => $this->windowAt($stops), 'stops' => $stops];
    }

    /**
     * The visible symbols for each reel, indexed [reel][row], when the reels stop at the given positions.
     *
     * @param  list<int>  $stops
     * @return list<list<Symbol>>
     */
    public function windowAt(array $stops): array
    {
        $grid = [];

        foreach ($this->strips as $reel => $strip) {
            $length = count($strip);

            for ($row = 0; $row < self::ROWS; $row++) {
                $grid[$reel][$row] = $strip[($stops[$reel] + $row) % $length];
            }
        }

        return $grid;
    }
}
