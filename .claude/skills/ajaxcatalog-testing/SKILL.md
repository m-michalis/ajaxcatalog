---
name: ajaxcatalog-testing
description: "ajaxcatalog testing — DDEV OpenMage env, PHPUnit integration tests, sample data, HTTP smoke tests."
---

# ajaxcatalog Testing Environment

DDEV project `om-ajaxcatalog` (https://om-ajaxcatalog.ddev.site) with OpenMage 20 + 1.9 sample data in `openmage/` (gitignored). The module is a composer path repo symlinked into it — edits apply immediately.

## Gotchas

- **Sample data SQL is 1.9** and imported after `install.php`. `setup-openmage` therefore saves/restores `core_resource` around the import (otherwise every request re-runs upgrades and dies on "table already exists"), re-adds `catalog_product_entity_group_price.is_percent`, and re-inserts `web/*/base_url` (the dump wipes them → 302 to localhost). An old install missing these → `ddev reset-openmage && ddev setup-openmage`.
- **Clear cache after DB config changes:** `ddev exec "rm -rf openmage/var/cache/*"`.
- **Bootstrap runs `Mage::app('admin')` in developer mode** — warnings throw. Stock checks are skipped in admin: switch store with `Mage::app()->setCurrentStore(...)` and restore in `tearDown`.
- **Stock item caches `getMinSaleQty()`** — build a fresh `cataloginventory/stock_item` from the loaded one's data before changing min_sale_qty (see `CartQtyTest::simpleWithStock`).
- **Product collections in tests need `addStoreFilter()`** — ~240 sample products have no stock row for website 1.
- **`core/http` helper caches REMOTE_ADDR** — `Mage::unregister('_helper/core/http')` when faking IPs.
- **`ddev test --filter 'a|b'`** breaks on the pipe through ddev's shell — filter by class name or a single method.
- Tests log an expected "Invalid webpack manifest" warning to `var/log/system.log`.
- phpunit comes from `openmage/vendor/bin/phpunit`; config is root `phpunit.xml` (`failOnWarning`).

## Sample data facts

Stock status (website 1): 37 in-stock configurables, all with `qty = 0`; 236 in-stock / 13 out-of-stock simples; grouped/bundle have no own price. `women.html` has 12 configurables. Simple ids 234/235 are in stock (qty 25).

## Files

| Path | Purpose |
|------|---------|
| `.ddev/config.yaml` | PHP 8.2, MariaDB 11.8, `MAGE_IS_DEVELOPER_MODE=1` |
| `.ddev/commands/web/setup-openmage` / `reset-openmage [--full]` / `test` | Provision / wipe / run PHPUnit |
| `tests/bootstrap.php` | Loads `openmage/app/Mage.php`, dev mode, admin app |
| `tests/Integration/AjaxCatalogTestCase.php` | `createTempDir()` (int value = mtime), `createAssets()`, `loadProductByType()` |
| `tests/Integration/*Test.php` | Assets, ProductOutput, CartQty, StockSplit, Http (headers + critical access) |

## Commands

```bash
ddev start && ddev setup-openmage      # first time (~2 min; sample data cached in .ddev/.sampleData)
ddev test                              # all tests
ddev test --filter AssetsTest
ddev mysql -e "SELECT ..."
```

## HTTP smoke test

```bash
B=https://om-ajaxcatalog.ddev.site; J=/tmp/jar
curl -sk -c $J -b $J $B/women.html -o page.html                  # session + form_key in HTML
curl -sk -b $J -H 'X-Requested-With: XMLHttpRequest' $B/women.html  # listing JSON
curl -sk -b $J -X POST -d "form_key=$FK" $B/ajaxcatalog/cart/add/product/234/
```

Split catalog: `REPLACE INTO core_config_data (scope,scope_id,path,value) VALUES ('default',0,'catalog/frontend/split_frontend_catalog','1')` + clear cache; delete the row afterwards.
