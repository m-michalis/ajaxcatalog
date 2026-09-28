---
name: om-ddev
description: "ajaxcatalog DDEV environment — setup-openmage, sample data, reset, seed, lint, module symlinking, CI pipeline."
---

# DDEV Environment (om-ajaxcatalog)

Project `om-ajaxcatalog`, https://om-ajaxcatalog.ddev.site, admin `admin` / `veryl0ngpassw0rd`. PHP 8.2, MariaDB 11.8, Node 22, `MAGE_IS_DEVELOPER_MODE=1`.

## Gotchas

- **No PHP/Composer on the host.** Everything PHP goes through `ddev exec`, `ddev composer` or a `.ddev/commands/web/*` wrapper. Cypress/npm run on the host.
- **Always install with sample data**: `ddev setup-openmage --with-sample-data`. The tests assert against the Magento 1.9 catalog. The dump is imported *before* `install.php` (ordering is load-bearing); `install.php` then upgrades it.
- **`openmage/` is ephemeral** (gitignored). Moving files under `src/` or editing `modman` leaves stale symlinks → `ddev reset-openmage --full && ddev setup-openmage --with-sample-data`.
- **`setup-openmage` is idempotent** — when installed it just prints URLs. Reinstall: `ddev reset-openmage && ddev setup-openmage --with-sample-data`.
- **Module is installed via composer path repo** (`../`, symlink) as `m-michalis/ajaxcatalog:@dev`; `magento-composer-installer` reads `modman` to symlink `src/app/...` into `openmage/app/...`. `modman link` is not used.
- **The path repo symlinks the whole project into the docroot** (`openmage/vendor/m-michalis/ajaxcatalog`). Anything in the repo with a `.php` extension is web-reachable under DDEV. `tests/fixtures/seed/lib.php` refuses non-CLI SAPIs — every seed/e2e script must keep including it.
- **Two composer roots.** Project root `vendor/` = lint tooling (`ddev composer install`, `composer.lock` committed). `openmage/vendor/` = OpenMage + PHPUnit 10 (installed by `setup-openmage`).
- **`ddev <cmd>`: No such file or directory** after recreating `.ddev/` → `ddev restart`.
- **Composer stalls in setup** → a plugin is missing from `allow-plugins` (needs `cweagans/composer-patches`, `php-http/discovery`).
- **Clear cache after DB config edits:** `ddev exec "rm -rf openmage/var/cache/*"`.
- DB inside the container: host/user/pass/name all `db`. Use `${DDEV_PRIMARY_URL}` in commands, never a hardcoded host.
- Default currency is EUR (template default).

## Key Files

| File | Purpose |
|---|---|
| `.ddev/config.yaml` | Project name, PHP/DB/Node versions, env |
| `.ddev/commands/web/setup-openmage` | Composer project + path repo, optional sample data, `install.php`, reindex |
| `.ddev/commands/web/reset-openmage` | Drop DB + local.xml; `--full` deletes `openmage/` |
| `.ddev/commands/web/test` | PHPUnit with root `phpunit.xml` (args passed through) |
| `.ddev/commands/web/seed` | Dispatcher over `tests/fixtures/seed/<type>.php` |
| `.ddev/commands/web/lint` | `all` / `ecs` / `phpstan` / `phpcs` / `rector` / `fix` |
| `.ddev/commands/host/cypress-run`, `cypress-open` | Cypress from the host (auto `npm install`) |
| `.github/workflows/ci.yml` | start → setup (sample data) → lint → test → Cypress → Seed suite |
| `.github/workflows/release.yml` | Tags/releases `composer.json` version if the tag doesn't exist |

## Seeding

`ddev seed` maps each type 1:1 to `tests/fixtures/seed/<type>.php`. To add one: drop the file, add it to `VALID_TYPES` in `.ddev/commands/web/seed` (and `DEFAULT_ORDER` if plain `ddev seed` should run it).

| Type | Creates |
|---|---|
| `config` | Storefront prerequisites (flatrate, checkmo, flat catalog off) + `split_frontend_catalog=0` |
| `stores` | `qa_de`, `qa_it` store views |
| `attributes` | `qa_supplier_code`, `qa_grade` |
| `categories` | QA Store > QA Electronics / QA Apparel |
| `products [n]` / `customers [n]` / `orders [n]` | `QA-` products, `qa-` customers, checkmo orders |
| `mock` | Links `tests/fixtures/mock` into the docroot |
| `clean` | Deletes everything prefixed `QA-`/`qa-`/`qa_` — never runs by default |

Seeders are idempotent and prefix-scoped; keep the prefixes when extending. The module config path lives in `SEED_MODULE_SPLIT_CATALOG_PATH` (`lib.php`).

## Commands

```bash
ddev start && ddev setup-openmage --with-sample-data
ddev reset-openmage --full                 # nuke openmage/
ddev composer install                      # root lint tooling
ddev lint && ddev lint rector              # gate: all must be clean
ddev lint fix                              # ECS + Rector auto-fix
ddev test                                  # Unit + Integration
ddev test --testsuite Seed                 # seeders (mutates DB)
ddev seed / ddev seed products 25 / ddev seed clean
ddev mysql -e "SELECT ..."
```

## Command anatomy

`.ddev/commands/web/*` run inside the web container, `.ddev/commands/host/*` on the host. Each needs a `#!/bin/bash` shebang, `## Description:` / `## Usage:` / `## Example:` headers, and no `.sh` extension.
