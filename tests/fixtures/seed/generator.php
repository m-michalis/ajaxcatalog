<?php

/**
 * Deterministic value generation for the seeders.
 *
 * Every function here is a pure mapping from a one-based index to a value, so
 * `ddev seed products 25` produces byte-identical data on every machine and on
 * every re-run. That is what makes the seeders idempotent: re-running does not
 * merely avoid duplicates, it also avoids churn.
 *
 * Never introduce rand(), time() or uniqid() into this file.
 */

require_once __DIR__ . '/lib.php';

// tests/fixtures/seed holds standalone CLI scripts, not a library of
// classes. Plain functions are the right shape here: a seeder is a file you
// run, and `require_once lib.php` is the whole of its dependency graph.
// Wrapping these in a static class would add indirection and buy nothing.
// phpcs:disable Squiz.Functions.GlobalFunction.Found

/**
 * Adjectives cycled through by seed_gen_name().
 */
const SEED_GEN_ADJECTIVES = [
    'Alpine', 'Basalt', 'Cobalt', 'Dune', 'Ember',
    'Frost', 'Granite', 'Harbour', 'Ivory', 'Jade',
];

/**
 * Nouns cycled through by seed_gen_name().
 */
const SEED_GEN_NOUNS = [
    'Router', 'Speaker', 'Monitor', 'Keyboard', 'Headset',
    'Charger', 'Adapter', 'Webcam', 'Docking', 'Tablet',
];

/**
 * Colour labels cycled through by seed_gen_colour().
 */
const SEED_GEN_COLOURS = [
    'Black', 'White', 'Slate', 'Sand', 'Olive', 'Navy', 'Copper',
];

/**
 * RGB triples backing the generated product images, aligned with the colours.
 *
 * @var array<int, array{int, int, int}>
 */
const SEED_GEN_RGB = [
    [32, 32, 32],
    [235, 235, 235],
    [86, 98, 112],
    [214, 194, 155],
    [107, 114, 62],
    [30, 55, 96],
    [176, 106, 58],
];

/**
 * SKU of the Nth seeded product, e.g. 1 becomes "QA-0001".
 */
function seed_gen_sku(int $index): string
{
    return SEED_SKU_PREFIX . str_pad((string) $index, 4, '0', STR_PAD_LEFT);
}

/**
 * Product name of the Nth seeded product.
 */
function seed_gen_name(int $index): string
{
    $adjectives = count(SEED_GEN_ADJECTIVES);
    $nouns      = count(SEED_GEN_NOUNS);

    $adjective = SEED_GEN_ADJECTIVES[($index - 1) % $adjectives];
    $noun      = SEED_GEN_NOUNS[intdiv($index - 1, $adjectives) % $nouns];

    return sprintf('%s%s %s %04d', SEED_LABEL_PREFIX, $adjective, $noun, $index);
}

/**
 * Colour label of the Nth seeded product.
 */
function seed_gen_colour(int $index): string
{
    return SEED_GEN_COLOURS[($index - 1) % count(SEED_GEN_COLOURS)];
}

/**
 * Price of the Nth seeded product, between 9.99 and 99.99.
 */
function seed_gen_price(int $index): float
{
    return round(9.99 + (($index * 7) % 90), 2);
}

/**
 * Stock quantity of the Nth seeded product, between 10 and 99.
 */
function seed_gen_qty(int $index): int
{
    return 10 + (($index * 13) % 90);
}

/**
 * Long description of the Nth seeded product.
 */
function seed_gen_description(int $index): string
{
    return sprintf(
        '%s is generated fixture data produced by tests/fixtures/seed. '
        . 'Colour: %s. Sequence: %d. Nothing here describes a real product; '
        . 'delete it all with "ddev seed clean".',
        seed_gen_name($index),
        seed_gen_colour($index),
        $index,
    );
}

/**
 * Short description of the Nth seeded product.
 */
function seed_gen_short_description(int $index): string
{
    return sprintf('%s fixture product, colour %s.', SEED_LABEL_PREFIX, seed_gen_colour($index));
}

/**
 * Supplier code of the Nth seeded product, for the qa_supplier_code attribute.
 */
function seed_gen_supplier_code(int $index): string
{
    return sprintf('SUP-%03d', (($index - 1) % 12) + 1);
}

/**
 * Write a deterministic PNG for the Nth seeded product and return its path.
 *
 * Returns null when GD is unavailable, which is a warning rather than a
 * failure: products seed fine without images.
 */
function seed_gen_image(int $index): ?string
{
    if (!extension_loaded('gd') || !function_exists('imagecreatetruecolor')) {
        seed_warn('PHP extension "gd" is not loaded; seeding products without images.');

        return null;
    }

    $directory = seed_generated_dir();
    seed_mkdir($directory);

    $path = $directory . '/' . seed_gen_sku($index) . '.png';

    // Deterministic output means an existing file is already correct.
    if (is_file($path)) {
        return $path;
    }

    $image = imagecreatetruecolor(400, 400);

    if ($image === false) {
        seed_warn(sprintf('GD could not allocate an image for %s.', seed_gen_sku($index)));

        return null;
    }

    [$red, $green, $blue] = SEED_GEN_RGB[($index - 1) % count(SEED_GEN_RGB)];

    $background = seed_gen_allocate($image, $red, $green, $blue);
    // Pick a legible foreground from the background's perceived brightness.
    $luminance  = (0.299 * $red) + (0.587 * $green) + (0.114 * $blue);
    $foreground = $luminance > 150.0
        ? seed_gen_allocate($image, 20, 20, 20)
        : seed_gen_allocate($image, 245, 245, 245);

    imagefilledrectangle($image, 0, 0, 399, 399, $background);
    imagerectangle($image, 8, 8, 391, 391, $foreground);
    imagestring($image, 5, 20, 170, seed_gen_sku($index), $foreground);
    imagestring($image, 3, 20, 200, seed_gen_colour($index), $foreground);

    $written = imagepng($image, $path);
    imagedestroy($image);

    if (!$written) {
        seed_warn(sprintf('Could not write generated image to "%s".', $path));

        return null;
    }

    return $path;
}

/**
 * Allocate a colour, falling back to index 0 when the palette is exhausted.
 *
 * imagecolorallocate() is documented as int|false, and every GD drawing
 * function wants a plain int. The channel bounds are declared so callers are
 * checked rather than GD silently wrapping an out-of-range value.
 *
 * @param int<0, 255> $red   Red channel
 * @param int<0, 255> $green Green channel
 * @param int<0, 255> $blue  Blue channel
 */
function seed_gen_allocate(GdImage $image, int $red, int $green, int $blue): int
{
    $colour = imagecolorallocate($image, $red, $green, $blue);

    return $colour === false ? 0 : $colour;
}
