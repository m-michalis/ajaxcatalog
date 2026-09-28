<?php

/**
 * Shared bootstrap and helpers for the seed scripts in this directory.
 *
 * Every seeder starts with `require_once __DIR__ . '/lib.php';`, which boots
 * OpenMage in admin scope and exposes the helpers below. Nothing here is
 * PHPUnit-aware: seeders are plain CLI scripts driven by `ddev seed`.
 *
 * Everything a seeder writes carries one of the prefixes declared below, and
 * that prefix is the ONLY contract clean.php relies on. Add a seeder, prefix
 * its data too, or `ddev seed clean` will leave the rows behind.
 *
 * Every Mage::* factory is loosely typed upstream (Mage::getModel() is
 * documented as `false|Mage_Core_Model_Abstract|object`), so each accessor
 * below narrows the result and fails loudly instead of handing a half-typed
 * object to a seeder.
 */

// The Composer path repo symlinks the project into openmage/vendor/, which puts
// these scripts inside the docroot. They write config and delete data, so they
// must never run from a web request.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}

// tests/fixtures/seed holds standalone CLI scripts, not a library of
// classes. Plain functions are the right shape here: a seeder is a file you
// run, and `require_once lib.php` is the whole of its dependency graph.
// Wrapping these in a static class would add indirection and buy nothing.
// phpcs:disable Squiz.Functions.GlobalFunction.Found

/**
 * Prefix for every seeded product SKU.
 */
const SEED_SKU_PREFIX = 'QA-';

/**
 * Local part prefix for every seeded customer e-mail address.
 */
const SEED_EMAIL_PREFIX = 'qa-';

/**
 * Reserved-for-testing domain used by every seeded customer e-mail address.
 */
const SEED_EMAIL_DOMAIN = '@example.test';

/**
 * Prefix for every seeded human readable label (categories, attributes, ...).
 */
const SEED_LABEL_PREFIX = 'QA ';

/**
 * Prefix for every seeded machine code (store codes, attribute codes, ...).
 */
const SEED_CODE_PREFIX = 'qa_';

/**
 * Password assigned to every seeded customer account.
 */
const SEED_CUSTOMER_PASSWORD = 'QaSeed123!';

/**
 * The module's own config path. It has no section of its own and extends
 * Catalog > Frontend instead (see etc/system.xml).
 */
const SEED_MODULE_SPLIT_CATALOG_PATH = 'catalog/frontend/split_frontend_catalog';

/**
 * Name of the seeded top level category.
 */
const SEED_CATEGORY_ROOT = SEED_LABEL_PREFIX . 'Store';

/**
 * Name of the seeded category that products are assigned to.
 */
const SEED_CATEGORY_PRODUCTS = SEED_LABEL_PREFIX . 'Electronics';

/**
 * Name of the second seeded category, kept empty on purpose.
 */
const SEED_CATEGORY_SECONDARY = SEED_LABEL_PREFIX . 'Apparel';

/**
 * Absolute path of the module repository root.
 */
function seed_project_root(): string
{
    // tests/fixtures/seed -> tests/fixtures -> tests -> repository root.
    return dirname(__DIR__, 3);
}

/**
 * Absolute path of the OpenMage installation.
 *
 * Mirrors tests/bootstrap.php: OPENMAGE_ROOT wins, otherwise the sibling
 * openmage/ directory created by `ddev setup-openmage`.
 */
function seed_openmage_root(): string
{
    $envRoot   = getenv('OPENMAGE_ROOT');
    $candidate = ($envRoot !== false && $envRoot !== '') ? $envRoot : seed_project_root() . '/openmage';
    $resolved  = realpath($candidate);

    if ($resolved === false || !file_exists($resolved . '/app/Mage.php')) {
        fwrite(STDERR, "\n");
        fwrite(STDERR, "ERROR: OpenMage is not installed.\n");
        fwrite(STDERR, "Run: ddev setup-openmage\n");
        fwrite(STDERR, "\n");
        exit(1);
    }

    return $resolved;
}

