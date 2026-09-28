---
name: om-phpunit
description: "OpenMage PHPUnit testing — bootstrap, AbstractTestCase, test organization, Mage factory in tests. Load for any PHPUnit or testing task."
---

# OpenMage PHPUnit Testing

## Gotchas

### Environment

- Tests require a **running DDEV instance** with OpenMage installed. The bootstrap loads `openmage/app/Mage.php` — if it's missing, tests exit telling you to run `ddev setup-openmage`.
- `ddev test` runs PHPUnit **from inside the web container** at `/var/www/html/openmage` but uses `phpunit.xml` from `/var/www/html` (project root). The `--configuration` flag bridges this.
- PHPUnit is a dev dependency **inside `openmage/`**, not at project root. Path: `openmage/vendor/bin/phpunit`. Running `./vendor/bin/phpunit` from project root will NOT work.
- Bootstrap initializes `Mage::app('admin')` — tests run with **admin store scope**. For a specific store, call `Mage::app()->setCurrentStore($storeId)`.
- Session settings are suppressed in bootstrap (`session.use_cookies=0`). Tests depending on session behaviour need to mock it.
- Autoloader maps `Tests\*` to `tests/`. `Tests\Unit\Helper\DataTest` lives at `tests/Unit/Helper/DataTest.php`.

### Config saved in a test, but the old value comes back

*Symptom*: the test writes config, then `Mage::getStoreConfig()` still returns the previous value.

*Cause*: `Mage::getConfig()->reinit()` only rebuilds the config tree. Already-instantiated `Mage_Core_Model_Store` objects keep their own cached values.

*Fix*: call `Mage::getConfig()->reinit()` **and** `Mage::app()->reinitStores()`. `AbstractTestCase::configure()` does both, records the pre-test value, and restores it automatically in `tearDown()`. Never call `saveConfig()` directly in a test — the value leaks into every test that runs afterwards.

### PHPStan: `Call to an undefined method Mage_Core_Helper_Abstract::isEnabled()`

*Cause*: the helper getter was typed to the abstract base class, so PHPStan sees only the base API.

*Fix*: type the getter to the **concrete** helper class (`InternetCode_<Module>_Helper_Data`). As a side effect `assertInstanceOf()` on that type becomes a tautology PHPStan rejects — assert `::class` instead.

### PHPStan: `Cannot call method saveConfig() on Mage_Core_Model_Config|null`

*Cause*: `Mage::getConfig()` is nullable in the stubs, and every call site repeats the guard.

*Fix*: resolve it through one guarded private accessor (`getMageConfig()`) and use that everywhere.

### PHPStan cannot find PHPUnit classes

*Cause*: PHPUnit is installed inside `openmage/`, not at project root, so PHPStan's own autoloader never sees it.

*Fix*: `bootstrapFiles: openmage/vendor/autoload.php` in `.phpstan.dist.neon`. Do not remove it during an upstream sync.

### Seeded customer cannot log in

*Cause*: OpenMage set a confirmation key on save, leaving the account unconfirmed.

*Fix*: `$customer->setConfirmation(null)` and save again.

### Deleting a customer/product/order silently does nothing

*Cause*: core delete guards refuse outside the secure area.

*Fix*: wrap the delete in `Mage::register('isSecureArea', true)` and unregister afterwards.

### `$product->setQty(5)` has no effect

*Cause*: `qty` and `is_in_stock` are **not** product attributes. They live in `cataloginventory_stock_item`.

*Fix*: go through the stock item — `AbstractTestCase::setProductStock($product, $qty, $inStock)` wraps it.

## Key Files

| File | Purpose |
|---|---|
| `phpunit.xml` | Config — two suites: `Unit` and `Integration`, bootstrap path, cache dir |
| `tests/bootstrap.php` | Loads Mage.php, inits admin store, registers Tests\ autoloader |
| `tests/Base/AbstractTestCase.php` | Base class with helper methods — extend this, not PHPUnit\TestCase |
| `tests/Unit/` | Unit tests (can use Mage:: but avoid DB-heavy operations) |
| `tests/Integration/` | Integration tests (full DB access, create/update/delete entities) |

## AbstractTestCase Helpers

