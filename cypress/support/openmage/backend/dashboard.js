// Admin dashboard page configuration.
//
// WHY THIS FILE IS MANDATORY
// --------------------------
// The vendored cy.openmage.admin.login() in _utils/admin.js ends with:
//
//     cy.url().should('include', cy.openmage.test.backend.dashbord.config.index.url);
//
// _utils/test.js declares `test.backend.dashbord` as an empty object and leaves
// the `.config` to a page object — which is this file. Without it EVERY backend
// spec dies in its beforeEach with
// "TypeError: Cannot read properties of undefined (reading 'index')".
//
// Shape and contents are kept identical to the upstream page object
// (cypress/support/openmage/backend/dashboard.js in OpenMage LTS) so the two
// stay interchangeable. Note the upstream spelling "dashbord" — it is a typo in
// core that _utils/admin.js depends on. Do not correct it.

const test = cy.openmage.test.backend.dashbord;

/**
 * Configuration for "Dashboard" menu item
 * @type {{_: string, _nav: string, _title: string, url: string, index: {}}}
 */
test.config = {
    _: '#nav-admin-dashboard',
    _nav: '#nav-admin-dashboard',
    _title: 'h3.head-dashboard',
    url: 'dashboard/index',
    index: {},
};

/**
 * Configuration for "Dashboard" page
 * @type {{title: string, url: string}}
 */
test.config.index = {
    title: 'Dashboard',
    url: test.config.url,
};
