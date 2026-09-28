---
name: om-module-init
description: "Instantiate the OpenMage module template into a working module — requirements interview, scripts/init.sh flags, scaffold selection, seed/Cypress wiring, and the lint/test/seed verification gate. Load when scaffolding a new module from this template."
---

# Instantiating the OpenMage Module Template

This repo ships as a template. `scripts/init.sh` turns it into one real module, then deletes itself. This skill is the durable workflow — it survives that deletion, so a follow-up agent can still tell what was decided and why.

## Gotchas

- `scripts/init.sh` **does** handle vendor identity (`InternetCode` → your vendor, `internetcode` → lowercase, `m-michalis` → composer vendor, the composer.json author block), snake_case derivation, directory/file renames, Cypress hook uncommenting, and a generic sweep of any remaining placeholder-named path. Older docs claimed vendor had to be replaced by hand. That is **wrong** — do not do it manually, you will double-replace.
- The composer.json author email is parked behind sentinels during the vendor rewrite, so `m@internetcode.gr` is not mangled into `m@<vendor>.gr`. Do not "fix" the author block afterwards.
- `init.sh` deletes `scripts/init.sh`, `scripts/qa-instantiate.sh` and `scripts/tests/` on success. Pass `--keep-scripts` if you need to re-run it (e.g. during a dry run).
- The placeholder-path sweep uses `find -depth`, so scaffold-added paths (`sql/<alias>_setup/`, `design/.../ajax_catalog/`, `skin/.../ajax_catalog/`) are renamed too. `SCAFFOLDS.md`'s manual `git mv` block is a fallback for paths added *after* init ran.
- `.claude/skills/om-ajax_catalog/` is renamed only if it exists (guarded by `[ -d ]`). Fill it in after init.
- `MODULE_LOWERCASE` and `MODULE_SNAKE` differ. `ProductFeedSync` → `productfeedsync` (project/package name) and `product_feed_sync` (XML aliases, config paths). Never use one where the other belongs.

## Step 0 — Requirements interview

Ask before touching anything. Use a structured question tool if available.

| # | Question | Type |
|---|---|---|
| 1 | PascalCase module name? (`VendorSync`, `ProductFeed`) | text |
| 2 | PHP vendor namespace? (`InternetCode`, `Acme`) | text |
| 3 | Composer vendor slug? (`m-michalis`, `acme-corp`) | text |
| 4 | One-sentence description (max 100 chars) | text |
| 5 | Which seed types does development need? | multi: config, stores, attributes, categories, products, customers, orders, mock, none |
| 6 | Cypress scope? | multi: backend, frontend, none |

For 6: **backend** = admin UI (grids, forms, menus, system config). **frontend** = storefront pages. **none** = delete the Cypress surface entirely.

### Derived values

`init.sh` computes these — the table exists so you can predict the result and sanity-check the output.

| Variable | Derivation | Example |
|---|---|---|
| `MODULE` | Answer 1 verbatim | `ProductFeedSync` |
| `MODULE_LOWERCASE` | Answer 1 lowercased, no separators | `productfeedsync` |
| `MODULE_SNAKE` | Answer 1 snake_cased, acronym-aware | `product_feed_sync` |
| `PROJECT_NAME` | `om-` + `MODULE_LOWERCASE` | `om-productfeedsync` |
| `VENDOR` | Answer 2 verbatim | `Acme` |
| `VENDOR_LOWERCASE` | Answer 2 lowercased | `acme` |
| `COMPOSER_VENDOR` | Answer 3 | `acme-corp` |
| Config prefix | `VENDOR_LOWERCASE` + `_` + `MODULE_SNAKE` | `acme_product_feed_sync` |

## Step 1 — Run init

```bash
./scripts/init.sh --module ProductFeedSync \
    --vendor Acme \
    --composer-vendor acme-corp \
    --description "Vendor product feed synchronization" \
    --author-name "A Dev" \
    --author-email dev@acme.test
```

Positional form still works: `./scripts/init.sh VendorSync "Description"`. Defaults are `InternetCode` / `m-michalis` / `Michalis Michalis <m@internetcode.gr>`.

Add `--keep-scripts` to keep the template tooling around.

Verify zero placeholders survive:

```bash
grep -rn 'AjaxCatalog\|ajaxcatalog\|ajax_catalog\|om-ajaxcatalog\|AJAX catalog listings, AJAX add-to-cart and webpack asset injection for a custom OpenMage frontend' \
  --exclude-dir=.git --exclude-dir=openmage --exclude-dir=vendor --exclude-dir=node_modules .
find . -name '*__MODULE*' -o -name '*om-ajaxcatalog*' | grep -v node_modules
```

