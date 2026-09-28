<?php

namespace Tests\Integration\Seed;

use Mage;
use Mage_Customer_Model_Customer;
use Mage_Core_Model_Store;
use Mage_Sales_Model_Order;
use Mage_Sales_Model_Order_Payment;
use Tests\Base\AbstractTestCase;

/**
 * Proves the config, customer, store, order and mock seeders really work.
 *
 * These are integration tests in the strongest sense: each one runs the actual
 * `ddev seed` script in a subprocess and then asserts against the database.
 * They intentionally leave the seeded data behind — that data IS the point of
 * the seed system, and every seeder is idempotent so the next run is a no-op.
 */
class CoreSeedTest extends AbstractTestCase
{
    use SeedRunner;

    /**
     * Config paths the order seeder cannot work without.
     */
    private const REQUIRED_CHECKOUT_CONFIG = [
        'carriers/flatrate/active' => '1',
        'payment/checkmo/active'   => '1',
    ];

    public function testConfigSeederPinsModuleConfigAndCheckoutPrerequisites(): void
    {
        $this->runSeeder('config');

        $this->assertConfigEquals('0', 'frontend/split_frontend_catalog');

        foreach (self::REQUIRED_CHECKOUT_CONFIG as $path => $expected) {
            self::assertSame(
                $expected,
                (string) Mage::getStoreConfig($path),
                sprintf('Config "%s" was not seeded; the order seeder depends on it.', $path),
            );
        }
    }

    public function testConfigSeederIsIdempotent(): void
    {
        $this->runSeeder('config');
        $first = (string) Mage::getStoreConfig('carriers/flatrate/price');

        $this->runSeeder('config');
        $second = (string) Mage::getStoreConfig('carriers/flatrate/price');

        self::assertSame($first, $second, 'Running the config seeder twice changed a value.');
    }

    public function testCustomerSeederCreatesAnImmediatelyUsableAccount(): void
    {
        $this->runSeeder('config');
        $this->runSeeder('customers', ['3']);

        $email    = seed_customer_email(1);
        $customer = seed_find_customer($email);

        self::assertInstanceOf(
            Mage_Customer_Model_Customer::class,
            $customer,
            sprintf('Seeded customer "%s" was not found.', $email),
        );

        // A leftover confirmation key means the account exists but cannot log
        // in, and the failure looks like a wrong password.
        self::assertNull(
            $customer->getConfirmation(),
            'Seeded customer still carries a confirmation key and cannot log in.',
        );

        self::assertNotNull(
            $customer->getDefaultBillingAddress(),
            'Seeded customer has no default billing address and cannot check out.',
        );
    }

    public function testCustomerSeederIsIdempotent(): void
    {
        $this->runSeeder('customers', ['3']);
        $before = $this->countSeededEntities();

        $this->runSeeder('customers', ['3']);
        $after = $this->countSeededEntities();

        self::assertSame(
            $before['customers'],
            $after['customers'],
            'Running the customer seeder twice changed the customer count.',
        );
        self::assertGreaterThanOrEqual(3, $after['customers']);
    }

    public function testStoreSeederCreatesStoreViewsIdempotently(): void
    {
        $this->runSeeder('stores');
        $before = $this->countSeededEntities();

        $store = Mage::getModel('core/store');

        self::assertInstanceOf(Mage_Core_Model_Store::class, $store);

        $store->load(SEED_CODE_PREFIX . 'de', 'code');

        self::assertNotNull(
            seed_optional_id($store->getId()),
            sprintf('Store view "%sde" was not created.', SEED_CODE_PREFIX),
        );

        $this->runSeeder('stores');
        $after = $this->countSeededEntities();

        self::assertSame(
            $before['stores'],
            $after['stores'],
            'Running the store seeder twice changed the store view count.',
        );
    }

    public function testMockSeederLinksFixturesIntoTheDocroot(): void
    {
        $this->runSeeder('mock');

        $link = seed_openmage_root() . '/mock';

        self::assertTrue(is_link($link), sprintf('"%s" is not a symlink.', $link));
        self::assertFileExists($link . '/sample.json');
        self::assertFileExists($link . '/sample.xml');

        $payload = file_get_contents($link . '/sample.json');

        self::assertIsString($payload);

        $decoded = json_decode($payload, true);

        self::assertIsArray($decoded, 'The mock JSON fixture is not valid JSON.');
        self::assertArrayHasKey('items', $decoded);

        // Re-running must not fail on the symlink that is already there.
        $this->runSeeder('mock');
        self::assertTrue(is_link($link));
    }

    public function testOrderSeederPlacesAnOrderPaidWithCheckmo(): void
    {
        $this->runSeeder('config');
        $this->runSeeder('categories');
        $this->runSeeder('products', ['3']);
        $this->runSeeder('customers', ['2']);
        $this->runSeeder('orders', ['1']);

        $orders = seed_seeded_orders();

        self::assertNotSame([], $orders, 'The order seeder created no orders.');

        $orderId = seed_id($orders[0]->getId(), 'Seeded order');
        $order   = Mage::getModel('sales/order');

        self::assertInstanceOf(Mage_Sales_Model_Order::class, $order);

        $order->load($orderId);
        $payment = $order->getPayment();

        self::assertInstanceOf(
            Mage_Sales_Model_Order_Payment::class,
            $payment,
            'The seeded order has no payment record.',
        );

        // importData(['method' => ...]) is the only way to set this; the magic
        // setMethod() setter would leave it empty.
        self::assertSame('checkmo', $payment->getMethod());
        self::assertSame('flatrate_flatrate', $order->getShippingMethod());
        self::assertGreaterThan(0.0, (float) $order->getGrandTotal());
        self::assertGreaterThan(0, count($order->getAllItems()));
    }

    public function testOrderSeederIsIdempotent(): void
    {
        $this->runSeeder('config');
        $this->runSeeder('categories');
        $this->runSeeder('products', ['3']);
        $this->runSeeder('customers', ['2']);
        $this->runSeeder('orders', ['1']);

        $before = $this->countSeededEntities();

        $this->runSeeder('orders', ['1']);
        $after = $this->countSeededEntities();

        self::assertSame(
            $before['orders'],
            $after['orders'],
            'Running the order seeder twice created extra orders.',
        );
        self::assertGreaterThanOrEqual(1, $after['orders']);
    }
}
