---
name: ajaxcatalog
description: "ajaxcatalog module — AJAX listings JSON, add-to-cart, stock split, webpack assets/manifest, critical css, observer."
---

# ajaxcatalog Module

## Gotchas

- **Same URL, two formats.** `Model/Observer.php` hooks `controller_action_layout_generate_blocks_after`; for routes in `frontend/ajaxroutes` (config.xml) it returns JSON when `isAjax()` (XHR header, `?ajax=1`, `?isAjax=1`), else strips the product list from the HTML (`prepareNormalView` → `unsetChildren`). Both paths call `Helper_Data::applyResponseHeaders()` (Vary / no-store) — keep it that way, the JSON embeds the session form key.
- **`setNoRender(true)` on the front controller** is what stops `renderLayout()` in the AJAX path — don't remove it.
- **`prepareProductOutput()` wipes product data** — keeps only `name` + computed keys. Anything the frontend needs must be added there or via event `ajaxcatalog_prepare_product_output`.
- **`add_to_card_url` (typo) is kept on purpose** next to `add_to_cart_url` — the frontend reads it.
- **Composite parents have stock `qty = 0`** (configurable/grouped/bundle indexers hard-code it). Never filter or check stock by `qty` for them: stock split uses `stock_status` (`Helper/Stock.php`), cart check skips non-`isQty` types.
- **`Stock_Item::checkQty()` returns true in the admin store** and `getMinSaleQty()` caches per instance — matters in tests.
- **Stock split order:** `getOutOfStockCount()` must run before `addInStockFilter()` (count clones the collection select). Own alias `ajaxcatalog_stock` avoids clashing with core's `stock_status_index`.
- **Every add-to-cart URL on the site** goes to `ajaxcatalog/cart/add` (helper rewrite overrides core `getAddUrlCustom`). `CartController::preDispatch` 404s everything except `add`/`data`.
- **Cart qty check mirrors core `Mage_Checkout_Model_Cart::addProduct()`** (default qty = min_sale_qty, raised to it only when product not in quote). Change both together or not at all.
- **PHP warnings are exceptions in developer mode** (`mageCoreErrorHandler`) — an undefined index breaks the whole JSON response under DDEV.
- **Asset URLs:** use `Model_Assets::getUrl()` (web base URL). `Mage::getBaseUrl()` adds store code / `index.php`.
- **Critical endpoint access:** developer mode + `dev/restrict/allow_ips`, or header `X-Ajaxcatalog-Token` = `global/ajaxcatalog/critical_token` (local.xml). Token is never read from query params.

## Webpack assets (`Model/Assets.php`, singleton `ajaxcatalog/assets`)

- Source of truth: `{base}/assets/manifest.json` (key `home.js` → value `home.[hash].js`, publicPath/`assets/` prefix stripped, absolute URLs passed through). Fallback: directory scan, **newest mtime first**.
- In manifest mode `critical.*` / `uncritical.*` files are still picked from the directory (generated after the build).
- Entry → layout handles: `frontend/ajaxentries` in config.xml (`home` → `cms_index_index`, `shared` → `default`). One file per entry/type; `shared` appended to every handle.
- With critical css the regular css becomes `uncritical` (async preload); an explicit `uncritical.{entry}.css` replaces it and must already include shared css.
- `Helper_Data::getWebpackFilesByRoute()` / `getImageAssetUrl()` are BC wrappers — templates call them.
- Tests point the model elsewhere with `setAssetDir($dir)`; reset with `setAssetDir(null)`.

## Where things are

| Path | What |
|------|------|
| `Model/Observer.php` | Route → AJAX model dispatch, headers |
| `Model/Catalog/Abstract.php` | Collection init, toolbar/layer JSON, stock split call |
| `Model/Catalog/Category/View.php`, `Model/Catalogsearch/Result/Index.php` | Per-route list block + response |
| `Helper/Data.php` | Product output, discount/is_new, cart qty rules, headers, critical access |
| `Helper/Stock.php` | Stock split SQL |
| `Model/Assets.php` + `Block/Webpack.php` | Asset resolution + `<script>/<link>` output (layout `internetcode_ajaxcatalog.xml`) |
| `controllers/CartController.php` | JSON add-to-cart + minicart data |
| `controllers/CriticalController.php` | Build-time critical css feed (Guzzle) |
| `app/locale/el_GR/InternetCode_AjaxCatalog.csv` | Greek strings — source strings are English |

## Config

- `catalog/frontend/split_frontend_catalog` (system.xml) — hide unsalable products unless `?out_of_stock=1` or `?stock=`; count returned as `toolbar.out_of_stock_count`.
- New translatable strings → add to the el_GR CSV; new files outside `app/code` → add to `modman`.
