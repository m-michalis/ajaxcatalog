# ajaxcatalog (InternetCode_AjaxCatalog)

OpenMage module — backend for a custom (webpack) frontend: AJAX category/search listings, AJAX add-to-cart, webpack asset injection, critical css.

## Quick Facts

- **Type:** Frontend-backend module (controllers, observer, blocks); the storefront JS lives in the theme repo, not here
- **PHP:** composer says `^7.2 || ^8.0` — keep app code 7.2-compatible (no typed properties, no `match`)
- **OpenMage:** 20.x only (cart helper rewrite relies on OpenMage-only methods)
- **Aliases:** model/block/helper `ajaxcatalog`; rewrites `checkout/cart` helper
- **Front name:** `ajaxcatalog` (`/ajaxcatalog/cart/add`, `/ajaxcatalog/cart/data`, `/ajaxcatalog/critical`)
- **Tests:** integration tests via `ddev test` (PHPUnit 10, runs against a DDEV OpenMage with sample data)
- **Version:** keep `composer.json` and `etc/config.xml` `<version>` in sync

## Skills

| Skill | When to load |
|-------|-------------|
| `ajaxcatalog` | Any change to listings JSON, add-to-cart, stock split, webpack assets, critical css, observer |
| `ajaxcatalog-testing` | DDEV environment, PHPUnit tests, sample data, HTTP smoke tests |
