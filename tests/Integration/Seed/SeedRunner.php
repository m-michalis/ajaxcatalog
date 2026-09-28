<?php

namespace Tests\Integration\Seed;

use Mage;
use Mage_Core_Model_Config;
use Mage_Core_Model_App;
use PHPUnit\Framework\ExpectationFailedException;

// The seed library is the subject under test, and it also exposes the prefix
// constants and lookup helpers the assertions need. Requiring generator.php
// pulls in lib.php, which is a no-op here: Mage.php is already required and
// Mage::app() is already booted by tests/bootstrap.php.
require_once dirname(__DIR__, 2) . '/fixtures/seed/generator.php';

/**
 * Runs the seed scripts in a subprocess and re-syncs this process afterwards.
 *
 * The seeders are CLI scripts that end in exit(0), so they cannot simply be
 * required into the PHPUnit process — the first one would take the test runner
 * down with it. Running them out of process is also the honest test: it is
 * exactly what `ddev seed` does.
 */
trait SeedRunner
{
    /**
     * Run one seeder and return its combined stdout/stderr.
     *
     * @param string       $type      Seeder name, i.e. tests/fixtures/seed/<type>.php
     * @param list<string> $arguments Extra CLI arguments, e.g. ['25']
     *
     * @throws ExpectationFailedException When the seeder exits non-zero
     */
    protected function runSeeder(string $type, array $arguments = []): string
    {
        $script = dirname(__DIR__, 2) . '/fixtures/seed/' . $type . '.php';

        self::assertFileExists($script, sprintf('Seeder "%s" does not exist.', $type));

        $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($script);

        foreach ($arguments as $argument) {
            $command .= ' ' . escapeshellarg($argument);
        }

        $output = [];
        $status = 0;

        exec($command . ' 2>&1', $output, $status);

        $combined = implode(PHP_EOL, $output);

        self::assertSame(0, $status, sprintf(
            'Seeder "%s" exited with status %d:%s%s',
            $type,
            $status,
            PHP_EOL,
            $combined,
        ));

        $this->resyncAfterSeed();

        return $combined;
    }

    /**
     * Count the rows a seeder is responsible for, for idempotence assertions.
     *
     * @return array{products: int, customers: int, orders: int, stores: int}
     */
    protected function countSeededEntities(): array
    {
        return [
            'products'  => count(seed_seeded_products()),
            'customers' => count(seed_seeded_customers()),
            'orders'    => count(seed_seeded_orders()),
            'stores'    => count(seed_app()->getStores(false)),
        ];
    }

    /**
     * Pull the subprocess's changes into this process.
     *
     * The seeder committed to the database, but this process is still holding
     * the config XML, the store objects and the EAV metadata it read at
     * bootstrap. Without this every assertion after a seeder run would read
     * pre-seed state.
     */
    protected function resyncAfterSeed(): void
    {
        $config = Mage::getConfig();

        if ($config instanceof Mage_Core_Model_Config) {
            $config->reinit();
        }

        $app = Mage::app();

        if ($app instanceof Mage_Core_Model_App) {
            $app->reinitStores();
        }

        // Attributes created by a seeder are invisible until the cached EAV
        // metadata this process read at bootstrap is dropped.
        seed_reset_eav_cache();
    }
}
