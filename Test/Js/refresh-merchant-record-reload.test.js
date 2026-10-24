/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 *
 * TWO-26153: a successful "Refresh merchant profile" reloads the page onto
 * its panel, so every section renders afresh; the stamp the reload carries is
 * dropped on landing, so a later reload or save does not confirm it again.
 */

'use strict';

const jquery = require('jquery');
const { loadAmdModule } = require('./amd-harness');

function load(response) {
    document.body.innerHTML = `
        <input name="form_key" value="fk"/>
        <div class="two-refresh-merchant-record" id="two-refresh-merchant-record"
             data-refresh-url="/admin/two/config/refreshMerchantRecord" data-scope="default" data-scope-id="0">
            <button type="button" class="two-refresh-merchant-record__button"></button>
            <div class="two-refresh-merchant-record__status"></div>
        </div>`;
    const $ = jquery;
    $.ajax = function () {
        const deferred = $.Deferred();
        if (response === null) {
            deferred.reject();
        } else {
            deferred.resolve(response);
        }
        return deferred.promise();
    };
    loadAmdModule('view/adminhtml/web/js/refresh-merchant-record.js', {
        jquery: $,
        'mage/translate': function (s) { return s; },
        'domReady!': {}
    }, { window: window });
    return $;
}

describe('refresh merchant profile reload', () => {
    afterEach(() => {
        delete window.twoAdminNavigate;
        window.sessionStorage.clear();
    });

    it.each([
        [{ success: true, message: 'Merchant profile refreshed.', merchant: 'Acme · m1' }, 1, 'a success reloads onto the panel'],
        [{ success: false, message: 'No.' }, 0, 'a refused refresh stays put with its reason'],
        [null, 0, 'a transport failure stays put']
    ])('%#: %s', (response, reloads, description) => {
        window.history.replaceState(null, '', '/admin/admin/system_config/edit/section/two_version/#top');
        window.twoAdminNavigate = jest.fn();
        const $ = load(response);
        $('.two-refresh-merchant-record__button').trigger('click');

        expect([window.twoAdminNavigate.mock.calls.length, description]).toEqual([reloads, description]);
        if (reloads) {
            const url = new URL(window.twoAdminNavigate.mock.calls[0][0]);
            expect([url.pathname, url.hash, description]).toEqual([
                '/admin/admin/system_config/edit/section/two_version/', '#two-refresh-merchant-record', description
            ]);
            expect(url.searchParams.get('two_refreshed')).toMatch(/^\d+$/);
            expect(window.sessionStorage.getItem('twoRefreshedStatus')).toBe('Merchant profile refreshed. Acme · m1');
        }
    });

    it.each([
        ['/admin/x/?two_refreshed=1700000000000#two-refresh-merchant-record', '/admin/x/#two-refresh-merchant-record', 'Merchant profile refreshed. Acme', 'the stamp is dropped and the stored confirmation shown'],
        ['/admin/x/?a=1', '/admin/x/?a=1', '', 'a URL without it is left alone, and nothing is confirmed']
    ])('%#: landing on %s', (landing, expected, status, description) => {
        window.sessionStorage.setItem('twoRefreshedStatus', 'Merchant profile refreshed. Acme');
        window.history.replaceState(null, '', landing);
        const $ = load(null);

        expect([window.location.pathname + window.location.search + window.location.hash, description]).toEqual([expected, description]);
        expect([$('.two-refresh-merchant-record__status').text(), description]).toEqual([status, description]);
        expect(window.sessionStorage.getItem('twoRefreshedStatus')).toBeNull();
    });
});
