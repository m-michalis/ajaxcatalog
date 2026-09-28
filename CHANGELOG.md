# Changelog

All notable changes to InternetCode_AjaxCatalog will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/),
and this project adheres to [Semantic Versioning](https://semver.org/).

## [0.5.0] - 2026-09-28

### Changed

- **Requires PHP 8.2+** (was 7.2+).
- Migrated to the om-dev-template layout: module files moved under `src/`, `modman` updated accordingly.
- Code brought to PHPStan level 8 (strict rules), PHPCS (ECG) and ECS (PER-CS) with no baseline.
- `CartController` loads its core parent through the include path instead of `Mage::getModuleDir()`.
- `getProductListBlock()` on the catalog AJAX models returns `null` instead of `false` when the block is missing (callers still accept `false` from subclasses).
- AJAX add-to-cart without a `minicart_content` block now answers `success: 1` with empty `content` instead of failing.
- Dev environment: default currency EUR (was USD); sample data is opt-in via `ddev setup-openmage --with-sample-data`.

### Added

- CI (DDEV: setup with sample data, lint, PHPUnit, Cypress) and automated tag/release from `composer.json` version.
- Quality tooling: ECS, PHPStan, PHPCS, Rector.
- Template test base (`Tests\Base\AbstractTestCase`), `ddev seed` fixtures, Unit/Integration suites.
- Cypress specs for the listing JSON and AJAX cart endpoints.

## [0.4.0]

- Audit fixes, webpack manifest support, test environment.
