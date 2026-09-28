// AJAX add-to-cart: /ajaxcatalog/cart/add and /ajaxcatalog/cart/data.

const ajaxcatalog = cy.openmage.test.frontend.ajaxcatalog;
const test = ajaxcatalog.config;

describe('AjaxCatalog add to cart', () => {
    beforeEach('Start from an empty cart', () => {
        // the cart lives in the session
        cy.clearCookies();
    });

    it('SC-01 reports an empty cart', () => {
        cy.request(test.cart.data).then((response) => {
            expect(response.headers['content-type']).to.include('application/json');
            expect(response.body).to.include.all.keys('content', 'count');
            // an empty quote reports null rather than 0
            expect(response.body.count ?? 0).to.eq(0);
        });
    });

    it('SC-02 rejects an invalid form key', () => {
        // without a session cookie core redirects to /enable-cookies before the module runs
        ajaxcatalog.formKey();

        cy.request({ method: 'POST', url: test.cart.add(test.cart.productId), form: true, body: { form_key: 'invalid' } })
            .then((response) => {
                expect(response.body.success).to.eq(0);
                expect(response.body.error).to.have.length.of.at.least(1);
            });
    });

    it('SC-03 adds a product and returns the minicart', () => {
        ajaxcatalog.formKey().then((formKey) => {
            cy.request({
                method: 'POST',
                url: test.cart.add(test.cart.productId),
                form: true,
                body: { form_key: formKey, qty: 1 },
            }).then((response) => {
                expect(response.headers['content-type']).to.include('application/json');
                expect(response.body).to.include.all.keys('message', 'content');
                expect(response.body.message).to.include('was added to your shopping cart');
            });
        });

        cy.request(test.cart.data).its('body.count').should('eq', 1);
    });

    it('SC-04 does not serve the inherited core cart actions', () => {
        cy.request({ url: test.cart.blocked, failOnStatusCode: false }).its('status').should('eq', 404);
    });
});
