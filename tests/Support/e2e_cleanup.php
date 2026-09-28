<?php

/**
 * DESTRUCTIVE. Delete everything the seeders created.
 *
 * A one-line entry point so Cypress has a stable in-container path to call,
 * rather than reaching into tests/fixtures/seed from a spec. All of the work —
 * and all of the prefix-matching safety rules that make it safe — lives in the
 * clean seeder, which also owns the bootstrap and the exit status.
 *
 * This is never part of a default spec run. cypress/support/e2e.js leaves
 * after() empty on purpose: a developer running the suite against their working
 * instance must not lose data because a hook fired.
 *
 * Usage:
 *   php tests/Support/e2e_cleanup.php
 *
 * Equivalent to:
 *   ddev seed clean
 */

require_once __DIR__ . '/../fixtures/seed/clean.php';