/**
 * Absolute path of the directory holding generated fixture assets.
 *
 * Gitignored: the contents are reproducible from generator.php.
 */
function seed_generated_dir(): string
{
    return seed_project_root() . '/tests/fixtures/generated';
}

/**
 * Absolute path of the directory holding static mock payloads.
 */
function seed_mock_dir(): string
{
    return seed_project_root() . '/tests/fixtures/mock';
}

/**
 * Print one progress line.
 */
function seed_log(string $message): void
{
    fwrite(STDOUT, $message . PHP_EOL);
}

/**
 * Print one warning line to stderr without failing the run.
 */
function seed_warn(string $message): void
{
    fwrite(STDERR, 'WARNING: ' . $message . PHP_EOL);
}

/**
 * Abort the current seeder with a message and a non-zero status.
 */
function seed_fail(string $message): never
{
    fwrite(STDERR, 'ERROR: ' . $message . PHP_EOL);
    exit(1);
}

/**
 * Create a directory, including parents, if it does not exist yet.
 *
 * @throws RuntimeException When the directory cannot be created
 */
function seed_mkdir(string $directory): void
{
    if (is_dir($directory)) {
        return;
    }

    // The is_dir() re-check covers a parallel run winning the race.
    if (!mkdir($directory, 0775, true) && !is_dir($directory)) {
        throw new RuntimeException(sprintf('Could not create directory "%s".', $directory));
    }
}

/**
 * Run a callback with the isSecureArea flag registered.
 *
 * Customers, categories, products and orders all call
 * Mage_Core_Model_Abstract::_protectFromNonAdmin() from _beforeDelete(), which
 * throws unless this registry key is set. Without the wrapper a delete looks
 * like it worked and the row is still there.
 *
 * @param callable():void $callback Work to run inside the secure area
 */
function seed_secure(callable $callback): void
{
    Mage::register('isSecureArea', true, true);

    try {
        $callback();
    } finally {
        Mage::unregister('isSecureArea');
    }
}

/**
 * Read a positive integer CLI argument, falling back to a default.
 *
 * @param int $default  Value used when the argument is absent or unusable
 * @param int $position Zero-based $argv index; 1 is the first script argument
 */
function seed_int_arg(int $default, int $position = 1): int
{
    $argv = $_SERVER['argv'] ?? null;

    if (!is_array($argv) || !isset($argv[$position])) {
        return $default;
    }

    $raw = $argv[$position];

    if (!is_string($raw) || !ctype_digit($raw)) {
        return $default;
    }

    $value = (int) $raw;

    return $value > 0 ? $value : $default;
}

/**
 * Narrow a loosely typed OpenMage identifier down to a real int.
 *
 * @param mixed  $value   Raw id as returned by OpenMage
 * @param string $context Human readable subject for the error message
 *
 * @throws RuntimeException When the value is not a positive integer id
 */
function seed_id(mixed $value, string $context): int
{
    $id = seed_optional_id($value);

    if ($id === null) {
        throw new RuntimeException(sprintf(
            '%s: expected a positive numeric id, got "%s".',
            $context,
            get_debug_type($value),
        ));
    }

    return $id;
}

/**
 * Narrow a loosely typed OpenMage identifier, returning null when absent.
 *
 * Varien_Object::getId() yields null, '', an int or a numeric string depending
 * on where the value came from, so "does this record exist" needs one place
 * that understands all four.
 *
 * @param mixed $value Raw id as returned by OpenMage
 */
function seed_optional_id(mixed $value): ?int
{
    if (is_int($value)) {
        return $value > 0 ? $value : null;
    }

    if (is_string($value) && ctype_digit($value)) {
        $id = (int) $value;

        return $id > 0 ? $id : null;
    }

    return null;
}

