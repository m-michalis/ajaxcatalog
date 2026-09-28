---
name: ajaxcatalog-testing
description: "ajaxcatalog PHPUnit tests — AbstractTestCase, config rollback, suites, sample-data facts, lint gate, HTTP smoke tests."
---

# ajaxcatalog Testing (PHPUnit + lint)

Environment setup lives in `om-ddev`; Cypress in `om-cypress`.

## Gotchas

- **Tests need the 1.9 sample data** (`ddev setup-openmage --with-sample-data`). Without it `tests/bootstrap.php` exits with "No default store view found".
- **PHPUnit lives in `openmage/vendor/`**, config is root `phpunit.xml`; `ddev test` bridges both. `./vendor/bin/phpunit` at root does not exist.
- **Bootstrap = `Mage::setIsDeveloperMode(true)` + `Mage::app('admin')`.** Warnings throw. Stock checks are skipped in admin: `Mage::app()->setCurrentStore(...)` and restore it in `tearDown`.
- **Don't set `session.use_cookies=0` in the bootstrap** (the template did): the first in-process frontend session then dies in `session_set_cookie_params()`. The Seed tests used to mask this by starting the session first.
- **Suites:** `Unit`, `Integration`, `Seed`. `defaultTestSuite="Unit,Integration"`; `Seed` (`tests/Integration/Seed`) runs the seeders and leaves QA data behind — opt in with `--testsuite Seed`. "Unit" still boots `Mage::app()`.
- **Config in tests:** only `configure('frontend/split_frontend_catalog', '1')` — paths are relative to `AbstractTestCase::CONFIG_SECTION = 'catalog'`, helpers/models to `MODULE_ALIAS = 'ajaxcatalog'`. The raw `core_config_data` row for that exact scope is backed up and restored (or deleted) in `tearDown()`. Never call `saveConfig()` directly.
- **Config saved but old value returned** → needs `Mage::getConfig()->reinit()` **and** `Mage::app()->reinitStores()`; `configure()` does both.
- **`Mage::reset()` in `resetState()`** kills the app for the whole process. Never.
- **Stock item caches `getMinSaleQty()`**: build a fresh `cataloginventory/stock_item` from the loaded one's data first (`CartQtyTest::simpleWithStock`). `qty`/`is_in_stock` are not product attributes — use `setProductStock()`.
- **Product collections in tests need `addStoreFilter()`**: ~240 sample products have no stock row for website 1.
- **`core/http` helper caches REMOTE_ADDR**: `Mage::unregister('_helper/core/http')` when faking IPs.
- **`ddev test --filter 'a|b'`** breaks on the pipe through ddev's shell. One class or method per call.
- **Deleting customers/products/orders** needs `Mage::register('isSecureArea', true)`.
- **Namespaced tests** (`Tests\Unit\…`, `Tests\Integration\…`) must `use Mage;` and every `Mage_*` class. Data providers use `#[DataProvider]` attributes (Rector turns bodies into `yield`).
- Tests log an expected "Invalid webpack manifest" warning to `var/log/system.log`.
- `failOnRisky` + `failOnWarning`: a test without assertions fails.

## Lint gate (no baseline — keep it at zero)

- `ddev lint` = ECS (PER-CS) + PHPStan level 8 strict/bleedingEdge + PHPCS (ECG) over `src/` and `tests/`; `ddev lint rector` must also be clean.
- PHPStan: `Mage::getConfig()` is nullable, `Mage::helper()/getModel()/getSingleton()` loosely typed → narrow with `instanceof` (see `AbstractTestCase::getMageConfig()`), never suppress.
- ECG forbids direct filesystem functions and `html_entity_decode` — use SPL, or a justified inline `phpcs:ignore`.

## Sample data facts (website 1 stock status)

Configurable: 37 in stock (all `qty = 0`), 3 out. Simple: 13 out of stock (in-stock count grows with `ddev seed products`). Grouped/bundle have no own price. `women.html` lists 12 configurables. Simple ids 234/235 in stock (qty 25).

## Files

| Path | Purpose |
|------|---------|
| `phpunit.xml` | Suites, default suite, source include for coverage |
| `tests/bootstrap.php` | Dev mode, admin app, sample-data guard, `Tests\` autoloader |
| `tests/Base/AbstractTestCase.php` | `getHelper()`, `configure()`/`assertConfigEquals()` with rollback, `setProductStock()`, `resolveOptionId()`, `resetState()` hook |
| `tests/Base/AjaxCatalogTestCase.php` | `createTempDir()` (int value = mtime), `createAssets()`, `loadProductByType()`, `getDefaultStore()` |
| `tests/Unit/Model/AssetsTest.php` | Manifest / directory resolution on temp dirs |
| `tests/Integration/*Test.php` | ProductOutput, CartQty, StockSplit, Http (headers + critical access) |
| `tests/Integration/Seed/` | `SeedRunner` + seeder assertions (Seed suite) |
| `tests/fixtures/seed/`, `tests/Support/` | Seeders and Cypress CLI helpers; `lib.php` blocks non-CLI SAPIs |

## Commands

```bash
ddev test                               # Unit + Integration
ddev test --testsuite Seed              # seeders; CI runs it last
ddev test --filter AssetsTest
ddev lint && ddev lint rector
```

## HTTP smoke test

```bash
B=https://om-ajaxcatalog.ddev.site; J=/tmp/jar
curl -sk -c $J -b $J $B/customer/account/login/ -o login.html      # session + fresh form_key
FK=$(grep -o 'name="form_key" type="hidden" value="[^"]*"' login.html | head -1 | sed 's/.*value="//;s/"//')
curl -sk -b $J -H 'X-Requested-With: XMLHttpRequest' $B/women.html  # listing JSON
curl -sk -b $J -X POST -d "form_key=$FK" $B/ajaxcatalog/cart/add/product/234/
curl -sk -b $J $B/ajaxcatalog/cart/data
```

- Category-page form keys are stale (block-cached footer newsletter form) — take the key from the login page.
- POST without a session cookie → core 302s to `/enable-cookies` before the module runs.
- curl jars prefix HttpOnly cookies with `#HttpOnly_` — don't grep them out.
- Split catalog: `REPLACE INTO core_config_data (scope,scope_id,path,value) VALUES ('default',0,'catalog/frontend/split_frontend_catalog','1')`, clear cache, delete the row afterwards.
