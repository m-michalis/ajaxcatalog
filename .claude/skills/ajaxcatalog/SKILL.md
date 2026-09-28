---
name: ajaxcatalog
description: "ajaxcatalog module code — listings JSON, AJAX add-to-cart, stock split, webpack assets/manifest, critical css, observer, public API."
---

# ajaxcatalog Module

Module code lives in `src/app/code/local/InternetCode/AjaxCatalog/` (paths below are relative to it). Other files: `src/app/design/frontend/base/default/layout/internetcode_ajaxcatalog.xml`, `src/app/locale/el_GR/InternetCode_AjaxCatalog.csv`, `src/app/etc/modules/InternetCode_AjaxCatalog.xml`.

## Gotchas

- **Public API = the theme repo.** Storefront templates (separate repo) call helper/block/model methods. No native param types or new native return types on public/protected methods: PHPDoc only. Removing or renaming a public method breaks the theme silently.
- **Same URL, two formats.** `Model/Observer.php` hooks `controller_action_layout_generate_blocks_after`; for routes in `frontend/ajaxroutes` (config.xml) it returns JSON when `isAjax()` (XHR header, `?ajax=1`, `?isAjax=1`), else strips the product list from the HTML (`prepareNormalView` → `unsetChildren`). Both paths call `Helper_Data::applyResponseHeaders()` (Vary / no-store) — keep it, the JSON embeds the session form key.
- **Route classes must extend `Model_AjaxResponse`**, or `Observer::getAjaxRoute()` ignores them and the route serves HTML.
- **`setNoRender(true)` on the front controller** is what stops `renderLayout()` in the AJAX path — don't remove it.
- **`getProductListBlock()`** returns `null` when the block is missing, but subclasses may still return `false` (old `getBlock()` style). Callers in `Model/Catalog/Abstract.php` must check `instanceof Mage_Catalog_Block_Product_List`, never `!== null`.
- **`prepareProductOutput()` wipes product data** — keeps only `name` + computed keys. Anything the frontend needs must be added there or via event `ajaxcatalog_prepare_product_output`.
- **`add_to_card_url` (typo) is kept on purpose** next to `add_to_cart_url` — the frontend reads it.
- **Composite parents have stock `qty = 0`** (configurable/grouped/bundle indexers hard-code it). Never filter or check stock by `qty` for them: stock split uses `stock_status` (`Helper/Stock.php`), cart check skips non-`isQty` types.
- **Stock split order:** `getOutOfStockCount()` must run before `addInStockFilter()` (count clones the collection select). Own alias `ajaxcatalog_stock` avoids clashing with core's `stock_status_index`.
- **Every add-to-cart URL on the site** goes to `ajaxcatalog/cart/add` (helper rewrite overrides core `getAddUrlCustom`). `CartController::preDispatch` 404s everything except `add`/`data`.
- **`CartController` loads its parent with `require_once 'Mage/Checkout/controllers/CartController.php'`** (include path). Don't switch back to `Mage::getModuleDir()` (crashes PHPStan) and don't let Rector absolutize it (`__DIR__`-relative path doesn't exist → add-to-cart 500s; the rule is skipped in `.rector.php`).
- **Cart qty check mirrors core `Mage_Checkout_Model_Cart::addProduct()`** (default qty = min_sale_qty, raised to it only when product not in quote). Change both together or not at all.
- **`related_product`** is only honoured as a non-empty string other than `'0'` (`_addRelatedProducts()`).
- **No `minicart_content` block** → `addAction` still answers `success: 1` with empty `content`.
- **PHP warnings are exceptions in developer mode** (`mageCoreErrorHandler`) — an undefined index breaks the whole JSON response under DDEV.
- **Asset URLs:** use `Model_Assets::getUrl()` (web base URL). `Mage::getBaseUrl()` adds store code / `index.php`.
- **Critical endpoint access:** developer mode + `dev/restrict/allow_ips`, or header `X-Ajaxcatalog-Token` = `global/ajaxcatalog/critical_token` (local.xml), compared with `hash_equals`. Never read from query params.
- **Two justified suppressions** in `Model/Catalog/Abstract.php`: `@phpstan-ignore argument.type` on `$toolbar->setCollection()` (core PHPDoc is wrong) and `phpcs:ignore` on `html_entity_decode` for filter labels. Don't add more without an inline reason; lint has no baseline.

## Webpack assets (`Model/Assets.php`, singleton `ajaxcatalog/assets`)

- Source of truth: `{base}/assets/manifest.json` (key `home.js` → value `home.[hash].js`, publicPath/`assets/` prefix stripped, absolute URLs passed through). Fallback: directory scan, **newest mtime first**.
- File access uses SPL (`SplFileInfo`, `DirectoryIterator`) — ECG forbids `file_get_contents`/`scandir`/`filemtime`. `_getFileMtimes()` catches `RuntimeException` around the whole loop: a file vanishing mid-scan (rebuild cleaning old hashes) empties that request's listing.
- In manifest mode `critical.*` / `uncritical.*` files are still picked from the directory (generated after the build).
- Entry → layout handles: `frontend/ajaxentries` in config.xml (`home` → `cms_index_index`, `shared` → `default`). One file per entry/type; `shared` appended to every handle.
- With critical css the regular css becomes `uncritical` (async preload); an explicit `uncritical.{entry}.css` replaces it and must already include shared css.
- `Helper_Data::getWebpackFilesByRoute()` / `getImageAssetUrl()` are BC wrappers — templates call them. `getImageUrl()` rejects paths containing `..`.
- Listings are memoized per request only (`$_directoryListings`), not in the Mage cache. Tests point the model elsewhere with `setAssetDir($dir)`; reset with `setAssetDir(null)`.

## Where things are

| Path | What |
|------|------|
| `Model/Observer.php` | Route → AJAX model dispatch, headers |
| `Model/AjaxResponse.php` | Base for route models (`prepareNormalView`/`prepareAjaxView`/`getAjaxResponse`) |
| `Model/Catalog/Abstract.php` | Collection init, toolbar/layer JSON (`getLayerFilters`, `getLayerState`), stock split call |
| `Model/Catalog/Category/View.php`, `Model/Catalogsearch/Result/Index.php` | Per-route list block + response |
| `Helper/Data.php` | Product output, discount/is_new, cart qty rules, headers, critical access, null-safe config reads (`_getConfigNode`) |
| `Helper/Stock.php` | Stock split SQL |
| `Helper/Checkout/Cart.php` | `checkout/cart` rewrite → AJAX add URL |
| `Model/Assets.php` + `Block/Webpack.php` | Asset resolution + `<script>/<link>` output |
| `controllers/CartController.php` | JSON add-to-cart + minicart data |
| `controllers/CriticalController.php` | Build-time critical css feed (Guzzle) |

## Config & release

- Only own setting: `catalog/frontend/split_frontend_catalog` (system.xml extends Catalog > Frontend; no own section, no ACL). Hides unsalable products unless `?out_of_stock=1` or `?stock=`; count returned as `toolbar.out_of_stock_count`.
- New translatable strings → el_GR CSV. New files outside `src/app/code` → add to `modman` (`src/app/...` → `app/...`), then rebuild `openmage/` (see `om-ddev`).
- Version lives in `composer.json` **and** `etc/config.xml` `<version>` — bump both, plus `CHANGELOG.md`. `release.yml` tags the unprefixed composer version (`0.5.0`) on push to master.