Both must return nothing.

## Step 2 — Choose the scaffolds

Read `src/app/code/local/<Vendor>/<Module>/SCAFFOLDS.md` first. Everything except `Helper/Data.php` and `etc/config.xml` is optional and inert until the matching `etc/config.xml` block is uncommented.

**Delete what the module does not need.** A module with no admin screens should end up with no `Block/Adminhtml/`, no `controllers/Adminhtml/`, and no adminhtml layout XML. Leftover scaffolds are dead code that lint still has to pass and a future reader has to reason about.

| Need | Uncomment in `etc/config.xml` | Keep |
|---|---|---|
| Models + DB tables | `<models>`, `<resources>` | `sql/<alias>_setup/`, your own `Model/` |
| Blocks / templates | `<blocks>` | `Block/`, design templates |
| Event observers | `<events>` (+ `<models>`) | `Model/Observer.php` |
| Storefront route | `<frontend>` | `controllers/IndexController.php`, frontend layout/template |
| Admin route | `<admin>`, `<adminhtml><layout>` | `controllers/Adminhtml/`, `Block/Adminhtml/`, `etc/adminhtml.xml` `<acl>` + `<menu>` |
| Admin config screen | — | `etc/system.xml`, source models |
| Default config values | `<default>` | — |

For each file type you keep (design, skin, shell), uncomment the matching line in `modman`. If the module has no admin config at all, delete `etc/system.xml` and strip `etc/adminhtml.xml` down to the ACL it still needs.

Load `om-adminhtml`, `om-eav` or `om-install-scripts` for the details of each area.

## Step 3 — Wire the seeders

`ddev seed` dispatches to `tests/fixtures/seed/<type>.php`. Every seeder is idempotent and prefix-scoped (`QA-` SKUs, `qa-` emails) so `ddev seed clean` removes exactly what was seeded.

For the types chosen in answer 5, edit the matching seeder to cover the module's own domain — new attributes, new config paths under `<vendor>_<module_snake>/`, entities the module reads. Add a new seeder by dropping `tests/fixtures/seed/<name>.php` in place and adding `<name>` to `VALID_TYPES` in `.ddev/commands/web/seed` (and to `DEFAULT_ORDER` if plain `ddev seed` should run it).

If the answer was "none": leave the seeders alone. They seed generic QA data, they are harmless, and the Integration suite under `tests/Integration/Seed/` uses them.

## Step 4 — Wire Cypress

**none** — remove the surface:

```bash
rm -rf cypress/ cypress.config.js package.json
rm -f .ddev/commands/host/cypress-run .ddev/commands/host/cypress-open
```

**backend** — `init.sh` already renamed `cypress/support/openmage/backend/catalog/<module_snake>.js` and `cypress/e2e/openmage/backend/catalog/<module_snake>.cy.js`, uncommented the `e2e.js` import and the `test.js` namespace line. What is left is real content: menu link selector, page title, grid ids, URL fragments, `__fields`, `__tabs`. Then `npm install`.

**frontend** — create `cypress/e2e/openmage/frontend/` and write specs against the `cy.openmage.test.frontend.*` tree upstream already provides. No admin login needed.

Load `om-cypress` for the framework details.

## Step 5 — Verification gate

The module is **not** scaffolded until all three pass:

```bash
ddev start
ddev setup-openmage
ddev lint     # ECS + PHPStan level 8 + PHPCS
ddev test     # PHPUnit Unit + Integration
ddev seed     # config, stores, attributes, categories, products, customers, orders
```

Do not report success, do not move on to feature work, and do not delete anything else until `ddev lint && ddev test && ddev seed` is green end to end. A red gate here is always a wiring mistake from steps 1–4, never a template bug — the template ships green.

Admin: `https://<PROJECT_NAME>.ddev.site/admin` — `admin` / `veryl0ngpassw0rd`.

## Step 6 — Documentation

1. `CLAUDE.md` — fill in Module Purpose, Architecture, Key Files. Delete the skills rows for areas the module does not use.
2. `.claude/skills/om-<module_snake>/SKILL.md` — the per-module stub. Fill it in as you build; it is the place module-specific gotchas belong.
3. `CHANGELOG.md` — real date, initial feature list.
4. `README.md` — install, configure, use, for humans.
5. `GET_STARTED.md` — delete it. It is a template pointer, not project documentation. This skill stays.
