// Contract for the InternetCode_AjaxCatalog JSON endpoints.
//
// Runs against the Magento 1.9 sample data (`ddev setup-openmage --with-sample-data`),
// the same catalog the PHPUnit integration suite uses.

cy.openmage.test.frontend.ajaxcatalog = {};

cy.openmage.test.frontend.ajaxcatalog.config = {
    xhrHeaders: { 'X-Requested-With': 'XMLHttpRequest' },
    category: {
        url: '/women.html',
        // sample data: 12 configurables in "Women"
        minItems: 1,
    },
    search: {
        url: '/catalogsearch/result/?q=shirt',
    },
    listingKeys: ['collection', 'toolbar', 'layer', 'state', 'translate'],
    toolbarKeys: ['available_orders', 'available_limits', 'total_items', 'total_pages', 'pages', 'currentPageNum', 'out_of_stock_count'],
    itemKeys: ['entity_id', 'name', 'product_url', 'price_html', 'add_to_cart_url', 'is_salable', 'is_sale', 'is_new'],
    cart: {
        // sample data simple product, in stock (qty 25)
        productId: 234,
        add: (productId) => `/ajaxcatalog/cart/add/product/${productId}/`,
        data: '/ajaxcatalog/cart/data',
        // only add and data are served by the module controller
        blocked: '/ajaxcatalog/cart/index',
    },
    // an uncached page; the footer newsletter block is cached with a stale form key
    formKeySource: '/customer/account/login/',
};

/**
 * Yield a form key bound to the current session cookie.
 *
 * @returns {Cypress.Chainable<string>}
 */
cy.openmage.test.frontend.ajaxcatalog.formKey = () => {
    const test = cy.openmage.test.frontend.ajaxcatalog.config;

    return cy.request(test.formKeySource).then((response) => {
        const match = String(response.body).match(/name="form_key" type="hidden" value="([^"]+)"/);

        expect(match, 'form_key on ' + test.formKeySource).to.not.equal(null);

        return match[1];
    });
};
