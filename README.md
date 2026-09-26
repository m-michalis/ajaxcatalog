# Ajax Catalog for OpenMage/Magento

Only compatible with custom frontend. This module handles the backend.

## Features
TBA

## Installation

### Composer

```json
{
    "minimum-stability": "dev",
    "require": {
        "m-michalis/ajaxcatalog": "0.1.*"
    }
}
```

## Usage

### Webpack assets
Build output is read from `{magento_root}/assets`. Entry names map to layout handles through
`frontend/ajaxentries` in `config.xml` (e.g. entry `home` → `cms_index_index`); `shared` is loaded on every page.

When `assets/manifest.json` exists (e.g. [webpack-manifest-plugin](https://github.com/shellscape/webpack-manifest-plugin)),
it is the source of truth:

```json
{
  "home.js": "home.3f2a1c.js",
  "home.css": "home.9b8e7d.css",
  "shared.js": "shared.11aa22.js",
  "critical.home.css": "critical.home.44bb55.css",
  "media/icons/logo.png": "media/icons/logo.66cc77.png"
}
```

Values may be relative to `assets/`, prefixed with the public path (`/assets/…`), or absolute URLs.
Without a manifest the directory is scanned and file names (`{entry}.[hash].{ext}`) are parsed; the newest
file wins when older builds are still present.

Critical css: `critical.{entry}.[hash].css` is loaded blocking, and the entry's regular css (including
`shared`) is loaded asynchronously — or `uncritical.{entry}.[hash].css` instead, when the build emits one. An
uncritical file replaces all of that css, so generate it from the full css list the critical endpoint returns.
Critical/uncritical files are picked up from the directory even when the manifest does not list them.

### Add to cart
The `checkout/cart` helper is rewritten so that **every** add-to-cart URL on the site points to
`ajaxcatalog/cart/add`, which answers with JSON. Only `add` and `data` are served by that controller.

### Critical css endpoint
`ajaxcatalog/critical` fetches the pages configured under `frontend/ajaxcritical` for the critical css build.
It is available in developer mode only, unless a token is configured in `app/etc/local.xml`:

```xml
<config>
    <global>
        <ajaxcatalog>
            <critical_token>long-random-string</critical_token>
        </ajaxcatalog>
    </global>
</config>
```

and sent as the `X-Ajaxcatalog-Token` request header.

### Caching
Catalog routes answer with HTML or JSON on the same URL (JSON for XHR / `?isAjax=1`), so responses carry
`Vary: X-Requested-With`; JSON responses contain the session form key and are sent with `Cache-Control: private, no-store`.

## Development
```bash
ddev start
ddev setup-openmage   # OpenMage + sample data, module symlinked
ddev test
```

## Compatibility
- OpenMage 20.x (the cart helper rewrite relies on OpenMage-only methods)

## Roadmap & TODOs
TBA

## License
This module is released under the GPL-3.0 License.
