---
name: om-cypress
description: "OpenMage Cypress E2E testing — cy.openmage.* framework, page objects, admin grid/form testing, backend/frontend specs. Load for any Cypress or E2E task."
---

# OpenMage Cypress E2E Framework

## Gotchas

### A custom command disappeared after an upstream sync

*Symptom*: `cy.myCustomCommand is not a function` after syncing Cypress utilities from OpenMage LTS.

*Cause*: `cypress/support/commands.js`, `cypress/support/openmage.js` and everything under `cypress/support/openmage/_utils/` are **vendored verbatim** from upstream. A sync overwrites them wholesale.

*Fix*: never add local code to those files. Put module commands in `cypress/support/module-commands.js` and import it from `e2e.js`. That file is ours and is never overwritten.

### Admin URL 404s or bounces to login

*Cause*: admin URLs carry a per-session secret key (`/admin/mymodule/index/key/<hash>/`). A hand-constructed URL has no valid key.

*Fix*: navigate by **clicking through the UI** — `cy.openmage.admin.goToSection()` then the menu link. Assert on URL *fragments* (`mymodule/index`), never on full URLs.

### Don't redefine what upstream already provides

Upstream ships `cy.openmage.test.backend.system.config` (including `clickSave()`) and the whole `cy.openmage.test.frontend.*` tree. Build on them. Redefining them means your version silently diverges at the next sync.

### General

- The `cy.openmage.*` namespace is initialized in `cypress/support/openmage.js` and populated by files in `_utils/`. Import order matters — `openmage.js` MUST come before any `_utils/*.js`.
- `test.js` has a **typo**: `cy.openmage.test.backend.dashbord` (not "dashboard"). This is intentional — matches OpenMage core. `admin.js` login checks `dashbord.config.index.url`. Don't "fix" it.
- Selector convention: `_` prefix = CSS selector, `__` prefix = metadata (buttons, fields, tabs, classes). Example: `_button` is a selector, `__buttons` is an object of button configs.
- Admin login uses **hardcoded** creds in `admin.js`: `admin` / `veryl0ngpassw0rd`. Must match `setup-openmage`.
- `cypress.config.js` has `chromeWebSecurity: false` — required for DDEV's self-signed certs.
- Module page config and spec files ship with a `ajax_catalog` placeholder in both filename and content. `scripts/init.sh` handles both, plus uncommenting the `e2e.js` import and the `test.js` namespace line. Do not do it by hand.
- PHP exists **only inside DDEV**. Cypress runs on the host, so it reaches PHP via `cy.exec('ddev exec …')`.

## Directory Structure

```
cypress/
├── e2e/openmage/
│   ├── backend/catalog/              # Admin test specs (.cy.js)
│   └── frontend/                     # Storefront specs (create as needed)
├── fixtures/                         # Test data files
└── support/
    ├── e2e.js                        # Entry point — import order matters
    ├── commands.js                   # VENDORED — adminGetConfiguration, adminSaveConfiguration
    ├── module-commands.js            # OURS — put every local command here
    ├── openmage.js                   # Namespace init (cy.openmage = {})
    └── openmage/
        ├── _utils/                   # VENDORED verbatim from upstream — DO NOT MODIFY
        │   ├── admin.js              # login(), goToPage(), goToSection()
        │   ├── check.js              # buttons(), fields(), grid(), tabs(), title(), url()
        │   ├── test.js               # Base button/grid configs, buttonsSets
        │   ├── tools.js              # click(), grid.clickFirstRow/SortedColumn/Contains
        │   ├── utils.js              # generateRandomEmail(), screenshot()
        │   └── validation.js         # fillFields(), emptyFields(), has*Message(), validators
        └── backend/catalog/
            └── {module}.js           # Module page config (selectors, buttons, fields)
```

## Page Object Pattern

Every admin page needs a **config object** in `support/openmage/backend/{section}/{module}.js`:

```js
const base = cy.openmage.test.backend.__base;
test.config.index = {
    title: 'Page Heading',           // Text in h3.icon-head
    url: 'mymodule/index',           // URL fragment for assertions
    grid: {
        _: '#grid_id',               // Grid container selector
        _table: '#grid_id_table',    // Grid table selector
        sort: { order: 'col', dir: 'desc' },
    },
    __buttons: { add: { _: '...', __class: ['scalable','add'], click: () => {...} } },
    __fields: { name: { _: '[name="name"]' } },
    __tabs: { general: '#tabs_general' },
};
```

Then the spec references it: `const test = cy.openmage.test.backend.catalog.mymodule.config;`

## Pre-defined Button Sets

Use `base.__buttonsSets.edit` (save, saveAndContinue, delete, back, reset) or `base.__buttonsSets.new` (save, saveAndContinue, back, reset) instead of defining buttons manually for standard pages.

## Validation Helpers

| Validator | CSS Class | Error Message |
|---|---|---|
| `validation.requiredEntry` | `required-entry` | "This is a required field." |
| `validation.digits` | `validate-digits` | "Please use numbers only..." |
| `validation.number` | `validate-number` | "Please enter a valid number..." |

`validation.pageElements(test, test.index)` runs ALL checks at once (buttons, fields, grid, tabs, title, url, navigation).

## Writing a New Test

1. Create page config: `cypress/support/openmage/backend/{section}/{module}.js`
2. Initialize namespace in `test.js`: `cy.openmage.test.backend.{section}.{module} = {};`
3. Import in `e2e.js`: `import './openmage/backend/{section}/{module}'`
4. Create spec: `cypress/e2e/openmage/backend/{section}/{module}.cy.js`
5. Pattern: `beforeEach` → login + navigate, `it` → check/tools/validation
6. Local commands go in `cypress/support/module-commands.js`, never in `commands.js`

### Scenario ids

Every `it()` title starts with an `SC-*` scenario id so a failure in CI output maps back to a documented scenario without reading the spec:

```js
it('SC-01 renders the grid with the expected columns', () => { /* ... */ });
it('SC-02 rejects an empty required field', () => { /* ... */ });
```

Ids are per-spec and stable. Renumbering an existing id breaks the mapping — append instead.

### Reaching PHP from a spec

Cypress runs on the host where no PHP exists. Shell out through DDEV:

```js
cy.exec('ddev seed clean');
cy.exec('ddev seed products 3');
```

## Commands

```bash
npm install              # First time — install Cypress
ddev cypress-open        # Interactive GUI
ddev cypress-run         # Headless (CI)
ddev cypress-run --spec cypress/e2e/openmage/backend/catalog/mymodule.cy.js
```
