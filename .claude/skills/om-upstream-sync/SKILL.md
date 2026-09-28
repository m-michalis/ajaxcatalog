---
name: om-upstream-sync
description: "Sync quality tooling from OpenMage LTS upstream — update ECS, PHPStan, PHPCS, Rector, Cypress configs when upstream changes. Load when upgrading or auditing tooling."
---

# Syncing Quality Tooling from OpenMage LTS

When OpenMage LTS updates its code quality or Cypress configs, this module template should follow. This skill explains what to check, what to copy, and what to adapt.

## Local deviations — PRESERVE on sync

Every item below is a deliberate divergence from upstream, discovered by running the toolchain. A sync that blindly copies upstream re-breaks each one silently. Check this list **before** committing a sync.

### ECS dies with a missing Symfony Console class

*Symptom*: `ddev lint` aborts with `Fatal error: Class "ECSPrefix202607\Symfony\Component\Console\Application" not found` and reports zero files analysed.

*Cause*: `.php-cs-fixer.dist.php` configured the **deprecated** option `['use_nullable_type_declaration' => true]` on `NullableTypeDeclarationForDefaultNullValueFixer`. Configuring a deprecated option routes through php-cs-fixer's deprecation-notice path, which references `PhpCsFixer\Console\Application`. ECS ships a scoped bundle that vendors no `symfony/console`, so the class does not exist and the process dies before analysis.

*Fix*: keep the fixer, drop the option. **Upstream OpenMage LTS still ships that option — do NOT copy it back.**

### Other pinned deviations

| File | Local value | Why it must survive |
|---|---|---|
| `composer.json` | `phpcs:test` keeps the `php -d error_reporting=24575` prefix | Suppresses deprecation noise that otherwise buries real PHPCS output |
| `composer.json` | `rector:test` / `rector:fix` keep `--config=.rector.php` | Rector auto-discovers `rector.php`, **not** the dotfile. Without the flag the config is silently ignored and Rector runs on defaults |
| `.phpcs.dist.xml` | `<config name="testVersion" value="8.2-"/>` | PHPCompatibility target; upstream supports older PHP |
| `.phpcs.dist.xml` | `tests/*` excluded from `Ecg.Security` | ECG security rules flag legitimate test fixtures |
| `.phpstan.dist.neon` | `bootstrapFiles: openmage/vendor/autoload.php` | PHPUnit lives inside `openmage/`, not at project root; without it PHPStan cannot resolve PHPUnit classes |
| `cypress/support/openmage/_utils/test.js` | The commented module-namespace tail line | `scripts/init.sh` uncomments it during instantiation. Deleting it breaks init |

## Upstream Source

Repo: `https://github.com/OpenMage/magento-lts` (branch: `main`)

Key upstream files:
```
.php-cs-fixer.dist.php      → ECS / code style rules
.phpstan.dist.neon           → PHPStan config + baselines
.phpcs.dist.xml              → PHPCS + ECG coding standard rules
.rector.php                  → Rector refactoring rules
.phpmd.dist.xml              → PHPMD (we don't use this — skip)
composer.json                → dev dependency versions
cypress/                     → inside the installed openmage/ (vendor copy)
```

## What to Sync vs Adapt

| Upstream File | Action | Why |
|---|---|---|
| `composer.json` require-dev versions | **Copy versions** | Stay on same tool versions as upstream |
| `.php-cs-fixer.dist.php` rules | **Copy rules, adapt paths** | Same code style; our paths are `src/` + `tests/` not `app/code/core` |
| `.phpstan.dist.neon` level + extensions | **Copy level/includes, adapt paths** | Same strictness; strip upstream baselines and path exclusions |
| `.phpcs.dist.xml` rule refs + excludes | **Copy rule config, adapt paths** | Same ECG standard; strip upstream legacy exclusions |
| `.rector.php` prepared sets + skips | **Copy sets + generic skips, drop OpenMage\\Rector\\Migration rules** | Migration rules are upstream-internal; keep the PHP version sets and prepared sets |
| `cypress/support/openmage/_utils/*` | **Copy verbatim** | These are the generic OpenMage test framework — must match upstream |
| `cypress/support/openmage/backend/*` | **Ignore** | These are page configs for OpenMage core admin pages, not our module |

## Step-by-Step Sync Process

### 1. Check upstream changes

```bash
# Fetch upstream config files (no clone needed)
UPSTREAM="https://raw.githubusercontent.com/OpenMage/magento-lts/main"
curl -sO "$UPSTREAM/.php-cs-fixer.dist.php"
curl -sO "$UPSTREAM/.phpstan.dist.neon"
curl -sO "$UPSTREAM/.phpcs.dist.xml"
curl -sO "$UPSTREAM/.rector.php"
curl -sO "$UPSTREAM/composer.json"
```

Or read them directly on GitHub — the relevant sections are small.

### 2. Update dev dependency versions

Compare `require-dev` versions in upstream `composer.json` with ours. Update ours to match:

```bash
# Key packages to check:
# phpstan/phpstan, phpstan/phpstan-strict-rules, phpstan/phpstan-deprecation-rules
# phpstan/phpstan-phpunit, phpstan/extension-installer
# macopedia/phpstan-magento1
# friendsofphp/php-cs-fixer, symplify/easy-coding-standard
# squizlabs/php_codesniffer, magento-ecg/coding-standard
# phpcompatibility/php-compatibility
# rector/rector
```

After updating versions: `composer update --dev`

### 3. Sync ECS config (`.php-cs-fixer.dist.php`)

**Copy**: All `->withRules([...])` and `->withConfiguredRule(...)` calls.
**Adapt**: Keep `->withPaths([__DIR__ . '/src', __DIR__ . '/tests'])` — upstream scans `app/code/core`, `lib/`, `shell/`, etc.
**Keep**: Our `->withCache(directory: __DIR__ . '/.cache/.ecs.cache')`.

Gotcha: upstream may add new `PhpCsFixer\Fixer\*` classes. If ECS fails after sync, a new fixer might need a new php-cs-fixer version.

### 4. Sync PHPStan config (`.phpstan.dist.neon`)

**Copy**: `level`, `strictRules`, `phpVersion`, `includes` (extension neon files), and `checkFunctionNameCase` / `checkInternalClassCaseSensitivity` / `treatPhpDocTypesAsCertain` flags.
**Adapt**: Keep our paths (`src`, `tests`), our `magentoRootPath` (`%currentWorkingDirectory%/openmage`), our `tmpDir`.
**Drop**: All upstream `excludePaths` (those are for core legacy files), `ignoreErrors` (those are core-specific), and baselines (`_loader.php`).

If upstream bumps `phpVersion.min`, consider bumping our `composer.json` PHP requirement too.

### 5. Sync PHPCS config (`.phpcs.dist.xml`)

**Copy**: All `<rule ref="...">` blocks and their `<exclude>` children.
**Adapt**: Keep `<file>src/</file>` and `<file>tests/</file>` — upstream scans `app/code/core`, `lib/`, etc.
**Drop**: All upstream `<exclude-pattern>` entries (those are for core legacy files like Mysql4, mcrypt, etc.).

If ECG standard adds new rules, they'll appear in the upstream config as new `<rule>` or `<exclude>` entries.

### 6. Sync Rector config (`.rector.php`)

**Copy**: `->withPhpSets(...)`, `->withPreparedSets(...)`, and generic `->withSkip([...])` entries (the ones about rector behavior, not file paths).
**Drop**: All `OpenMage\Rector\Migration` rules — those are internal to the upstream repo for renaming deprecated methods across the core. They don't apply to module code.
**Drop**: All path-specific skips (`__DIR__ . '/app/code/core/...'`).
**Adapt**: Keep our `->withPaths([__DIR__ . '/src', __DIR__ . '/tests'])`.

If upstream changes `php81: true` to `php82: true`, update ours to match.

### 7. Sync Cypress utilities

The `cypress/support/openmage/_utils/` files are the **generic OpenMage test framework**. When upstream updates these, our copies should match.

```bash
# Source: installed OpenMage inside openmage/vendor/openmage/magento-lts/cypress/support/
# Or fetch from GitHub:
UPSTREAM="https://raw.githubusercontent.com/OpenMage/magento-lts/main/cypress/support"

# Files to sync (copy verbatim):
# openmage/_utils/admin.js
# openmage/_utils/check.js
# openmage/_utils/test.js     ← CAREFUL: see below
# openmage/_utils/tools.js
# openmage/_utils/utils.js
# openmage/_utils/validation.js
# openmage.js
# commands.js
```

**CAREFUL with `test.js`**: Upstream's `test.js` ends with page namespace declarations for core admin pages (catalog, customer, sales, etc.). Our version ends with our module's namespace. After copying, restore the module-specific lines at the bottom:
```js
cy.openmage.test.backend.catalog = {};
// Module page namespace — uncomment and modify for your module:
// cy.openmage.test.backend.catalog.ajax_catalog = {};
```

**Do NOT sync**: `cypress/support/openmage/backend/*` or `cypress/e2e/*` from upstream — those are core page configs and tests, not relevant to modules.

### 8. Verify after sync

```bash
ddev lint                # All quality checks pass
ddev test                # PHPUnit still passes
ddev cypress-run         # Cypress still passes (if applicable)
```

## When to Sync

- **On OpenMage LTS minor/major release** (e.g., 20.x → 21.x)
- **When quality tool CI fails** after upgrading a dependency
- **Quarterly maintenance** — check if upstream bumped tool versions
- **When starting a new module** from this template — ensure template is current first

## Gotchas

- Upstream uses `vendor/bin/ecs` (ECS wraps php-cs-fixer) — never run `php-cs-fixer` binary directly
- `macopedia/phpstan-magento1` must match your OpenMage LTS version — if you upgrade OpenMage, check for a new phpstan-magento1 release
- The `dashbord` typo in `test.js` is intentional (matches OpenMage core URL) — don't "fix" it during sync
- Upstream's `composer.json` has `phpunit/phpunit: ^9.6` but our template uses `^10.0` (installed dynamically by setup-openmage). This is fine — upstream supports older PHP versions