/**
 * Instantiate a model and narrow it to the class the caller expects.
 *
 * @template T of object
 *
 * @param string          $alias    Model alias, e.g. 'catalog/product'
 * @param class-string<T> $expected Class the alias must resolve to
 *
 * @return T
 *
 * @throws RuntimeException When the alias does not resolve to $expected
 */
function seed_model(string $alias, string $expected): object
{
    $model = Mage::getModel($alias);

    if (!$model instanceof $expected) {
        throw new RuntimeException(sprintf(
            'Model "%s" did not resolve to %s. Check the <models> node in config.xml.',
            $alias,
            $expected,
        ));
    }

    return $model;
}

/**
 * Instantiate a resource model and narrow it to the expected class.
 *
 * @template T of object
 *
 * @param string          $alias    Resource alias, e.g. 'catalog/product_collection'
 * @param class-string<T> $expected Class the alias must resolve to
 *
 * @return T
 *
 * @throws RuntimeException When the alias does not resolve to $expected
 */
function seed_resource(string $alias, string $expected): object
{
    $model = Mage::getResourceModel($alias);

    if (!$model instanceof $expected) {
        throw new RuntimeException(sprintf(
            'Resource model "%s" did not resolve to %s.',
            $alias,
            $expected,
        ));
    }

    return $model;
}

/**
 * The catalog EAV setup resource, which is what owns addAttribute().
 *
 * The usual incantation is
 * Mage::getResourceModel('catalog/setup', 'catalog_setup'), but that call
 * cannot be written type-correctly: its second parameter is annotated as an
 * array while Mage_Core_Model_Resource_Setup::__construct() actually wants the
 * resource NAME as a string, and passing an array breaks at runtime.
 *
 * Resolving the class name through the config instead keeps class rewrites
 * working and lets the constructor be called with the string it really wants.
 *
 * @throws RuntimeException When catalog/setup cannot be resolved
 */
function seed_catalog_setup(): Mage_Catalog_Model_Resource_Setup
{
    $className = seed_config()->getResourceModelClassName('catalog/setup');

    if (!is_string($className) || $className === '' || !class_exists($className)) {
        throw new RuntimeException('Resource model "catalog/setup" could not be resolved. Is Mage_Catalog active?');
    }

    $setup = new $className('catalog_setup');

    if (!$setup instanceof Mage_Catalog_Model_Resource_Setup) {
        throw new RuntimeException(sprintf(
            'Resource model "catalog/setup" resolved to %s, which is not an EAV setup class.',
            $className,
        ));
    }

    return $setup;
}

/**
 * Drop the process-wide EAV metadata cache.
 *
 * Mage_Eav_Model_Config caches entity types, attributes and set membership for
 * the lifetime of the singleton, so anything created after it was first read
 * stays invisible. Mage_Eav_Model_Config::clear() does exactly this but is
 * deprecated upstream, so the two things it actually does are done directly:
 * flush the cache entry, then drop the singleton so the next lookup rebuilds
 * it from the database.
 */
function seed_reset_eav_cache(): void
{
    seed_app()->cleanCache([Mage_Eav_Model_Config::ENTITIES_CACHE_ID]);
    Mage::unregister('_singleton/eav/config');
}

/**
 * The booted application object.
 *
 * @throws RuntimeException When Mage::app() was never run
 */
function seed_app(): Mage_Core_Model_App
{
    $app = Mage::app();

    // Mage::app() has no native return type and yields null after a reset.
    if (!$app instanceof Mage_Core_Model_App) {
        throw new RuntimeException('OpenMage application is not available. Did lib.php boot Mage::app()?');
    }

    return $app;
}

/**
 * The global config model.
 *
 * @throws RuntimeException When Mage::app() was never run
 */
function seed_config(): Mage_Core_Model_Config
{
    $config = Mage::getConfig();

    if (!$config instanceof Mage_Core_Model_Config) {
        throw new RuntimeException('OpenMage config is not available. Did lib.php boot Mage::app()?');
    }

    return $config;
}

