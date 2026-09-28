---
name: om-cypress
description: "ajaxcatalog Cypress E2E — JSON endpoint specs, cy.request, vendored cy.openmage utils, seed hooks, cypress-run."
---

# Cypress E2E (ajaxcatalog)

The module has no admin UI and the storefront JS lives in the theme repo, so specs test the **JSON endpoints** with `cy.request`, not page interactions. They run against the 1.9 sample data.

## Gotchas

- **Upstream storefront specs don't fit this module.** Every add-to-cart URL is rewritten to `ajaxcatalog/cart/add` (JSON), so core cart/product click-through specs fail. They were removed deliberately.
- **Vendored files — never edit:** `cypress/support/commands.js`, `cypress/support/openmage.js`, `cypress/support/openmage/_utils/*`. An upstream sync overwrites them. Local commands go in `cypress/support/module-commands.js`; module page objects under `cypress/support/openmage/frontend/ajaxcatalog/`.
- `_utils/test.js` keeps the module backend namespace line **commented** (`// cy.openmage.test.backend.catalog.ajax_catalog = {};`) — there is no admin page object.
- `cypress/support/openmage/backend/dashboard.js` must stay imported: `_utils/admin.js` reads it to confirm logins. The `dashbord` typo in `test.js` is upstream's — don't fix it.
- **Import order in `e2e.js` is load-bearing:** `openmage.js` → `_utils/*` → `module-commands` → page objects.
- **Form key:** take it from `/customer/account/login/` (`ajaxcatalog.formKey()`); category pages carry a stale block-cached key.
- **POST without a session cookie** redirects to `/enable-cookies` (core) — call `formKey()` first even when testing an invalid key.
- **Empty cart** reports `count: null`, not `0`.
- `before()` in `e2e.js` runs `ddev seed config` once per spec file (idempotent). Skip with `--env seed=false`. Nothing cleans up automatically.
- Cypress runs on the **host**; PHP only via `cy.exec('ddev …')` (`cy.ddevRun`, `cy.ddevExec` in `module-commands.js`). Those commands are string-concatenated into the host shell — keep arguments free of spaces/quotes.
- `chromeWebSecurity: false` in `cypress.config.js` is required for DDEV's self-signed certs.
- Every `it()` starts with a stable `SC-NN` id; append new ids, never renumber.

## Files

| Path | Purpose |
|---|---|
| `cypress.config.js` | baseUrl `https://om-ajaxcatalog.ddev.site`, admin creds, timeouts |
| `cypress/support/e2e.js` | Entry: imports + seed `before()` hook |
| `cypress/support/openmage/frontend/ajaxcatalog/api.js` | Endpoint contract (`config`: URLs, expected JSON keys, sample product 234) + `formKey()` |
| `cypress/e2e/openmage/frontend/ajaxcatalog/listing.cy.js` | HTML vs XHR JSON, headers, search, `?isAjax=1` |
| `cypress/e2e/openmage/frontend/ajaxcatalog/cart.cy.js` | Empty cart, invalid form key, add + minicart, blocked core actions (404) |
| `cypress/support/module-commands.js` | `ddevExec`, `ddevRun`, `seedConfig`, `setStoreConfig`, `seedCustomer`, `seedClean` |
| `tests/Support/e2e_*.php` | CLI helpers the commands call (non-CLI SAPIs are refused) |
| `package.json` / `package-lock.json` | Cypress ^14; CI uses `npm ci` |

## Adding a spec

1. Put expected URLs/keys in `api.js` `config` (one contract, many specs).
2. Spec in `cypress/e2e/openmage/frontend/ajaxcatalog/<area>.cy.js`: `const test = cy.openmage.test.frontend.ajaxcatalog.config;`.
3. `cy.clearCookies()` in `beforeEach` when the test depends on an empty session/cart.
4. Assert on JSON keys and headers (`content-type`, `vary`, `cache-control`), not on volatile values (form keys, quote ids, HTML).

## Commands

```bash
npm ci                                   # first time (lockfile committed)
ddev cypress-run                         # headless, all specs
npx cypress run --config-file cypress.config.js --spec 'cypress/e2e/openmage/frontend/ajaxcatalog/*.cy.js'
ddev cypress-open                        # GUI
```