```php
// All methods use the module's alias prefix (internetcode_{module_snake})
$this->getHelper()                    // Mage::helper('internetcode_{module}')
$this->getModel('entity')            // Mage::getModel('internetcode_{module}/entity')
$this->getResourceModel('entity')    // Mage::getResourceModel('internetcode_{module}/entity')
$this->getConfig('general/enabled')  // Mage::getStoreConfig('internetcode_{module}/general/enabled')
$this->configure('general/enabled', '1')   // write + flush + auto-restore in tearDown()
$this->setConfig('general/enabled', '1')   // deprecated alias for configure()
$this->assertConfigEquals('1', 'general/enabled')
$this->resolveOptionId('qa_grade', 'A')    // attribute option label -> id
$this->setProductStock($product, 5, true)  // writes cataloginventory_stock_item
```

All return types are declared (PHP 8.2+). `getConfig()` accepts optional `$storeId`.

## Test Organization

```
tests/
├── Base/
│   └── AbstractTestCase.php    # Extend this — never PHPUnit\TestCase directly
├── Unit/
│   └── Helper/DataTest.php     # Reference test + worked example of the house pattern
├── Integration/
│   └── Seed/                   # SeedRunner + Catalog/Core seed assertions
└── fixtures/
    ├── seed/                   # ddev seed dispatch targets
    └── mock/                   # static payloads (sample.json, sample.xml)
```

Convention: mirror the module's class structure. `Model/Feed.php` → `tests/Unit/Model/FeedTest.php`.

## The House Test Pattern

`tests/Unit/Helper/DataTest.php` is the worked example. Copy its shape.

```php
class MyEntityTest extends AbstractTestCase
{
    /** Config this class owns, with the values it expects at rest. */
    private const CONFIG_DEFAULTS = [
        'general/enabled' => '0',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        // Each call is recorded and rolled back automatically.
        foreach (self::CONFIG_DEFAULTS as $path => $value) {
            $this->configure($path, $value);
        }
    }

    /** Optional hook: remove fixtures this test created. */
    protected function resetState(): void
    {
        // NEVER call Mage::reset() here — it tears down the app for the whole
        // PHP process and every later test fails.
    }
}
```

Rules:

1. Declare the config the class depends on in `CONFIG_DEFAULTS`, apply it in `setUp()`.
2. Mutate config only through `configure()`. Direct `saveConfig()` leaks into later tests.
3. Write no restore code. `AbstractTestCase::tearDown()` rolls back every recorded mutation, flushes the config caches, then calls `resetState()`.
4. Override `resetState()` for non-config fixtures (entities, files) only.

Place tests mirroring the module's class structure: `Model/Feed.php` → `tests/Unit/Model/FeedTest.php`.

### Ordered multi-pass tests

`phpunit.xml` sets `executionOrder="depends,defects"`, so `#[Depends]` chains run in declaration order and a passing test can hand its result to the next. `DataTest` demonstrates a 3-pass insert → update → no-op rewrite chain:

```php
public function testPassOneInsertsValue(): string { /* ... */ return $value; }

#[Depends('testPassOneInsertsValue')]
public function testPassTwoUpdatesValue(string $previous): string { /* ... */ }

#[Depends('testPassTwoUpdatesValue')]
public function testPassThreeRewriteIsANoOp(string $previous): void { /* ... */ }
```

`failOnRisky` and `failOnWarning` are both on — a test with no assertions is a failure.

## Commands

```bash
ddev test                          # All tests
ddev test --filter testMyMethod    # Specific method
ddev test --filter MyEntityTest    # Specific class
ddev test --testsuite Unit         # Unit suite only
ddev test --testsuite Integration  # Integration suite only
ddev test --testdox                # Verbose output with test names
```

## Integration Test Tips

- Use `setUp()` for fixtures, `resetState()` for teardown of anything that is not config
- `Mage::app()->setCurrentStore(Mage_Core_Model_App::ADMIN_STORE_ID)` for admin context
- Config: always `configure()`, never `saveConfig()` directly
- Deleting catalog/customer/sales entities needs `Mage::register('isSecureArea', true)`
- Integration tests can lean on the seeders — `tests/Integration/Seed/SeedRunner.php` drives them, and everything they create is `QA-` / `qa-` prefixed
- Run `DataTest` first to verify the environment works at all
