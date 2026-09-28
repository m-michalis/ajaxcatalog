<?php

/**
 * PHPUnit bootstrap for OpenMage module testing.
 *
 * This file initializes the OpenMage application so that tests
 * can use Mage::getModel(), Mage::helper(), etc.
 *
 * Requires: ddev setup-openmage to be run first.
 */

// Location of the OpenMage install. Defaults to ../openmage (created by `ddev setup-openmage`),
// overridable via OPENMAGE_ROOT for non-standard layouts or CI.
$envRoot = getenv('OPENMAGE_ROOT');
$magentoRoot = realpath(($envRoot !== false && $envRoot !== '') ? $envRoot : __DIR__ . '/../openmage');

if ($magentoRoot === false || !file_exists($magentoRoot . '/app/Mage.php')) {
    fwrite(STDERR, "\n");
    fwrite(STDERR, "ERROR: OpenMage is not installed.\n");
    fwrite(STDERR, "Run: ddev setup-openmage\n");
    fwrite(STDERR, "\n");
    exit(1);
}

// Mage core still emits deprecations on modern PHP; they are not actionable from module code.
error_reporting(E_ALL & ~E_DEPRECATED);

// Session ini is left alone: with session.use_cookies=0, the first frontend
// session started in-process fails in session_set_cookie_params(), and
// developer mode turns that warning into an exception.

require_once $magentoRoot . '/app/Mage.php';

// Same as index.php under DDEV: PHP warnings become exceptions, so tests catch them.
Mage::setIsDeveloperMode(true);

// Initialize with admin store (full access to all data)
Mage::app('admin');

// The integration tests assert against the Magento 1.9 sample catalog.
if (!Mage::app()->getDefaultStoreView() instanceof Mage_Core_Model_Store) {
    fwrite(STDERR, "\nERROR: No default store view found.\nRun: ddev setup-openmage --with-sample-data\n\n");
    exit(1);
}

// Register autoloader for test classes
spl_autoload_register(function (string $class): void {
    // Map Tests\* namespace to tests/ directory
    if (str_starts_with($class, 'Tests\\')) {
        $path = __DIR__ . '/' . str_replace('\\', '/', substr($class, 6)) . '.php';
        if (file_exists($path)) {
            require_once $path;
        }
    }
});
