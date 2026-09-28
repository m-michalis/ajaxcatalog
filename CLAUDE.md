# ajaxcatalog (InternetCode_AjaxCatalog)

OpenMage module — backend for a custom (webpack) frontend: AJAX category/search listings, AJAX add-to-cart, webpack asset injection, critical css. Built on the [om-dev-template](https://github.com/m-michalis/om-dev-template) layout.

## Quick Facts

- **Type:** Frontend-backend module (controllers, observer, blocks); the storefront JS lives in the theme repo, not here
- **Layout:** module code under `src/app/...`, mapped by `modman`; composer package `m-michalis/ajaxcatalog`
- **PHP:** `^8.2`. OpenMage 20.x only (cart helper rewrite relies on OpenMage-only methods)
- **Aliases:** model/block/helper `ajaxcatalog`; rewrites `checkout/cart` helper. No own config section — adds `catalog/frontend/split_frontend_catalog`
- **Front name:** `ajaxcatalog` (`/ajaxcatalog/cart/add`, `/ajaxcatalog/cart/data`, `/ajaxcatalog/critical`)
- **Public API:** the theme repo calls helper/block methods from templates — no native param types on public/protected methods (PHPDoc only)
- **Version:** keep `composer.json` and `etc/config.xml` `<version>` in sync; `release.yml` tags `composer.json` version on push to master
- There is no PHP or Composer on the host. Every PHP command runs through `ddev`.

## Development Commands

| Command | Description |
|---|---|
| `ddev setup-openmage --with-sample-data` | Install OpenMage + 1.9 sample data + symlink module (tests need sample data) |
| `ddev reset-openmage [--full]` | Reset DB (`--full` deletes `openmage/`) |
| `ddev test` | PHPUnit Unit + Integration |
| `ddev lint` | ECS + PHPStan level 8 + PHPCS — no baseline, keep it at zero |
| `ddev lint fix` | Auto-fix (ECS + Rector) |
| `ddev seed [type]` | Template QA seeders (`QA-` products, `qa-` customers, …) |
| `ddev cypress-run` | Cypress specs for the JSON endpoints |

CI (`.github/workflows/ci.yml`): setup with sample data → lint → test → Cypress → `ddev test --testsuite Seed` (seeders mutate the DB, so they run last).

## Skills

| Skill | When to load |
|-------|-------------|
| `ajaxcatalog` | Changing module code: listings JSON, add-to-cart, stock split, webpack assets, critical css, observer, public API |
| `ajaxcatalog-testing` | Writing or running PHPUnit tests, lint gate fixes, sample-data facts, HTTP smoke tests |
| `om-ddev` | DDEV setup/reset, sample data, seeding, module symlinking, CI pipeline |
| `om-cypress` | Cypress specs for the JSON endpoints, vendored `cy.openmage` utils |
| `om-upstream-sync` | Upgrading or syncing ECS/PHPStan/PHPCS/Rector/Cypress from OpenMage LTS or om-dev-template |

For broader Magento 1.x / OpenMage work, also load the user-level **`openmage`** skill.
