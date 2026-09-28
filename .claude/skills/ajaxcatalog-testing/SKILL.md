---
name: ajaxcatalog-testing
description: "ajaxcatalog testing — DDEV OpenMage env with 1.9 sample data, PHPUnit Unit/Integration suites, lint gate, Cypress JSON-endpoint specs, HTTP smoke tests."
---

# ajaxcatalog Testing Environment

DDEV project `om-ajaxcatalog` (https://om-ajaxcatalog.ddev.site), OpenMage 20 + Magento 1.9 sample data in `openmage/` (gitignored). The module is a composer path repo (`../`), and `modman` symlinks `src/app/...` into it, so edits apply immediately. Generic template knowledge lives in `om-ddev`, `om-phpunit`, `om-cypress`.

## Gotchas

- **Tests need sample data.** Always `ddev setup-openmage --with-sample-data` (CI does too). The template imports the dump *before* `install.php`, which then upgrades it to OpenMage 20. Without sample data `tests/bootstrap.php` exits early with "No default store view found".
- **Moving files under `src/` or editing `modman`** → the symlinks in `openmage/` are stale: `ddev reset-openmage --full && ddev setup-openmage --with-sample-data`.
- **Clear cache after DB config changes:** `ddev exec "rm -rf openmage/var/cache/*"`.
- **Bootstrap runs `Mage::app('admin')` in developer mode**, so warnings throw. Stock checks are skipped in admin: switch store with `Mage::app()->setCurrentStore(...)` and restore it in `tearDown`.
- **Config in tests:** `AbstractTestCase::configure('frontend/split_frontend_catalog', '1')`. Paths are relative to `CONFIG_SECTION = 'catalog'` (the module has no section of its own). The raw `core_config_data` row for that scope is backed up and restored (or deleted) in `tearDown`, so inheritance survives.
- **Stock item caches `getMinSaleQty()`**: build a fresh `cataloginventory/stock_item` from the loaded item's data before changing min_sale_qty (see `CartQtyTest::simpleWithStock`).
- **Product collections in tests need `addStoreFilter()`**: ~240 sample products have no stock row for website 1.
- **`core/http` helper caches REMOTE_ADDR**: `Mage::unregister('_helper/core/http')` when faking IPs.
- **`ddev test --filter 'a|b'`** breaks on the pipe through ddev's shell. Filter by one class or method.
- **Form key from a category page is stale.** The footer newsletter block is block-cached with the key of whoever warmed the cache. Take the key from an uncached page (`/customer/account/login/`).
- **POST without a session cookie** → core 302s to `/enable-cookies` before the module runs.
- **`CartController` requires the parent via `require_once 'Mage/Checkout/controllers/CartController.php'`** (include path). `Mage::getModuleDir()` there crashes PHPStan: phpstan-magento1's controller autoloader *executes* controller files, and `Mage` is not loaded.
- **ECS 13.3+**: `withPhpCsFixerSets(perCS30: true)` fatals ("Unknown named parameter"); `.php-cs-fixer.dist.php` uses `withSets([SetList::PER_CS])`.
- Tests log an expected "Invalid webpack manifest" warning to `var/log/system.log`.

## Sample data facts (website 1 stock status)

Configurable: 37 in stock (all `qty = 0`), 3 out. Simple: 13 out of stock (the in-stock count grows with `ddev seed products`). Grouped/bundle have no own price. `women.html` lists 12 configurables. Simple ids 234/235 are in stock (qty 25).

## Files

| Path | Purpose |
|------|---------|
| `tests/bootstrap.php` | Loads `openmage/app/Mage.php`, dev mode, admin app, sample-data guard |
| `tests/Base/AbstractTestCase.php` | Template base: typed helper/model accessors, `configure()` with rollback, stock/EAV helpers |
| `tests/Base/AjaxCatalogTestCase.php` | `createTempDir()` (int value = mtime), `createAssets()`, `loadProductByType()` |
| `tests/Unit/Model/AssetsTest.php` | Webpack manifest / directory resolution (temp dirs; still boots `Mage::app()`) |
| `tests/Integration/*Test.php` | ProductOutput, CartQty, StockSplit, Http (headers + critical access) |
| `tests/Integration/Seed/` | Template seeder tests — own `Seed` suite, excluded from plain `ddev test` because the seeders leave data behind |
| `cypress/support/openmage/frontend/ajaxcatalog/api.js` | JSON endpoint contract + `formKey()` |
| `cypress/e2e/openmage/frontend/ajaxcatalog/*.cy.js` | Listing JSON + AJAX cart specs |

## Commands

```bash
ddev start && ddev setup-openmage --with-sample-data   # first time
ddev test                                              # Unit + Integration
ddev test --testsuite Seed                             # seeders (mutates DB; CI runs it last)
ddev test --filter AssetsTest
ddev lint                                              # ECS + PHPStan L8 + PHPCS (no baseline)
ddev lint fix                                          # ECS + Rector auto-fix
ddev cypress-run --spec 'cypress/e2e/openmage/frontend/ajaxcatalog/*.cy.js'
```

## HTTP smoke test

```bash
B=https://om-ajaxcatalog.ddev.site; J=/tmp/jar
curl -sk -c $J -b $J $B/customer/account/login/ -o login.html      # session + fresh form_key
curl -sk -b $J -H 'X-Requested-With: XMLHttpRequest' $B/women.html  # listing JSON
curl -sk -b $J -X POST -d "form_key=$FK" $B/ajaxcatalog/cart/add/product/234/
```

Split catalog: `REPLACE INTO core_config_data (scope,scope_id,path,value) VALUES ('default',0,'catalog/frontend/split_frontend_catalog','1')`, then clear cache. Delete the row afterwards.
