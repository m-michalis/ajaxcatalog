<?php

/**
 * Seed placed orders by driving a real quote through the checkout service.
 *
 * This is the full quote -> order pipeline rather than a fabricated
 * sales_flat_order row, so totals, stock movements and the payment record all
 * end up consistent.
 *
 * Idempotent: orders carry no code of their own, so existing seeded orders are
 * counted through the qa- customer e-mail that checkout copies onto them, and
 * only the shortfall is created.
 *
 * Requires: ddev seed config && ddev seed products && ddev seed customers
 *
 * Usage: ddev seed orders [count]     (default 2)
 */

require_once __DIR__ . '/lib.php';

// tests/fixtures/seed holds standalone CLI scripts, not a library of
// classes. Plain functions are the right shape here: a seeder is a file you
// run, and `require_once lib.php` is the whole of its dependency graph.
// Wrapping these in a static class would add indirection and buy nothing.
// phpcs:disable Squiz.Functions.GlobalFunction.Found

/**
 * Shipping method the seeded orders are placed with.
 */
const SEED_ORDER_SHIPPING_METHOD = 'flatrate_flatrate';

/**
 * Payment method the seeded orders are placed with.
 */
const SEED_ORDER_PAYMENT_METHOD = 'checkmo';

/**
 * Build a quote for one customer/product pair and submit it as an order.
 *
 * @param Mage_Customer_Model_Customer $customer Seeded account placing the order
 * @param Mage_Catalog_Model_Product   $product  Seeded product to buy
 * @param int                          $storeId  Frontend store view id
 * @param int                          $index    One-based sequence number
 *
 * @throws RuntimeException When the order cannot be built
 */
function seed_place_order(
    Mage_Customer_Model_Customer $customer,
    Mage_Catalog_Model_Product $product,
    int $storeId,
    int $index,
): Mage_Sales_Model_Order {
    // Reload the product in the frontend store scope; a product loaded in
    // admin scope has no final price and the quote item would be free.
    $storeProduct = seed_model('catalog/product', Mage_Catalog_Model_Product::class);
    $storeProduct->setStoreId($storeId);
    $storeProduct->load(seed_id($product->getId(), 'Seeded product'));

    $storeCustomer = seed_model('customer/customer', Mage_Customer_Model_Customer::class);
    $storeCustomer->setWebsiteId(seed_default_website_id());
    $storeCustomer->load(seed_id($customer->getId(), 'Seeded customer'));

    $quote = seed_model('sales/quote', Mage_Sales_Model_Quote::class);
    $quote->setStoreId($storeId);
    $quote->assignCustomer($storeCustomer);
    $quote->addProduct($storeProduct, 1);

    $addressData = seed_address_data($index);

    $billing = $quote->getBillingAddress();
    $billing->addData($addressData);
    $billing->setSaveInAddressBook(0);

    $shipping = $quote->getShippingAddress();
    $shipping->addData($addressData);
    $shipping->setSaveInAddressBook(0);
    $shipping->setCollectShippingRates(true);
    $shipping->collectShippingRates();
    $shipping->setShippingMethod(SEED_ORDER_SHIPPING_METHOD);
    $shipping->setPaymentMethod(SEED_ORDER_PAYMENT_METHOD);

    // importData(), NOT setMethod(): Mage_Sales_Model_Quote_Payment has no
    // setMethod() and the magic setter would only stash a data key that the
    // order converter never reads, so the order would be saved without a
    // payment method. importData() also validates that the method is active.
    $quote->getPayment()->importData(['method' => SEED_ORDER_PAYMENT_METHOD]);

    $quote->collectTotals();
    $quote->save();

    $service = Mage::getModel('sales/service_quote', $quote);

    if (!$service instanceof Mage_Sales_Model_Service_Quote) {
        throw new RuntimeException('Model "sales/service_quote" could not be instantiated.');
    }

    $order = $service->submitOrder();

    if (!$order instanceof Mage_Sales_Model_Order) {
        throw new RuntimeException('submitOrder() did not return an order.');
    }

    $quote->setIsActive(false);
    $quote->save();

    return $order;
}

$count = seed_int_arg(2);

// Quotes cannot live in the admin store: prices, tax and shipping are all
// resolved against a frontend store view.
$storeId = seed_default_store_id();
seed_app()->setCurrentStore($storeId);

$customers = seed_seeded_customers();
$products  = seed_seeded_products();

if ($customers === []) {
    seed_fail('No seeded customers found. Run "ddev seed customers" first.');
}

if ($products === []) {
    seed_fail('No seeded products found. Run "ddev seed products" first.');
}

$existing = count(seed_seeded_orders());
$toCreate = max(0, $count - $existing);

if ($toCreate === 0) {
    seed_log(sprintf('  orders: %d seeded orders already exist, nothing to do', $existing));

    exit(0);
}

$created = 0;
$failed  = 0;

// Hoisted out of the loop: both lists are fixed for the whole run.
$customerCount = count($customers);
$productCount  = count($products);

for ($index = $existing + 1; $index <= $count; $index++) {
    // Cycle through the seeded accounts and products so repeated runs spread
    // orders across them instead of piling everything onto the first pair.
    $customer = $customers[($index - 1) % $customerCount];
    $product  = $products[($index - 1) % $productCount];

    $customerEmail = $customer->getEmail();
    $productSku    = $product->getSku();

    try {
        $order = seed_place_order($customer, $product, $storeId, $index);
    } catch (Exception $exception) {
        seed_warn(sprintf(
            'Could not place order %d for %s: %s',
            $index,
            is_string($customerEmail) ? $customerEmail : '(unknown customer)',
            $exception->getMessage(),
        ));
        $failed++;

        continue;
    }

    seed_log(sprintf(
        '  orders: created %s for %s (%s, %s)',
        (string) $order->getIncrementId(),
        is_string($customerEmail) ? $customerEmail : '(unknown customer)',
        is_string($productSku) ? $productSku : '(unknown sku)',
        SEED_ORDER_PAYMENT_METHOD,
    ));
    $created++;
}

seed_log(sprintf(
    '  orders: %d created, %d failed, %d seeded orders in total',
    $created,
    $failed,
    count(seed_seeded_orders()),
));

exit(0);
