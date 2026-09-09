/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 *
 * The surcharge grid asks the fees endpoint for nothing (ABN-542). Fee figures
 * are the term chips' surface (payment-terms-config.js); a second fetch beside
 * the editable inputs would render into cells the grid does not have.
 */

'use strict';

const jq = require('jquery');
const { loadAmdModule, defaultMocks } = require('./amd-harness');

const MODULE = 'view/adminhtml/web/js/surcharge-grid.js';
const SECTION = 'two_payment';
const PREFIX = SECTION + '_payment_terms_';

function render() {
    document.body.innerHTML =
        '<input name="form_key" value="k"/>'
        + '<input id="' + PREFIX + 'payment_terms_duration_days" value="30,60"/>'
        + '<select id="' + PREFIX + 'surcharge_type">'
        + '  <option value="fixed" selected="selected">fixed</option>'
        + '  <option value="percentage">percentage</option>'
        + '</select>'
        + '<input type="checkbox" id="' + PREFIX + 'surcharge_differential"/>'
        + '<select id="' + PREFIX + 'default_payment_term">'
        + '  <option value="30" selected="selected">30</option><option value="60">60</option>'
        + '</select>'
        + '<input type="checkbox" id="' + PREFIX + 'surcharge_type_inherit"/>'
        + '<div id="' + PREFIX + 'payment_terms_checkboxes" class="two-term-checkboxes">'
        + '  <input type="checkbox" class="two-term-checkboxes__input" value="30" checked="checked"/>'
        + '  <input type="checkbox" class="two-term-checkboxes__input" value="60"/>'
        + '</div>'
        // data-fees-url is deliberately present though the template no longer
        // emits it: without it any restored fetch would return early and the
        // zero-request assertion below would pass vacuously.
        + '<div id="surcharge-grid-container" data-max-fixed="100" data-max-percentage="10"'
        + ' data-fees-url="/two/config/fees">'
        + '  <p class="surcharge-grid__no-terms"><span></span></p>'
        + '  <div class="surcharge-grid__inherit-control">'
        + '    <input type="checkbox" class="surcharge-grid__inherit-toggle"/>'
        + '    <input type="hidden" class="surcharge-grid__inherit-sentinel" value="0"/>'
        + '  </div>'
        + '  <table class="admin__table-secondary surcharge-grid">'
        + '    <tbody>'
        + rows([30, 60])
        + '    </tbody>'
        + '  </table>'
        + '  <p class="surcharge-grid__currency-note note"><span></span></p>'
        + '</div>';
}

function rows(terms) {
    return terms.map(function (days) {
        return '<tr class="surcharge-grid__row" data-term="' + days + '">'
            + ['fixed', 'percentage', 'limit'].map(function (col) {
                return '<td class="surcharge-grid__' + col + '">'
                    + '<input type="text" class="input-text surcharge-grid__input"'
                    + ' data-column="' + col + '" data-term="' + days + '" value=""/>'
                    + '</td>';
            }).join('')
            + '</tr>';
    }).join('');
}

/**
 * Boots the real module against the fixture with jQuery's ajax counted rather
 * than issued. Returns the counter so a caller can assert after interacting.
 */
function boot() {
    render();
    const calls = [];
    jq.ajax = function (options) {
        calls.push(options);
        const chain = {
            done: function () { return chain; },
            fail: function () { return chain; },
            always: function () { return chain; }
        };
        return chain;
    };

    const mocks = defaultMocks();
    // Real jQuery for the DOM and event wiring; the harness double's
    // `mage/validation` decorations, which real jQuery has no equivalent of.
    jq.validator = mocks.jquery.validator;
    jq.mage = mocks.jquery.mage;
    mocks.jquery = jq;
    loadAmdModule(MODULE, mocks)({}, document.getElementById('surcharge-grid-container'));

    return calls;
}

/** Lets any promise/timer-deferred request actually be issued before asserting. */
function flushMacrotasks() {
    return new Promise(function (resolve) { setTimeout(resolve, 0); });
}

describe('surcharge grid fee requests', () => {
    it.each([
        { interact: function () {}, description: 'initialising the grid' },
        {
            interact: function () { jq('.two-term-checkboxes__input[value="60"]').prop('checked', true).trigger('change'); },
            description: 'adding a term'
        },
        {
            interact: function () { jq('.two-term-checkboxes__input[value="30"]').prop('checked', false).trigger('change'); },
            description: 'removing a term'
        },
        {
            interact: function () { jq('#' + PREFIX + 'payment_terms_duration_days').val('45').trigger('keyup'); },
            description: 'typing a custom day count'
        },
        {
            interact: function () { jq('#' + PREFIX + 'surcharge_type').val('percentage').trigger('change'); },
            description: 'switching the surcharge type'
        },
        {
            interact: function () { jq('#' + PREFIX + 'default_payment_term').val('60').trigger('change'); },
            description: 'changing the default term'
        },
        {
            interact: function () { jq('.surcharge-grid__inherit-toggle').prop('checked', true).trigger('change'); },
            description: 'toggling grid inherit'
        },
        {
            interact: function () { jq('#' + PREFIX + 'surcharge_differential').prop('checked', true).trigger('change'); },
            description: 'enabling differential surcharges'
        }
    ])('issues none on $description', async ({ interact, description }) => {
        const calls = boot();

        interact();
        await flushMacrotasks();

        const urls = calls.map(function (options) { return options.url; });
        expect({ after: description, requests: urls }).toEqual({ after: description, requests: [] });
    });
});
