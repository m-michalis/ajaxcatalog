---
name: om-upstream-sync
description: "ajaxcatalog tooling upgrades — sync ECS, PHPStan, PHPCS, Rector, Cypress utils from OpenMage LTS / om-dev-template; bump dev tools."
---

# Syncing Quality Tooling (OpenMage LTS / om-dev-template)

Sources: OpenMage LTS (`https://github.com/OpenMage/magento-lts`, branch `main`) for tool configs and Cypress utils; `git@github.com:m-michalis/om-dev-template.git` for the DDEV/CI/test skeleton this repo was migrated from.

## Local deviations — PRESERVE on sync

Each one was found by running the toolchain. Blindly copying upstream or the template re-breaks it.

| File | Local value | Why |
|---|---|---|
| `.php-cs-fixer.dist.php` | `->withSets([SetList::PER_CS])` instead of `withPhpCsFixerSets(perCS30: true)` | ECS ≥13.3 turned `withPhpCsFixerSets()` into a no-arg stub → "Unknown named parameter $perCS30" fatal |
| `.php-cs-fixer.dist.php` | `NullableTypeDeclarationForDefaultNullValueFixer` **unconfigured** | The deprecated `use_nullable_type_declaration` option routes through `PhpCsFixer\Console\Application`, which ECS's scoped bundle lacks → fatal before analysis. Upstream still ships the option |
| `.rector.php` | skip `AbsolutizeRequireAndIncludePathRector` | Rewrites `require_once 'Mage/Checkout/controllers/CartController.php'` to a nonexistent `__DIR__` path → add-to-cart 500s |
| `.rector.php` | skip `PreferPHPUnitThisCallRector`, `DeclareStrictTypesTestsRector`, `SafeDeclareStrictTypesRector`, `FinalizeTestCaseClassRector`, constructor-promotion/variadic rules | Fight ECS, break Mage (not strict-types safe), or break parent signatures. Rector hard-fails on skip entries that aren't registered or don't exist — verify FQCNs against `vendor/` |
| `.phpstan.dist.neon` | `bootstrapFiles: openmage/vendor/autoload.php`, `magentoRootPath: openmage` | PHPUnit and OpenMage live in `openmage/`, not the root `vendor/` |
| `.phpcs.dist.xml` | `testVersion 8.2-`, `tests/*` excluded from `Ecg.Security` | PHPCompatibility checks nothing without it; test/seed code echoes and exits |
| `composer.json` scripts | `php -d error_reporting=24575` on phpcs; `--config=.rector.php` on rector | Deprecation noise; Rector ignores the dotfile without the flag |
| `phpunit.xml` | `Seed` suite + `defaultTestSuite="Unit,Integration"` | Seeders mutate the DB; no `--` inside XML comments (PHPUnit refuses to load) |
| `tests/bootstrap.php` | no `session.use_cookies=0`, developer mode on, sample-data guard | See `ajaxcatalog-testing` |
| `tests/fixtures/seed/lib.php` | `PHP_SAPI !== 'cli'` → 404 | Path repo symlinks the repo into the docroot |
| `tests/Base/AbstractTestCase.php` | `MODULE_ALIAS='ajaxcatalog'`, `CONFIG_SECTION='catalog'`, raw-row config backup | Module has no own config section; template restored inherited values as new rows |
| `.github/workflows/ci.yml` | no template-init step, `--with-sample-data`, Cypress + Seed steps, `permissions: contents: read` | Template's init step calls the deleted `scripts/init.sh` |
| `.github/workflows/release.yml` | tag check on unprefixed `$VERSION` via `env:` | Tags are `0.5.0`, not `v0.5.0` |
| `cypress/support/openmage/_utils/test.js` | module namespace line commented out | No admin page object |

## What to sync vs adapt

| Upstream | Action |
|---|---|
| `composer.json` require-dev versions | Copy versions, then `ddev composer update` and commit `composer.lock` |
| `.php-cs-fixer.dist.php` rules | Copy `withRules`/`withConfiguredRule`; keep our paths (`src`, `tests`), cache dir, and the deviations above |
| `.phpstan.dist.neon` | Copy level/includes/flags; drop upstream `excludePaths`, `ignoreErrors`, baselines |
| `.phpcs.dist.xml` | Copy `<rule>` blocks; drop upstream `<exclude-pattern>` for core legacy files |
| `.rector.php` | Copy PHP/prepared sets and generic skips; drop `OpenMage\Rector\Migration` rules and core path skips |
| `cypress/support/openmage/_utils/*`, `openmage.js`, `commands.js` | Copy verbatim, then re-comment the module line at the bottom of `test.js` |
| `cypress/support/openmage/backend|frontend/*`, `cypress/e2e/*` | Don't sync — core page objects/specs; ours are in `frontend/ajaxcatalog/` |
| om-dev-template `.ddev/commands/*`, `tests/Base/*`, `tests/fixtures/seed/*` | Diff by hand; keep the deviations above |

## Process

```bash
UPSTREAM="https://raw.githubusercontent.com/OpenMage/magento-lts/main"
curl -s "$UPSTREAM/.php-cs-fixer.dist.php" | diff - .php-cs-fixer.dist.php
curl -s "$UPSTREAM/.phpstan.dist.neon"     | diff - .phpstan.dist.neon
curl -s "$UPSTREAM/.phpcs.dist.xml"        | diff - .phpcs.dist.xml
curl -s "$UPSTREAM/.rector.php"            | diff - .rector.php
```

Verify — all must pass before committing:

```bash
ddev composer update && ddev lint && ddev lint rector
ddev test && ddev test --testsuite Seed
ddev cypress-run
curl -sk -X POST https://om-ajaxcatalog.ddev.site/ajaxcatalog/cart/add/product/234/   # must not be a PHP error page
```

## Gotchas

- A tool upgrade can surface new findings: fix them (no baseline), don't suppress.
- After `ddev lint fix` (Rector), always re-run the HTTP add-to-cart check: Rector rewrites can break runtime paths that tests don't cover.
- `macopedia/phpstan-magento1` executes controller files through its autoloader — top-level code in controllers must not call `Mage::` methods.
- PHPUnit comes from `setup-openmage` (`^10.0`, in `openmage/`), not from root `composer.json`.
- Use `vendor/bin/ecs`, never the `php-cs-fixer` binary directly.
