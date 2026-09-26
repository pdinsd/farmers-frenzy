<?php

namespace App\Console\Commands;

use GdImage;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('farmers-frenzy:extract-artwork {--source=artwork/farmers_frenzy} {--output=public/images/farmers-frenzy}')]
#[Description('Build the Farmers Frenzy symbols, logo, jackpot badges and scene from the reference artwork')]
class ExtractFarmersFrenzyArtwork extends Command
{
    /**
     * Symbols cut from `all_symboles.png` (a 4 x 2 grid), keyed by the game's
     * symbol id, as [column, row].
     *
     * @var array<string, array{0: int, 1: int}>
     */
    private const SYMBOL_GRID = [
        'nine' => [0, 0],
        'ten' => [1, 0],
        'jack' => [2, 0],
        'queen' => [3, 0],
        'king' => [0, 1],
        'tractor' => [1, 1],
        'dog' => [2, 1],
        'barn' => [3, 1],
    ];

    /**
     * Symbols cut from `new_cover.png`, as [x, y, width, height].
     *
     * @var array<string, array{0: int, 1: int, 2: int, 3: int}>
     */
    private const COVER_CELLS = [
        'farmer' => [620, 140, 480, 460],
    ];

    /**
     * Symbols drawn as a whole square image, keyed by the game's symbol id.
     *
     * @var array<string, string>
     */
    private const WHOLE_IMAGE_SYMBOLS = [
        'moon' => 'cow_girl.png',
        'milk_bottle' => 'milk_bottle.png',
        'door' => 'door.png',
    ];

    /**
     * Jackpot badges: source file and the circle of hay inside it, as [centerX, centerY, radius].
     *
     * @var array<string, array{source: string, circle: array{0: int, 1: int, 2: int}}>
     */
    private const BADGES = [
        'badge-grand' => ['source' => 'grand.png', 'circle' => [765, 512, 580]],
        'badge-major' => ['source' => 'major.png', 'circle' => [770, 512, 580]],
        'badge-minor' => ['source' => 'minor.png', 'circle' => [627, 640, 580]],
        'badge-mini' => ['source' => 'mini.png', 'circle' => [630, 640, 580]],
    ];

    private const GRID_COLUMN_WIDTH = 430;

    private const GRID_ROW_HEIGHT = 458;

    private const GRID_BORDER = 7;

    private const CELL_WIDTH = 168;

    private const CELL_HEIGHT = 164;

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $source = base_path($this->option('source'));
        $output = base_path($this->option('output'));

        foreach (['all_symboles.png', 'new_cover.png', 'haybale.png', 'title.png', ...self::WHOLE_IMAGE_SYMBOLS] as $file) {
            if (! is_file("{$source}/{$file}")) {
                $this->error("Artwork not found: {$source}/{$file}");

                return self::FAILURE;
            }
        }

        if (! is_dir($output)) {
            mkdir($output, 0755, true);
        }

        $grid = imagecreatefrompng("{$source}/all_symboles.png");

        foreach (self::SYMBOL_GRID as $name => [$column, $row]) {
            $x = $column * self::GRID_COLUMN_WIDTH + self::GRID_BORDER;
            $y = $row * self::GRID_ROW_HEIGHT + self::GRID_BORDER;
            $size = [self::GRID_COLUMN_WIDTH - 2 * self::GRID_BORDER, self::GRID_ROW_HEIGHT - 2 * self::GRID_BORDER];
            $this->save($this->resample($grid, $x, $y, ...$size), "{$output}/{$name}.png");
        }

        $cover = imagecreatefrompng("{$source}/new_cover.png");

        foreach (self::COVER_CELLS as $name => [$x, $y, $width, $height]) {
            $this->save($this->resample($cover, $x, $y, $width, $height), "{$output}/{$name}.png");
        }

        $this->save($this->haybale(imagecreatefrompng("{$source}/haybale.png")), "{$output}/bale.png");

        foreach (self::WHOLE_IMAGE_SYMBOLS as $name => $file) {
            $image = imagecreatefrompng("{$source}/{$file}");
            $this->save($this->resample($image, 0, 0, imagesx($image), imagesy($image)), "{$output}/{$name}.png");
        }

