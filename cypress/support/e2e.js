// OpenMage Cypress E2E Support File
// Imports core utilities, custom commands, and module-specific helpers.
//
// IMPORT ORDER IS LOAD-BEARING:
//   1. ./openmage             creates the cy.openmage namespace object
//   2. ./openmage/_utils/*    populate it — _utils/test.js declares every
//                             namespace the page objects hang their .config off
//   3. ./module-commands      local commands, and the cy.openmage.seed contract
//   4. page objects           set .config on the namespaces declared above
//
// ./commands, ./openmage and everything under ./openmage/_utils/ are copied
// VERBATIM from OpenMage LTS and are re-synced periodically. Never edit them —
// changes are silently destroyed on the next sync. Module-local additions go in
// ./module-commands or in a page object.

import './commands'
import './openmage'

// Core OpenMage utilities (vendored — do not edit)
import './openmage/_utils/admin'
import './openmage/_utils/check'
import './openmage/_utils/test'
import './openmage/_utils/tools'
import './openmage/_utils/utils'
import './openmage/_utils/validation'

// Local commands: cy.ddevExec, cy.ddevRun, cy.seedConfig, cy.setStoreConfig,
// cy.seedCustomer, cy.seedClean — plus the cy.openmage.seed data contract.
import './module-commands'

// Core page object the vendored utilities depend on (_utils/admin.js reads its
// url to confirm a login). Kept so admin specs can be added without surprises.
import './openmage/backend/dashboard'

// InternetCode_AjaxCatalog JSON endpoint contract (sample data)
import './openmage/frontend/ajaxcatalog/api'

// ── Seed lifecycle ──────────────────────────────────────────────────────────
//
// Cypress re-registers root hooks for every spec FILE, so this runs once per
// spec, not once per run. `ddev seed config` is an upsert over core_config_data
// and therefore cheap and idempotent — it exists to guarantee the storefront
// prerequisites the specs assume (flat catalog off, stock managed, customer
// confirmation off, flatrate + checkmo active).
//
// Skip it entirely with:  npx cypress run --env seed=false
before(() => {
    cy.seedConfig();
});

// Intentionally empty, and it stays that way.
//
// Cleanup here would be DESTRUCTIVE: `ddev seed clean` deletes every QA-
// product, qa- customer, "QA " category and qa_ store view it can find. A
// developer running these specs against their working instance must never lose
// data because a hook fired. Clean up explicitly instead:
//
//   ddev seed clean          (or cy.seedClean() from a throwaway spec)
//   ddev seed                (to put it all back)
after(() => {
    cy.log('Seed lifecycle: no automatic cleanup — run "ddev seed clean" explicitly.');
});
