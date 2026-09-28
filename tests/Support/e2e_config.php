<?php

/**
 * Apply individual config values, for Cypress to call through `ddev exec`.
 *
 * The seeders own the bulk configuration (`ddev seed config`). This script
 * exists for the one thing they cannot do: pin a single path to a known value
 * immediately before a spec asserts on it, so the assertion does not depend on
 * whatever the previous spec left behind.
 *
 * Bootstrapping is delegated to the seed library — this file adds an entry
 * point, never a second way to boot OpenMage.
 *
 * Usage:
 *   php tests/Support/e2e_config.php <path>=<value> [<path>=<value> ...]
 *
 * Example:
 *   php tests/Support/e2e_config.php catalog/frontend/split_frontend_catalog=0
 *
 * Values are written at DEFAULT scope only. Website and store scope are not
 * exposed on purpose: a spec that needs them should drive the admin UI, which
 * is what the scope switcher assertions are for.
 */

require_once __DIR__ . '/../fixtures/seed/lib.php';

// tests/Support holds standalone CLI entry points, not a library of classes,
// exactly like tests/fixtures/seed. Plain functions are the right shape.
// phpcs:disable Squiz.Functions.GlobalFunction.Found

/**
 * The CLI arguments, minus the script name.
 *
 * @return list<string>
 */
function e2e_config_arguments(): array
{
    $argv = $_SERVER['argv'] ?? null;

    if (!is_array($argv)) {
        return [];
    }

    $arguments = [];

    foreach (array_slice($argv, 1) as $argument) {
        if (is_string($argument) && $argument !== '') {
            $arguments[] = $argument;
        }
    }

    return $arguments;
}

/**
 * Split "path=value" into its two halves.
 *
 * substr() rather than explode(): the value may itself contain "=" (a base64
 * blob, a query string), and only the FIRST separator delimits the path.
 *
 * @return array{string, string}
 */
function e2e_config_split(string $pair): array
{
    $position = strpos($pair, '=');

    if ($position === false || $position === 0) {
        seed_fail(sprintf('Malformed argument "%s". Expected <path>=<value>.', $pair));
    }

    $path = trim(substr($pair, 0, $position));

    if ($path === '') {
        seed_fail(sprintf('Malformed argument "%s". The config path is empty.', $pair));
    }

    return [$path, substr($pair, $position + 1)];
}

$pairs = e2e_config_arguments();

if ($pairs === []) {
    seed_fail('No values given. Usage: php tests/Support/e2e_config.php <path>=<value> [...]');
}

$config = seed_config();

foreach ($pairs as $pair) {
    [$path, $value] = e2e_config_split($pair);

    $config->saveConfig($path, $value);
    seed_log(sprintf('  e2e config: %s = %s', $path, $value));
}

// reinit() alone rebuilds the merged XML but leaves already-instantiated store
// objects holding stale values, so the storefront would keep serving the old
// setting for the rest of the request. seed_refresh_config() does both.
seed_refresh_config();

seed_log(sprintf('  e2e config: applied %d value(s) at default scope', count($pairs)));

exit(0);