        foreach (self::BADGES as $name => ['source' => $file, 'circle' => $circle]) {
            $this->save($this->circle(imagecreatefrompng("{$source}/{$file}"), ...$circle), "{$output}/{$name}.png");
        }

        $title = imagecreatefrompng("{$source}/title.png");
        $this->save($this->scaled($title, 20, 185, 2140, 360, 1000), "{$output}/logo.png");

        $scene = $this->scaled($cover, 0, 0, imagesx($cover), imagesy($cover), 1000);
        imagejpeg($scene, "{$output}/scene.jpg", 86);
        $this->line("  {$output}/scene.jpg");

        $this->info('Extracted Farmers Frenzy artwork to '.$output);

        return self::SUCCESS;
    }

    private function resample(GdImage $source, int $x, int $y, int $width, int $height): GdImage
    {
        $cell = imagecreatetruecolor(self::CELL_WIDTH, self::CELL_HEIGHT);
        imagecopyresampled($cell, $source, 0, 0, $x, $y, self::CELL_WIDTH, self::CELL_HEIGHT, $width, $height);

        return $cell;
    }

    private function scaled(GdImage $source, int $x, int $y, int $width, int $height, int $targetWidth): GdImage
    {
        $targetHeight = (int) round($height * $targetWidth / $width);
        $image = imagecreatetruecolor($targetWidth, $targetHeight);
        imagecopyresampled($image, $source, 0, 0, $x, $y, $targetWidth, $targetHeight, $width, $height);

        return $image;
    }

    /**
     * The hay bale on its black backdrop, with the backdrop made transparent so
     * the bale sits on the reel like a bale does.
     */
    private function haybale(GdImage $source): GdImage
    {
        $bale = imagecreatetruecolor(self::CELL_WIDTH, self::CELL_HEIGHT);
        imagecopyresampled($bale, $source, 0, 0, 0, 0, self::CELL_WIDTH, self::CELL_HEIGHT, imagesx($source), imagesy($source));

        return $this->withAlpha($bale, function (int $red, int $green, int $blue): int {
            $brightness = max($red, $green, $blue);

            return (int) round(127 * (1 - max(0.0, min(1.0, ($brightness - 25) / 45))));
        });
    }

    /**
     * A round badge cut out of the jackpot artwork with a soft edge.
     */
    private function circle(GdImage $source, int $centerX, int $centerY, int $radius): GdImage
    {
        $size = 176;
        $badge = imagecreatetruecolor($size, $size);
        imagecopyresampled($badge, $source, 0, 0, $centerX - $radius, $centerY - $radius, $size, $size, 2 * $radius, 2 * $radius);
        $middle = ($size - 1) / 2;

        return $this->withAlpha($badge, function (int $red, int $green, int $blue, int $x, int $y) use ($middle, $size): int {
            $distance = sqrt(($x - $middle) ** 2 + ($y - $middle) ** 2) / ($size / 2);

            return (int) round(127 * max(0.0, min(1.0, ($distance - 0.94) / 0.06)));
        });
    }

    /**
     * Copy an opaque image into one with an alpha channel, using the callback
     * to choose each pixel's transparency (0 opaque to 127 transparent).
     *
     * @param  callable(int, int, int, int, int): int  $alphaFor
     */
    private function withAlpha(GdImage $opaque, callable $alphaFor): GdImage
    {
        $width = imagesx($opaque);
        $height = imagesy($opaque);
        $image = imagecreatetruecolor($width, $height);
        imagealphablending($image, false);
        imagesavealpha($image, true);

        for ($y = 0; $y < $height; $y++) {
            for ($x = 0; $x < $width; $x++) {
                $rgb = imagecolorat($opaque, $x, $y);
                [$red, $green, $blue] = [($rgb >> 16) & 0xFF, ($rgb >> 8) & 0xFF, $rgb & 0xFF];
                imagesetpixel($image, $x, $y, imagecolorallocatealpha($image, $red, $green, $blue, $alphaFor($red, $green, $blue, $x, $y)));
            }
        }

        return $image;
    }

    private function save(GdImage $image, string $path): void
    {
        imagepng($image, $path, 9);
        $this->line("  {$path}");
    }
}
