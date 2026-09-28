<?php

/**
 * Guarantee one login-able storefront customer and print its credentials.
 *
 * Cypress calls this before a storefront login spec so the spec never depends
 * on `ddev seed customers` having been run first, and never hardcodes a
 * password of its own.
 *
 * The account produced here is byte-identical to the one
 * tests/fixtures/seed/customers.php produces for the same index, so the two are
 * interchangeable and running both is a no-op.
 *
 * Two failure modes are handled, both of which look like "wrong password" from
 * the storefront:
 *
 *   1. a programmatically created account inherits the "confirm your e-mail"
 *      flow, and an account carrying a confirmation key cannot log in;
 *   2. an account left over from an earlier run may have any password at all.
 *
 * Both are fixed unconditionally on every invocation, which is what makes this
 * script safe to call from a beforeEach().
 *
 * Bootstrapping is delegated to the seed library — this file adds an entry
 * point, never a second way to boot OpenMage.
 *
 * Usage:
 *   php tests/Support/e2e_customer.php [index]      (default 1)
 *
 * stdout: {"email":"qa-customer-001@example.test","password":"QaSeed123!"}
 * stderr: human readable progress
 *
 * stdout carries the JSON payload and NOTHING else — Cypress parses it — so
 * every progress line goes to stderr.
 */

require_once __DIR__ . '/../fixtures/seed/lib.php';

// tests/Support holds standalone CLI entry points, not a library of classes,
// exactly like tests/fixtures/seed. Plain functions are the right shape.
// phpcs:disable Squiz.Functions.GlobalFunction.Found

/**
 * Print one progress line on stderr, keeping stdout clean for the JSON.
 */
function e2e_customer_note(string $message): void
{
    fwrite(STDERR, $message . PHP_EOL);
}

$index = seed_int_arg(1);
$email = seed_customer_email($index);

$customer = seed_find_customer($email);

if (!$customer instanceof Mage_Customer_Model_Customer) {
    $address = seed_address_data($index);

    // The stock "General" group is id 1, but a re-configured install can point
    // elsewhere, so read the configured default and only fall back on 1.
    $groupId = seed_optional_id(
        Mage::getStoreConfig('customer/create_account/default_group', seed_default_store_id()),
    ) ?? 1;

    $customer = seed_model('customer/customer', Mage_Customer_Model_Customer::class);
    $customer->setStore(seed_default_store());
    $customer->setWebsiteId(seed_default_website_id());
    $customer->setGroupId($groupId);
    $customer->setFirstname($address['firstname']);
    $customer->setLastname($address['lastname']);
    $customer->setEmail($email);

    e2e_customer_note(sprintf('  e2e customer: creating %s', $email));
} else {
    e2e_customer_note(sprintf('  e2e customer: refreshing %s', $email));
}

// Cleared AND force-confirmed: the flag stops the resource generating a new key
// in _beforeSave(), the null clears any key an earlier run left behind.
$customer->setConfirmation(null);
$customer->setForceConfirmed(true);
$customer->setPassword(SEED_CUSTOMER_PASSWORD);
$customer->save();

// Read back from the database rather than trusting the in-memory object: this
// is the only check that proves the account is really usable.
$persisted = seed_find_customer($email);

if (!$persisted instanceof Mage_Customer_Model_Customer) {
    seed_fail(sprintf('Customer "%s" could not be re-loaded after save.', $email));
}

$confirmation = $persisted->getConfirmation();

if ($confirmation !== null && $confirmation !== '') {
    seed_fail(sprintf('Customer "%s" still carries a confirmation key and cannot log in.', $email));
}

e2e_customer_note(sprintf('  e2e customer: %s is ready (id %s)', $email, (string) $persisted->getId()));

fwrite(STDOUT, json_encode(
    ['email' => $email, 'password' => SEED_CUSTOMER_PASSWORD],
    JSON_THROW_ON_ERROR,
) . PHP_EOL);

exit(0);