/**
 * Rebuild config and push it into the already-instantiated store objects.
 *
 * Both calls are mandatory. reinit() rebuilds the merged config XML but leaves
 * every Mage_Core_Model_Store that already exists holding its old cached
 * values, which is the number one cause of "I saved the config but
 * getStoreConfig() still returns the old value".
 */
function seed_refresh_config(): void
{
    seed_config()->reinit();
    seed_app()->reinitStores();
    seed_app()->cleanCache();
}

/**
 * The default frontend store view.
 *
 * Never the admin store: quotes, orders and category root ids are all
 * meaningless in scope 0.
 *
 * @throws RuntimeException When the installation has no frontend store view
 */
function seed_default_store(): Mage_Core_Model_Store
{
    $store = seed_app()->getDefaultStoreView();

    if ($store instanceof Mage_Core_Model_Store) {
        return $store;
    }

    // Fallback for an installation whose default flags were never set:
    // any active frontend store view will do. getStores(false) excludes admin.
    foreach (seed_app()->getStores(false) as $candidate) {
        if ($candidate instanceof Mage_Core_Model_Store) {
            return $candidate;
        }
    }

    throw new RuntimeException('No frontend store view found. Is OpenMage installed?');
}

/**
 * Website id of the default frontend store view.
 */
function seed_default_website_id(): int
{
    return seed_id(seed_default_store()->getWebsiteId(), 'Default store view website id');
}

/**
 * Store id of the default frontend store view.
 */
function seed_default_store_id(): int
{
    return seed_id(seed_default_store()->getId(), 'Default store view id');
}

/**
 * Catalog root category id for the default store view.
 *
 * Deliberately not hardcoded to 2: a multi-website installation has several
 * roots and "Default Category" can be renumbered or deleted outright.
 *
 * @throws RuntimeException When no root category can be resolved
 */
function seed_root_category_id(): int
{
    $rootId = seed_optional_id(seed_default_store()->getRootCategoryId());

    if ($rootId !== null && $rootId > Mage_Catalog_Model_Category::TREE_ROOT_ID) {
        return $rootId;
    }

    // Fallback: the first level-1 child of the tree root, which is what
    // "Default Category" is in a stock install.
    $collection = seed_category_collection();
    $collection->addAttributeToFilter('parent_id', Mage_Catalog_Model_Category::TREE_ROOT_ID);
    $collection->addAttributeToFilter('level', 1);
    $collection->setOrder('entity_id', 'ASC');
    $collection->setPageSize(1);

    $first = $collection->getFirstItem();
    $id    = seed_optional_id($first->getId());

    if ($id === null) {
        throw new RuntimeException('No catalog root category found. Is the catalog installed?');
    }

    return $id;
}

/**
 * Default attribute set id for products.
 *
 * Resolved from the entity type rather than hardcoded to 4, which is only
 * correct on a never-migrated stock installation.
 *
 * @throws RuntimeException When the product entity type cannot be read
 */
function seed_default_attribute_set_id(): int
{
    $resource = seed_model('catalog/product', Mage_Catalog_Model_Product::class)->getResource();

    if (!$resource instanceof Mage_Catalog_Model_Resource_Product) {
        throw new RuntimeException('Product resource model is unavailable. Is Mage_Catalog active?');
    }

    return seed_id(
        $resource->getEntityType()->getDefaultAttributeSetId(),
        'Default product attribute set',
    );
}

/**
 * Id of the first product tax class, i.e. "Taxable Goods" in a stock install.
 *
 * @throws RuntimeException When no product tax class exists
 */
function seed_product_tax_class_id(): int
{
    $collection = seed_resource(
        'tax/class_collection',
        Mage_Tax_Model_Resource_Class_Collection::class,
    );
    $collection->addFieldToFilter('class_type', Mage_Tax_Model_Class::TAX_CLASS_TYPE_PRODUCT);
    $collection->setOrder('class_id', 'ASC');
    $collection->setPageSize(1);

    $id = seed_optional_id($collection->getFirstItem()->getId());

    if ($id === null) {
        throw new RuntimeException('No product tax class found. Is Mage_Tax active?');
    }

    return $id;
}

