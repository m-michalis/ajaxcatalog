<?php

/**
 * Seed customer accounts that can log in and check out immediately.
 *
 * Two traps are handled here:
 *
 *  1. A programmatically created account inherits the storefront's
 *     "confirm your e-mail" flow. If a confirmation key ends up on the row the
 *     account exists but cannot log in and the failure looks like a wrong
 *     password. The key is cleared before AND after save, then verified
 *     against a fresh read.
 *  2. A customer with no default billing/shipping address cannot complete
 *     checkout, so one is attached on creation.
 *
 * Idempotent: accounts are looked up by e-mail first.
 *
 * Usage: ddev seed customers [count]     (default 3)
 */

require_once __DIR__ . '/lib.php';

// tests/fixtures/seed holds standalone CLI scripts, not a library of
// classes. Plain functions are the right shape here: a seeder is a file you
// run, and `require_once lib.php` is the whole of its dependency graph.
// Wrapping these in a static class would add indirection and buy nothing.
// phpcs:disable Squiz.Functions.GlobalFunction.Found

/**
 * Does this customer already have at least one address?
 */
function seed_customer_has_address(Mage_Customer_Model_Customer $customer): bool
{
    foreach ($customer->getAddressesCollection() as $address) {
        if ($address instanceof Mage_Customer_Model_Address) {
            return true;
        }
    }

    return false;
}

$count     = seed_int_arg(3);
$store     = seed_default_store();
$storeId   = seed_default_store_id();
$websiteId = seed_default_website_id();

// The stock "General" group is id 1, but a re-configured install can point
// elsewhere, so read the configured default and only fall back on 1.
$groupId = seed_optional_id(Mage::getStoreConfig('customer/create_account/default_group', $storeId)) ?? 1;

$created   = 0;
$skipped   = 0;
$repaired  = 0;
$addresses = 0;

for ($index = 1; $index <= $count; $index++) {
    $email   = seed_customer_email($index);
    $address = seed_address_data($index);
    $existing = seed_find_customer($email);

    if (!$existing instanceof Mage_Customer_Model_Customer) {
        $customer = seed_model('customer/customer', Mage_Customer_Model_Customer::class);
        $customer->setStore($store);
        $customer->setWebsiteId($websiteId);
        $customer->setGroupId($groupId);
        $customer->setFirstname((string) $address['firstname']);
        $customer->setLastname((string) $address['lastname']);
        $customer->setEmail($email);
        $customer->setPassword(SEED_CUSTOMER_PASSWORD);

        // Cleared before save and marked force-confirmed so the resource does
        // not generate a key in _beforeSave().
        $customer->setConfirmation(null);
        $customer->setForceConfirmed(true);

        $customer->save();

        seed_log(sprintf('  customers: created %s (id %s)', $email, (string) $customer->getId()));
        $created++;
    } else {
        seed_log(sprintf('  customers: %s already exists, skipping', $email));
        $skipped++;
    }

    // Read back from the database rather than trusting the in-memory object:
    // this is the only check that proves the account is really usable.
    $persisted = seed_find_customer($email);

    if (!$persisted instanceof Mage_Customer_Model_Customer) {
        seed_warn(sprintf('Customer "%s" could not be re-loaded after save.', $email));

        continue;
    }

    $confirmation = $persisted->getConfirmation();

    if ($confirmation !== null && $confirmation !== '') {
        $persisted->setConfirmation(null);
        $persisted->setForceConfirmed(true);
        $persisted->save();
        seed_log(sprintf('  customers: cleared confirmation key on %s', $email));
        $repaired++;
    }

    if (seed_customer_has_address($persisted)) {
        continue;
    }

    $customerAddress = seed_model('customer/address', Mage_Customer_Model_Address::class);
    $customerAddress->setData($address);
    $customerAddress->setCustomerId(seed_id($persisted->getId(), 'Seeded customer'));
    $customerAddress->setIsDefaultBilling(1);
    $customerAddress->setIsDefaultShipping(1);
    $customerAddress->setSaveInAddressBook(1);
    $customerAddress->save();

    seed_log(sprintf('  customers: added default billing/shipping address to %s', $email));
    $addresses++;
}

seed_log(sprintf(
    '  customers: %d created, %d already present, %d confirmation keys cleared, %d addresses added',
    $created,
    $skipped,
    $repaired,
    $addresses,
));
seed_log(sprintf('  customers: password for every seeded account is "%s"', SEED_CUSTOMER_PASSWORD));

exit(0);
