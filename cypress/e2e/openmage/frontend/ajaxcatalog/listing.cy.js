// Category and search listings answer with JSON for XHR on the same URL as the HTML page.

const test = cy.openmage.test.frontend.ajaxcatalog.config;

describe('AjaxCatalog listing JSON', () => {
    it('SC-01 serves HTML to a normal request and varies on X-Requested-With', () => {
        cy.request(test.category.url).then((response) => {
            expect(response.status).to.eq(200);
            expect(response.headers['content-type']).to.include('text/html');
            expect(String(response.headers.vary)).to.include('X-Requested-With');
        });
    });

    it('SC-02 serves the category listing as uncacheable JSON to XHR', () => {
        cy.request({ url: test.category.url, headers: test.xhrHeaders }).then((response) => {
            expect(response.status).to.eq(200);
            expect(response.headers['content-type']).to.include('application/json');
            expect(response.headers['cache-control']).to.eq('private, no-store');
            expect(String(response.headers.vary)).to.include('X-Requested-With');

            expect(response.body).to.include.all.keys(...test.listingKeys);
            expect(response.body.toolbar).to.include.all.keys(...test.toolbarKeys);
            expect(response.body.collection.items).to.have.length.of.at.least(test.category.minItems);
            expect(response.body.collection.items[0]).to.include.all.keys(...test.itemKeys);
        });
    });

    it('SC-03 serves search results as JSON to XHR', () => {
        cy.request({ url: test.search.url, headers: test.xhrHeaders }).then((response) => {
            expect(response.status).to.eq(200);
            expect(response.headers['content-type']).to.include('application/json');
            expect(response.body).to.include.all.keys(...test.listingKeys);
            expect(response.body.toolbar.total_items).to.be.greaterThan(0);
        });
    });

    it('SC-04 accepts ?isAjax=1 as an XHR marker', () => {
        cy.request(`${test.category.url}?isAjax=1`).then((response) => {
            expect(response.headers['content-type']).to.include('application/json');
            expect(response.body).to.include.all.keys(...test.listingKeys);
        });
    });
});