/**
 * Resolve a directory region id, or null when the country has no regions.
 *
 * @param string $countryId  ISO-2 country code, e.g. 'US'
 * @param string $regionCode Region code within that country, e.g. 'CA'
 */
function seed_region_id(string $countryId, string $regionCode): ?int
{
    $region = seed_model('directory/region', Mage_Directory_Model_Region::class);
    $region->loadByCode($regionCode, $countryId);

    return seed_optional_id($region->getId());
}

/**
 * Address payload shared by the customer and order seeders.
 *
 * @param int $index One-based sequence number, used to vary street and phone
 *
 * @return array<string, string>
 */
function seed_address_data(int $index): array
{
    $data = [
        'firstname'  => 'Qa',
        'lastname'   => sprintf('Tester%03d', $index),
        'company'    => 'QA Seed Ltd',
        'street'     => sprintf('%d Test Street', 100 + $index),
        'city'       => 'Los Angeles',
        'postcode'   => '90210',
        'country_id' => 'US',
        'region'     => 'California',
        'telephone'  => sprintf('+1 555 010%04d', $index),
    ];

    // US addresses validate against directory_country_region, so send the id
    // when it resolves and leave the free-text region as the fallback.
    $regionId = seed_region_id('US', 'CA');

    if ($regionId !== null) {
        $data['region_id'] = (string) $regionId;
    }

    return $data;
}

/**
 * E-mail address of the Nth seeded customer.
 */
function seed_customer_email(int $index): string
{
    return SEED_EMAIL_PREFIX . sprintf('customer-%03d', $index) . SEED_EMAIL_DOMAIN;
}

/**
 * A category collection scoped to the admin store.
 */
function seed_category_collection(): Mage_Catalog_Model_Resource_Category_Collection
{
    $collection = seed_resource(
        'catalog/category_collection',
        Mage_Catalog_Model_Resource_Category_Collection::class,
    );
    $collection->setStoreId(Mage_Core_Model_App::ADMIN_STORE_ID);
    $collection->addAttributeToSelect('name');

    return $collection;
}

/**
 * Find a seeded category by its exact name, or null when absent.
 */
function seed_find_category(string $name): ?Mage_Catalog_Model_Category
{
    $collection = seed_category_collection();
    $collection->addAttributeToFilter('name', $name);
    $collection->setOrder('entity_id', 'ASC');
    $collection->setPageSize(1);

    $id = seed_optional_id($collection->getFirstItem()->getId());

    if ($id === null) {
        return null;
    }

    // A collection item only carries the attributes that were selected, so
    // $item->getIsActive() would come back as an empty string. Callers need a
    // fully loaded category (is_active, path, url_key, children_count...).
    $category = seed_model('catalog/category', Mage_Catalog_Model_Category::class);
    $category->setStoreId(Mage_Core_Model_App::ADMIN_STORE_ID);
    $category->load($id);

    return seed_optional_id($category->getId()) === null ? null : $category;
}

/**
 * Find a product by SKU, or null when absent.
 */
function seed_find_product(string $sku): ?Mage_Catalog_Model_Product
{
    $product = seed_model('catalog/product', Mage_Catalog_Model_Product::class);
    $id      = seed_optional_id($product->getIdBySku($sku));

    if ($id === null) {
        return null;
    }

    $product->setStoreId(Mage_Core_Model_App::ADMIN_STORE_ID);
    $product->load($id);

    return seed_optional_id($product->getId()) === null ? null : $product;
}

/**
 * Find a customer by e-mail within the default website, or null when absent.
 */
function seed_find_customer(string $email): ?Mage_Customer_Model_Customer
{
    $customer = seed_model('customer/customer', Mage_Customer_Model_Customer::class);
    $customer->setWebsiteId(seed_default_website_id());
    $customer->loadByEmail($email);

    return seed_optional_id($customer->getId()) === null ? null : $customer;
}

