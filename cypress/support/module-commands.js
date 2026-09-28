// Local Cypress commands for InternetCode_AjaxCatalog.
//
// WHY THIS FILE EXISTS
// --------------------
// cypress/support/commands.js, cypress/support/openmage.js and everything under
// cypress/support/openmage/_utils/ are copied VERBATIM from OpenMage LTS and are
// re-synced periodically. Anything added to those files is silently destroyed on
// the next sync, so every module-local command lives here instead — this file is
// ours and the sync never touches it.
//
// EXECUTION CONTEXT (read before adding a command)
// ------------------------------------------------
// cy.exec() runs on the HOST: the machine that owns the `ddev` binary, the
// Cypress binary and this repository. Its working directory is the Cypress
// project root, i.e. the repository root. Two consequences:
//
//   * `ddev seed …` is a HOST command. DDEV relays it into the web container by
//     itself, so it is handed to cy.exec() unwrapped.
//   * a path that is already resolved INSIDE the container
//     (tests/Support/e2e_*.php, relative to /var/www/html) needs `ddev exec` in
//     front of it, which is exactly what cy.ddevExec() prepends.
//
// Never write cy.ddevExec('ddev seed config') — that produces
// `ddev exec ddev seed config` and fails.
//
// SEED LIFECYCLE FLAG
// -------------------
// Everything that mutates the instance is gated behind `Cypress.env('seed')`.
// Pass `--env seed=false` to run the whole suite against an instance that is
// already seeded, which is what CI does after a single up-front `ddev seed`.

/**
 * Contract with tests/fixtures/seed/*.php.
 *
 * These values are duplicated from lib.php on purpose: Cypress cannot read PHP
 * constants, and a spec that asserts on seeded data has to know what the
 * seeders produce. Keep them in step with tests/fixtures/seed/lib.php.
 */
cy.openmage.seed = {
    skuPrefix: 'QA-',
    labelPrefix: 'QA ',
    emailPrefix: 'qa-',
    emailDomain: '@example.test',
    customerPassword: 'QaSeed123!',
    category: {
        root: 'QA Store',
        products: 'QA Electronics',
        secondary: 'QA Apparel',
    },
    /**
     * SKU of the Nth seeded product, e.g. 1 becomes "QA-0001".
     *
     * @param {number} index One-based sequence number
     * @returns {string}
     */
    sku: (index = 1) => cy.openmage.seed.skuPrefix + String(index).padStart(4, '0'),
    /**
     * E-mail address of the Nth seeded customer.
     *
     * @param {number} index One-based sequence number
     * @returns {string}
     */
    email: (index = 1) =>
        cy.openmage.seed.emailPrefix + 'customer-' + String(index).padStart(3, '0') + cy.openmage.seed.emailDomain,
};

/**
 * Is the seed lifecycle allowed to mutate this instance?
 *
 * @returns {boolean}
 */
cy.openmage.seed.enabled = () => Cypress.env('seed') !== false && Cypress.env('seed') !== 'false';

/**
 * Run a command INSIDE the DDEV web container.
 *
 * The argument is the in-container command line; `ddev exec` is prepended here.
 * DDEV runs it with the working directory set to /var/www/html, so project
 * relative paths such as `tests/Support/e2e_config.php` resolve as written.
 *
 * @param {string} command In-container command line
 * @param {object} options Overrides passed straight to cy.exec()
 */
Cypress.Commands.add('ddevExec', (command, options = {}) =>
    cy.exec(`ddev exec ${command}`, { failOnNonZeroExit: true, timeout: 120000, ...options }),
);

/**
 * Run a DDEV custom command on the HOST (`ddev seed`, `ddev lint`, ...).
 *
 * @param {string} command Host command line, already starting with `ddev`
 * @param {object} options Overrides passed straight to cy.exec()
 */
Cypress.Commands.add('ddevRun', (command, options = {}) =>
    cy.exec(command, { failOnNonZeroExit: true, timeout: 300000, ...options }),
);

/**
 * Apply the seed configuration (`ddev seed config`).
 *
 * Idempotent: saveConfig() is an upsert, so re-running changes nothing. No-op
 * when the seed lifecycle is switched off.
 */
Cypress.Commands.add('seedConfig', () => {
    if (!cy.openmage.seed.enabled()) {
        cy.log('seedConfig: skipped (Cypress env "seed" is false)');
        return;
    }

    cy.ddevRun('ddev seed config');
});

/**
 * Write individual config paths through tests/Support/e2e_config.php.
 *
 * Used to pin a known starting state before a spec asserts on a saved value.
 * No-op when the seed lifecycle is switched off.
 *
 * @param {Object<string, string>} values path => value, e.g. { 'a/b/c': '1' }
 */
Cypress.Commands.add('setStoreConfig', (values = {}) => {
    const pairs = Object.keys(values).map((path) => `${path}=${values[path]}`);

    if (pairs.length === 0) {
        throw new Error('cy.setStoreConfig() needs at least one path => value pair.');
    }

    if (!cy.openmage.seed.enabled()) {
        cy.log(`setStoreConfig: skipped (Cypress env "seed" is false): ${pairs.join(' ')}`);
        return;
    }

    cy.ddevExec(`php tests/Support/e2e_config.php ${pairs.join(' ')}`);
});

/**
 * Guarantee a login-able storefront customer and yield its credentials.
 *
 * With the seed lifecycle on, tests/Support/e2e_customer.php prints
 * {"email":…,"password":…} on stdout and the JSON is parsed here. With it off,
 * the deterministic seeded credentials are yielded without touching the
 * instance — the seeders always produce the same account for a given index.
 *
 * @param {number} index One-based customer index, matching `ddev seed customers`
 * @returns {Cypress.Chainable<{email: string, password: string}>}
 */
Cypress.Commands.add('seedCustomer', (index = 1) => {
    const fallback = {
        email: cy.openmage.seed.email(index),
        password: cy.openmage.seed.customerPassword,
    };

    if (!cy.openmage.seed.enabled()) {
        cy.log(`seedCustomer: skipped (Cypress env "seed" is false), using ${fallback.email}`);
        return cy.wrap(fallback, { log: false });
    }

    return cy.ddevExec(`php tests/Support/e2e_customer.php ${index}`).then((result) => {
        const stdout = String(result.stdout || '').trim();
        const line = stdout.split('\n').pop();

        try {
            return cy.wrap(JSON.parse(line), { log: false });
        } catch {
            throw new Error(`e2e_customer.php did not print JSON credentials. stdout was:\n${stdout}`);
        }
    });
});

/**
 * DESTRUCTIVE. Delete everything the seeders created (`ddev seed clean`).
 *
 * Deliberately NOT wired into any before()/after() hook: a developer running
 * these specs against their working instance must never lose data by accident.
 * Call it explicitly, and only from a spec you are happy to run on a scratch
 * instance.
 */
Cypress.Commands.add('seedClean', () => {
    cy.ddevRun('ddev seed clean');
});
