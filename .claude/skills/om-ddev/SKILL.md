---
name: om-ddev
description: "OpenMage DDEV environment — setup-openmage, reset, seed, composer path repos, module symlinking. Load for any DDEV or environment task."
---

# OpenMage DDEV Environment

## Gotchas

### `ddev <command>`: No such file or directory

*Symptom*: `ddev seed` (or any custom command) fails with `/mnt/ddev_config/commands/web/seed: No such file or directory`, even though the file exists on the host and is executable.

*Cause*: `.ddev/` was deleted and recreated while the container was running, so the bind mount still points at the old, now-dangling inode.

*Fix*: `ddev restart`. Nothing about the file itself is wrong.

### `composer install` stalls during setup-openmage

*Symptom*: non-interactive `composer install` hangs or fails inside `setup-openmage`, usually right after dependency resolution.

*Cause*: a plugin is not in `allow-plugins`, and Composer's interactive consent prompt has nowhere to go.

*Fix*: the `allow-plugins` list must include `cweagans/composer-patches` and `php-http/discovery` alongside `magento-hackathon/magento-composer-installer`.

### General

- `openmage/` is **ephemeral** — gitignored, created by `ddev setup-openmage`, destroyed by `reset-openmage --full`. Never commit anything inside it.
- Module is symlinked via **composer path repo**, not modman CLI. The `modman` file at repo root tells `magento-composer-installer` where to put symlinks. Running `modman link` directly will NOT work.
- DDEV config uses `om-ajaxcatalog` placeholder. Must be replaced before `ddev start` or DDEV will create a project literally named `om-ajaxcatalog`.
- `setup-openmage` is **idempotent** — re-running it when already installed prints URLs and exits. To reinstall: `ddev reset-openmage && ddev setup-openmage`.
- Admin credentials are **hardcoded**: `admin` / `veryl0ngpassw0rd`. Cypress config and seed scripts must match.
- PHPUnit runs from **inside the web container** via `ddev test`, but `phpunit.xml` lives at **project root** (outside `openmage/`). The test command does `cd /var/www/html/openmage && php vendor/bin/phpunit --configuration /var/www/html/phpunit.xml`.
- DB creds inside container are always `db`/`db`/`db`/`db` (host/user/pass/name). Use `${DDEV_PRIMARY_URL}` for URLs, never hardcode hostnames.
- There is **no PHP and no Composer on the host**. Every PHP command runs through `ddev exec` or a `.ddev/commands/web/*` wrapper. Do not suggest `php`, `composer` or `vendor/bin/*` as host commands.

## Key Files

| File | Purpose |
|---|---|
| `.ddev/config.yaml` | Project name, PHP version, DB version, web_environment |
| `.ddev/commands/web/setup-openmage` | Install OpenMage + symlink module via composer path repo |
| `.ddev/commands/web/reset-openmage` | Drop DB, remove local.xml, optional `--full` to nuke openmage/ |
| `.ddev/commands/web/test` | Run PHPUnit (passthrough args via `$@`) |
| `.ddev/commands/web/seed` | Dispatcher over `tests/fixtures/seed/*.php` |
| `.ddev/commands/host/cypress-run` | Headless Cypress from host (auto `npm install`) |
| `.ddev/commands/host/cypress-open` | Cypress GUI from host (auto `npm install`) |
| `.ddev/commands/web/lint` | Run quality checks: all, ecs, phpstan, phpcs, rector, fix |

## How Module Symlinking Works

1. `setup-openmage` creates `openmage/composer.json` via `composer init` + `composer config`
2. Adds a **path repository** pointing to `../` (the module repo root): `composer config repositories.module '{"type":"path","url":"../","options":{"symlink":true}}'`
3. `composer require "m-michalis/om-{module}:@dev"` installs the module
4. `magento-hackathon/magento-composer-installer` reads the `modman` file and creates symlinks into `openmage/app/code/local/`, `openmage/app/etc/modules/`, etc.

After adding new paths to `modman`, run: `ddev exec "cd /var/www/html/openmage && composer update m-michalis/om-{module} --prefer-source"`

## Commands

```bash
ddev start                    # Start environment
ddev setup-openmage           # Install OpenMage + module (first time)
ddev reset-openmage           # Reset DB only
ddev reset-openmage --full    # Nuke openmage/ and reinstall
ddev test                     # Run all PHPUnit tests
ddev test --filter testFoo    # Run specific test
ddev seed                     # Default order: config stores attributes categories products customers orders
ddev seed products 25         # One type, optional count (products/customers/orders)
ddev seed clean               # Delete everything the seeders created
ddev lint                     # Run all quality checks (ECS + PHPStan + PHPCS)
ddev lint phpstan             # Run PHPStan only
ddev lint ecs                 # Run ECS only
ddev lint phpcs               # Run PHPCS only
ddev lint rector              # Rector dry run
ddev lint fix                 # Auto-fix code style (ECS + Rector)
ddev ssh                      # Shell into web container
ddev exec "php shell/..."     # Run OpenMage shell script
```

## Seeding

`ddev seed` is a **dispatcher**, not a set of bash functions. Each type maps 1:1 to `tests/fixtures/seed/<type>.php`. To add one: drop the file in place, add its name to `VALID_TYPES` in `.ddev/commands/web/seed`, and to `DEFAULT_ORDER` if plain `ddev seed` should run it.

| Type | What it creates |
|---|---|
| `config` | Module + storefront config (flatrate shipping, checkmo payment) |
| `stores` | Extra store views (`qa_de`, `qa_it`) |
| `attributes` | Product EAV attributes (`qa_supplier_code`, `qa_grade`) |
| `categories` | QA Store > QA Electronics / QA Apparel |
| `products [n]` | Simple products with stock and images (default 10) |
| `customers [n]` | Confirmed accounts with addresses (default 3) |
| `orders [n]` | Real quote → order via checkmo (default 2) |
| `mock` | Links `tests/fixtures/mock` into the docroot |
| `clean` | Deletes everything the seeders created — never runs by default |

`DEFAULT_ORDER` is dependency-ordered: products need categories and an attribute set, orders need config plus products plus customers.

Every seeder is **idempotent** and **prefix-scoped** — `QA-` SKUs, `qa-` emails, `qa_` codes. That is what makes `ddev seed clean` able to remove exactly what was seeded and nothing else. Keep the prefixes when you extend a seeder.

## Template QA Harness

`scripts/qa-instantiate.sh` instantiates this template into a throwaway DDEV project under `/tmp/opencode/` so the whole pipeline (init → lint → test → seed) can be verified for real. Template-only tooling: `scripts/init.sh` deletes it when a real module is generated.

```bash
./scripts/qa-instantiate.sh                          # fresh scratch project (om-qatest)
./scripts/qa-instantiate.sh --module Foo --vendor Acme
./scripts/qa-instantiate.sh --refresh                 # reuse the OpenMage install, preserving vendor/ and .ddev/
./scripts/qa-instantiate.sh --with-sample-data
./scripts/qa-instantiate.sh --destroy                 # tear the scratch project down
```

`--refresh` is the fast loop: it keeps the installed OpenMage, `vendor/` and `.ddev/` instead of reinstalling from scratch. Override the parent directory with `SCRATCH_ROOT`.

## DDEV Command Anatomy

Commands in `.ddev/commands/web/` run **inside** the web container. Commands in `.ddev/commands/host/` run on the **host machine**. All must have:
- `#!/bin/bash` shebang
- `## Description:` / `## Usage:` / `## Example:` DDEV headers
- No `.sh` extension
