/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 *
 * Tests for the brand-overlay-aware config lookup used by the
 * gateway_method KO renderer. The whole point of this helper is that
 * the renderer reads its config from `window.checkoutConfig.payment[<code>]`
 * instead of a hardcoded `.two_payment` subtree, so brand-overlay
 * packages (abn-plugin et al) can reuse the same renderer without
 * forking it.
 */

const path = require('path');
const getBrandConfig = require(path.resolve(
    __dirname,
    '../../view/frontend/web/js/model/brand-config.js'
));

describe('Two_Gateway/js/model/brand-config', () => {
    beforeEach(() => {
        // Reset checkoutConfig between tests so leftover state from one
        // case never affects the next.
        delete window.checkoutConfig;
    });

    it('returns an empty object when window.checkoutConfig is absent', () => {
        expect(getBrandConfig('two_payment')).toEqual({});
    });

    it('returns an empty object when checkoutConfig.payment is absent', () => {
        window.checkoutConfig = {};
        expect(getBrandConfig('two_payment')).toEqual({});
    });

    it('returns an empty object when the requested code is missing', () => {
        window.checkoutConfig = { payment: { two_payment: { foo: 1 } } };
        expect(getBrandConfig('abn_payment')).toEqual({});
    });

    it('reads the two_payment subtree by code', () => {
        const twoSubtree = { paymentTermsMessage: 'Two terms', isOrderIntentEnabled: true };
        window.checkoutConfig = { payment: { two_payment: twoSubtree } };
        expect(getBrandConfig('two_payment')).toBe(twoSubtree);
    });

    it('reads the abn_payment subtree by code (brand overlay)', () => {
        const abnSubtree = { paymentTermsMessage: 'ABN terms', isOrderIntentEnabled: false };
        window.checkoutConfig = {
            payment: { two_payment: { foo: 1 }, abn_payment: abnSubtree }
        };
        expect(getBrandConfig('abn_payment')).toBe(abnSubtree);
    });

    it('does not leak data across codes — each subtree is independent', () => {
        window.checkoutConfig = {
            payment: {
                two_payment: { paymentTermsMessage: 'Two' },
                abn_payment: { paymentTermsMessage: 'ABN' }
            }
        };
        expect(getBrandConfig('two_payment').paymentTermsMessage).toBe('Two');
        expect(getBrandConfig('abn_payment').paymentTermsMessage).toBe('ABN');
    });

    describe('getActiveTwoBrandCode', () => {
        it('returns null when checkoutConfig is absent', () => {
            expect(getBrandConfig.getActiveTwoBrandCode()).toBeNull();
        });

        it('returns null when no payment subtree has redirectUrlCookieCode', () => {
            window.checkoutConfig = {
                payment: {
                    checkmo: { title: 'Check / Money order' },
                    free: { title: 'No Payment Information Required' }
                }
            };
            expect(getBrandConfig.getActiveTwoBrandCode()).toBeNull();
        });

        it('returns the vanilla two_payment code when its subtree carries the sentinel', () => {
            window.checkoutConfig = {
                payment: {
                    checkmo: { title: 'Check / Money order' },
                    two_payment: { redirectUrlCookieCode: 'two_redirect_url', brand: 'two' }
                }
            };
            expect(getBrandConfig.getActiveTwoBrandCode()).toBe('two_payment');
        });

        it('returns the brand-overlay code (abn_payment) on an ABN install', () => {
            window.checkoutConfig = {
                payment: {
                    checkmo: { title: 'Check / Money order' },
                    abn_payment: { redirectUrlCookieCode: 'abn_redirect_url', brand: 'abn' }
                }
            };
            expect(getBrandConfig.getActiveTwoBrandCode()).toBe('abn_payment');
        });

        it('ignores subtrees with a falsy redirectUrlCookieCode', () => {
            window.checkoutConfig = {
                payment: {
                    two_payment: { redirectUrlCookieCode: '' },
                    abn_payment: { redirectUrlCookieCode: 'abn_redirect_url' }
                }
            };
            expect(getBrandConfig.getActiveTwoBrandCode()).toBe('abn_payment');
        });
    });

    describe('getActiveTwoBrandConfig', () => {
        it('returns an empty object when no Two-family brand is active', () => {
            window.checkoutConfig = { payment: { checkmo: { title: 'cm' } } };
            expect(getBrandConfig.getActiveTwoBrandConfig()).toEqual({});
        });

        it('returns the active brand subtree by reference', () => {
            const abnSubtree = {
                redirectUrlCookieCode: 'abn_redirect_url',
                checkoutApiUrl: 'https://abn.example/api',
                brand: 'abn'
            };
            window.checkoutConfig = { payment: { abn_payment: abnSubtree } };
            expect(getBrandConfig.getActiveTwoBrandConfig()).toBe(abnSubtree);
        });
    });
});