/**
 * Every product created by the seeders, ordered by SKU.
 *
 * @return list<Mage_Catalog_Model_Product>
 */
function seed_seeded_products(): array
{
    $collection = seed_resource(
        'catalog/product_collection',
        Mage_Catalog_Model_Resource_Product_Collection::class,
    );
    $collection->addAttributeToSelect('sku');
    $collection->addAttributeToFilter('sku', ['like' => SEED_SKU_PREFIX . '%']);
    $collection->setOrder('sku', 'ASC');

    $products = [];

    foreach ($collection as $product) {
        if ($product instanceof Mage_Catalog_Model_Product) {
            $products[] = $product;
        }
    }

    return $products;
}

/**
 * Every customer created by the seeders, ordered by e-mail.
 *
 * @return list<Mage_Customer_Model_Customer>
 */
function seed_seeded_customers(): array
{
    $collection = seed_resource(
        'customer/customer_collection',
        Mage_Customer_Model_Resource_Customer_Collection::class,
    );
    $collection->addAttributeToSelect('email');
    $collection->addAttributeToFilter('email', ['like' => SEED_EMAIL_PREFIX . '%']);
    $collection->setOrder('email', 'ASC');

    $customers = [];

    foreach ($collection as $customer) {
        if ($customer instanceof Mage_Customer_Model_Customer) {
            $customers[] = $customer;
        }
    }

    return $customers;
}

/**
 * Every order placed by a seeded customer, ordered by entity id.
 *
 * Orders carry no prefixable code of their own, so they are matched through
 * the customer e-mail that was copied onto them at checkout.
 *
 * @return list<Mage_Sales_Model_Order>
 */
function seed_seeded_orders(): array
{
    $collection = seed_resource(
        'sales/order_collection',
        Mage_Sales_Model_Resource_Order_Collection::class,
    );
    $collection->addFieldToFilter('customer_email', ['like' => SEED_EMAIL_PREFIX . '%']);
    $collection->setOrder('entity_id', 'ASC');

    $orders = [];

    foreach ($collection as $order) {
        if ($order instanceof Mage_Sales_Model_Order) {
            $orders[] = $order;
        }
    }

    return $orders;
}

/**
 * Attribute id for an entity type / attribute code pair, or null when absent.
 *
 * @param string $entityType EAV entity type code, e.g. 'catalog_product'
 * @param string $code       Attribute code
 */
function seed_attribute_id(string $entityType, string $code): ?int
{
    $resource = seed_resource(
        'eav/entity_attribute',
        Mage_Eav_Model_Resource_Entity_Attribute::class,
    );

    return seed_optional_id($resource->getIdByCode($entityType, $code));
}

// ─────────────────────────────────────────────────────────────────────────────
// Bootstrap. Everything above is declaration only; the application starts here.
// ─────────────────────────────────────────────────────────────────────────────

// Mage core still emits deprecations on modern PHP; they are not actionable
// from seed code and would drown the progress output.
error_reporting(E_ALL & ~E_DEPRECATED);

// Sessions are deliberately left at their PHP defaults, unlike
// tests/bootstrap.php which disables cookies. The order seeder drives the
// real checkout pipeline, which starts a Mage session, and
// Mage_Core_Model_Session_Abstract_Varien::start() calls
// session_set_cookie_params() unconditionally — with session.use_cookies=0
// that raises a warning which Mage's error handler turns into an exception,
// so every order silently fails to place.
//
// A CLI process has nowhere to send a cookie anyway, so leaving cookies
// enabled costs nothing and keeps checkout working.
ini_set('session.use_cookies', '1');

// No output has been sent yet in a CLI script, so a session started later in
// the run cannot collide with headers.
ini_set('session.cache_limiter', '');

require_once seed_openmage_root() . '/app/Mage.php';

Mage::app();
Mage::app()->setCurrentStore(Mage_Core_Model_App::ADMIN_STORE_ID);
