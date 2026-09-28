const { defineConfig } = require('cypress');

module.exports = defineConfig({
  e2e: {
    baseUrl: 'https://om-ajaxcatalog.ddev.site',
    supportFile: 'cypress/support/e2e.js',
    specPattern: 'cypress/e2e/**/*.cy.{js,ts}',
    // Default admin credentials (set by setup-openmage)
    env: {
      adminUser: 'admin',
      adminPassword: 'veryl0ngpassw0rd',
      adminPath: '/admin',
    },
    viewportWidth: 1280,
    viewportHeight: 720,
    defaultCommandTimeout: 10000,
    // Disable Chrome web security for DDEV's self-signed certs
    chromeWebSecurity: false,
  },
});
