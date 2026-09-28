<?php

/**
 * Seed the configuration the rest of the seed system depends on.
 *
 * Idempotent by nature: saveConfig() is an upsert on core_config_data, so
 * running this twice writes the same rows twice and changes nothing.
 *
 * Usage: ddev seed config
 */

require_once __DIR__ . '/lib.php';

/**
 * Config paths written at default scope, in path => value order.
 *
 * @var array<string, string>
 */
$seedValues = [
    // ── Module under development ────────────────────────────────────────────
    // The listing is not split by stock unless a test opts in.
    SEED_MODULE_SPLIT_CATALOG_PATH => '0',

    // ── Storefront identity ─────────────────────────────────────────────────
    'general/store_information/name'  => 'QA Seed Store',
    'general/store_information/phone' => '+1 555 0100000',
    'general/country/default'         => 'US',
    'general/locale/code'             => 'en_US',
    'general/locale/timezone'         => 'UTC',

    // ── Shipping ────────────────────────────────────────────────────────────
    // orders.php calls collectShippingRates() and then pins
    // flatrate_flatrate; without an active carrier the quote has no rate, the
    // shipping method is rejected and submitOrder() throws.
    'shipping/origin/country_id'    => 'US',
    'shipping/origin/postcode'      => '90210',
    'carriers/flatrate/active'      => '1',
    'carriers/flatrate/name'        => 'Fixed',
    'carriers/flatrate/title'       => 'Flat Rate',
    'carriers/flatrate/type'        => 'I',
    'carriers/flatrate/price'       => '5.00',
    'carriers/flatrate/handling_type' => 'F',
    'carriers/flatrate/sallowspecific' => '0',

    // ── Payment ─────────────────────────────────────────────────────────────
    // checkmo is the only offline method orders.php needs. Quote payment
    // import validates the method is active, so this is not optional.
    'payment/checkmo/active'       => '1',
    'payment/checkmo/title'        => 'Check / Money order',
    'payment/checkmo/order_status' => 'pending',
    'payment/checkmo/allowspecific' => '0',

    // ── Catalog ─────────────────────────────────────────────────────────────
    // Flat tables would need a reindex the seeders deliberately do not run,
    // so seeded products would be invisible on the storefront.
    'catalog/frontend/flat_catalog_product'  => '0',
    'catalog/frontend/flat_catalog_category' => '0',

    // ── Inventory ───────────────────────────────────────────────────────────
    'cataloginventory/item_options/manage_stock'  => '1',
    'cataloginventory/item_options/min_qty'       => '0',
    'cataloginventory/options/show_out_of_stock'  => '1',

    // ── Customers ───────────────────────────────────────────────────────────
    // Confirmation off keeps seeded accounts loggable-in; customers.php also
    // clears the per-account confirmation key as a belt-and-braces measure.
    'customer/create_account/confirm' => '0',
];

$config = seed_config();

foreach ($seedValues as $path => $value) {
    $config->saveConfig($path, $value);
}

// reinit() alone rebuilds the merged XML but leaves already-instantiated store
// objects holding stale values, so reinitStores() has to follow it.
seed_refresh_config();

seed_log(sprintf('  config: wrote %d values at default scope', count($seedValues)));
seed_log(sprintf('  config: module path "%s" pinned', SEED_MODULE_SPLIT_CATALOG_PATH));
seed_log('  config: flatrate shipping + check/money order payment active');

exit(0);
