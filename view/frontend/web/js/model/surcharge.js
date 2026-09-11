/**
 * Shared surcharge state for checkout.
 *
 * Chip values are deferred: rendered as an empty observable on first paint
 * (template shows a loader), then populated asynchronously after Magento's
 * /totals-information settles. This avoids the basis divergence that
 * server-side computation suffered (subtotal-only at HTML render time vs
 * subtotal+shipping after collectTotals re-runs).
 *
 * The first fetch is gated on the quote.getTotals() subscriber rather than
 * running at module load — server-rendered totals are pre-shipping and
 * fetching against them would just move the basis bug client-side. On
 * every subsequent totals change (coupon, shipping address, etc.) we
 * refetch; the calculator has a request-scoped response cache so duplicate
 * calls are free, and correctness beats the few KB of saved bandwidth.
 */
define([
    'ko',
    'jquery',
    'Magento_Checkout/js/model/quote',
    'Magento_Ui/js/model/messageList',
    'mage/translate',
    'mage/url',
    'Two_Gateway/js/model/brand-config'
], function (ko, $, quote, messageList, $t, url, brandConfig) {
    'use strict';

    var SELECT_TERM_TIMEOUT_MS = 30000;

    // Resolve the active Two-family brand subtree from checkoutConfig
    // rather than hardcoding `.two_payment`. The brand-overlay 2.0
    // architecture lets each overlay (acme_payment, …) ship its own
    // ConfigProvider subtree under the brand's payment-method code,
    // so this module needs to discover which is active rather than
    // assuming vanilla.
    var config = brandConfig.getActiveTwoBrandConfig();

    var selectedTerm = ko.observable(config.selectedPaymentTerm || config.defaultPaymentTerm || 0);
    // Empty by default — template renders loader until the latest loadFees() resolves.
    var termSurcharges = ko.observable({});
    var termSurchargesGross = ko.observable({});
    // 'excl' | 'incl' | 'both', mirroring tax/cart_display/price. Server-
    // supplied on every surcharge response; 'excl' until the first answers.
    var taxDisplay = ko.observable('excl');
    var isUpdating = ko.observable(false);

    // The term /select-term answered with re-collected totals for; anything else
    // on the chips means the summary and the order can disagree (ABN-550).
    // Observable so the placement gate re-evaluates when it moves.
    var confirmedTerm = ko.observable(selectedTerm());

    // A chip clicked while a /select-term is in flight, sent once that settles.
    // Overlapping calls are what the server serialises on the session lock, and
    // it can take them in the opposite order to the one they were sent in.
    var pendingTerm = null;

    // An external totals emission during a chip click is dropped by the
    // subscriber, so the fees are re-evaluated once the click settles.
    var totalsMissedWhileUpdating = false;

    // True only while this module is writing the /select-term totals back, whose
    // re-emission is not a change anything needs to react to.
    var applyingOwnTotals = false;

    // Sequence guard: out-of-order /select-term responses would otherwise
    // confirm a term nobody selected.
    var selectSeq = 0;

    // Fetch sequence guard. Magento fires quote.getTotals() once on bootstrap
    // (often with subtotal-only basis) and again after /totals-information
    // settles (with shipping). We fire one fetch per emission and let only
    // the latest response populate the observable — earlier responses, which
    // were computed against a partial basis, are discarded. Net effect: the
    // loader stays visible until the API answers based on the settled quote.
    var fetchSeq = 0;

    // Snapshot of the totals payload from the last fetch we issued. Magento
    // re-emits quote.getTotals() opportunistically (e.g. after a payment
    // method re-render or a step transition) without anything in the order
    // summary actually changing. Hash the user-visible elements — grand
    // total + every segment's code/value/title — and skip the fetch when
    // the snapshot matches the previous one.
    var lastTotalsSnapshot = null;

    /**
     * Both maps are replaced together so a chip can never pair one term's net
     * with another response's gross.
     *
     * @param {Array} items per-term entries from either surcharge endpoint
     */
    function applyTermSurcharges(items) {
        var net = {};
        var gross = {};
        items.forEach(function (item) {
            net[item.days] = item.net;
            gross[item.days] = typeof item.gross === 'number' ? item.gross : item.net;
        });
        termSurcharges(net);
        termSurchargesGross(gross);
    }

    /**
     * Build a deterministic snapshot of the totals payload covering every
     * element that affects our /surcharges request. Used to dedup no-op
     * refetches.
     *
     * The two_surcharge segment is intentionally excluded and the grand
     * total is folded down to the basis (grand_total minus the surcharge
     * itself) — a change that affects only our surcharge value (e.g. the
     * user clicked a chip and /select-term re-collected totals) does not
     * change the basis we'd send to the API, so it does not change the
     * fees the API would return. Skipping that refetch avoids a redundant
     * round-trip and a loader flicker right after every chip click.
     */
    function snapshotTotals(totals) {
        if (!totals) {
            return null;
        }
        var surchargeValue = 0;
        var otherSegments = [];
        (totals.total_segments || []).forEach(function (s) {
            if (s.code === 'two_surcharge') {
                surchargeValue = parseFloat(s.value) || 0;
            } else {
                otherSegments.push([s.code, s.value, s.title]);
            }
        });
        var basis = (parseFloat(totals.grand_total) || 0) - surchargeValue;
        return JSON.stringify({
            basis: basis.toFixed(4),
            segments: otherSegments
        });
    }

    /**
     * Fetch per-term surcharges for the live quote state. Clears the
     * observable at start so the loader is shown during the request:
     * a previously-shown value may be stale (e.g. just before this fire,
     * a coupon was applied) and showing it through the round-trip would
     * mislead. Out-of-order responses (race against /totals-information)
     * and failures both leave the observable empty, so the loader keeps
     * spinning rather than reverting to a stale or wrong value.
     */
    function loadFees() {
        var cartId = quote.getQuoteId();
        var totals = quote.getTotals()();
        if (!cartId || !totals) {
            return;
        }
        // Skip when the order summary hasn't actually changed since the last
        // call. Magento re-emits the totals observable in some flows even
        // when no segment value has changed; refetching in those cases is
        // wasted work and causes a visible loader flicker.
        var snapshot = snapshotTotals(totals);
        if (snapshot !== null && snapshot === lastTotalsSnapshot) {
            return;
        }
        lastTotalsSnapshot = snapshot;

        // The basis is computed server-side from the persisted quote —
        // we do not pass it as a query parameter. Trusting a caller-
        // supplied basis on an anonymous route allowed unauthenticated
        // probing of merchant surcharge configuration. The trade-off is
        // a one-step lag on /totals-information transitions (phantom
        // calc that does not persist to the server-side quote); during
        // that brief window the chip may show pre-shipping fees until
        // the next persisted collectTotals runs.
        var mySeq = ++fetchSeq;
        termSurcharges({});
        var restUrl = url.build('rest/V1/two/surcharges/' + encodeURIComponent(cartId));
        $.ajax({
            url: restUrl,
            type: 'GET',
            contentType: 'application/json'
        }).done(function (response) {
            if (mySeq !== fetchSeq) {
                // A newer fetch has been issued — discard this stale response.
                return;
            }
            var raw = Array.isArray(response) ? response[0] : response;
            var data;
            try {
                data = typeof raw === 'string' ? JSON.parse(raw) : raw;
            } catch (e) {
                console.warn('Two_Gateway: surcharges response not parseable', e);
                return;
            }
            if (data && data.tax_display) {
                taxDisplay(data.tax_display);
            }
            if (data && Array.isArray(data.term_surcharges) && data.term_surcharges.length) {
                applyTermSurcharges(data.term_surcharges);
            }
        }).fail(function (xhr, status, err) {
            console.warn('Two_Gateway: surcharges fetch failed', status, err);
        });
    }

    // Refresh on every totals change. Skip while a /select-term call is in
    // flight — that endpoint returns the new term_surcharges itself.
    //
    // KO subscribables do not replay. If this module mounts after Magento's
    // /totals-information has already fired (slow stack, deferred renderer,
    // returning customer with persisted address), the subscriber misses the
    // emit and the loader hangs. So we also fire once on init if totals are
    // already present. Trade-off: on a fast stack where /totals-information
    // has NOT yet fired, the init call uses the server-rendered
    // window.checkoutConfig.totalsData basis (pre-shipping), then the
    // subscriber catches the post-shipping emit and refetches via the
    // snapshot dedup. Two round-trips and a brief chip flicker beats the
    // loader hanging forever. Values are server-authoritative either way,
    // so no stale-display drift.
    quote.getTotals().subscribe(function (totals) {
        if (!totals || applyingOwnTotals) {
            return;
        }
        if (isUpdating()) {
            totalsMissedWhileUpdating = true;
            return;
        }
        loadFees();
    });
    if (quote.getTotals()() && !isUpdating()) {
        loadFees();
    }

    /**
     * Hand the chips back to the confirmed term: re-clicking the chip that
     * already looks selected does nothing.
     */
    function revertSelection() {
        selectedTerm(confirmedTerm());
        messageList.addErrorMessage({
            message: $t('Could not update payment term.') + ' ' + $t('Please try again.')
        });
    }

    var surchargeModel = {
        selectedTerm: selectedTerm,
        isUpdating: isUpdating,
        termSurcharges: termSurcharges,
        termSurchargesGross: termSurchargesGross,
        taxDisplay: taxDisplay,
        currencySymbol: config.currencySymbol || '',

        /**
         * Get the surcharge for the current term (for chip labels).
         */
        getAmount: function () {
            var surcharges = this.displayedTermSurcharges();
            return parseFloat(surcharges[selectedTerm()] || 0);
        },

        /**
         * The per-term map the chips must render: gross only when the store
         * shows gross-only prices at checkout ('incl'); net for 'excl' and
         * for 'both' — a chip is one compact value and has no room for the
         * excl/incl pair, which belongs to the order-summary rows instead.
         */
        displayedTermSurcharges: function () {
            return taxDisplay() === 'incl' ? termSurchargesGross() : termSurcharges();
        },

        /**
         * Set the selected term and recalculate totals server-side.
         */
        selectTerm: function (days) {
            if (days === selectedTerm()) {
                return;
            }
            selectedTerm(days);
            if (isUpdating()) {
                pendingTerm = days;
                return;
            }
            this.recalculateTotals(days);
        },

        /**
         * Whether the chips' selection is the term the server confirmed it
         * priced the quote on. Placement is refused while it is not (ABN-550).
         */
        isTermReconciled: function () {
            return !isUpdating() && confirmedTerm() === selectedTerm();
        },

        /**
         * Call /select-term to update totals with the new surcharge.
         */
        recalculateTotals: function (days) {
            var restUrl = url.build('rest/V1/two/select-term');
            var mySeq = ++selectSeq;

            isUpdating(true);
            // Do NOT clear termSurcharges here. A chip click only changes
            // which term is selected; the per-chip fees themselves are
            // invariant (the basis we'd send to /surcharges is the same
            // before and after — grand_total moves by exactly the surcharge
            // delta, which is excluded from the basis). Clearing would
            // flash the loader for no reason. /select-term still returns
            // term_surcharges and we repopulate from it below as a
            // belt-and-braces consistency check.

            $.ajax({
                url: restUrl,
                type: 'POST',
                contentType: 'application/json',
                // Without it a hung request holds isUpdating() true for the rest
                // of the session, and with it the Place Order button disabled.
                timeout: SELECT_TERM_TIMEOUT_MS,
                data: JSON.stringify({
                    cartId: quote.getQuoteId(),
                    termDays: days
                })
            }).done(function (response) {
                if (mySeq !== selectSeq) {
                    return;
                }
                var data = Array.isArray(response) ? response[0] : response;
                if (!data || !Array.isArray(data.total_segments) || data.total_segments.length === 0) {
                    // Nothing confirms the term without the totals it was
                    // collected on, and an empty set would blank the summary.
                    revertSelection();
                    return;
                }
                var currentTotals = quote.getTotals()();
                if (currentTotals) {
                    currentTotals.grand_total = data.grand_total;
                    currentTotals.base_grand_total = data.base_grand_total;
                    currentTotals.tax_amount = data.tax_amount;
                    currentTotals.total_segments = data.total_segments;
                    applyingOwnTotals = true;
                    quote.setTotals(currentTotals);
                    applyingOwnTotals = false;
                    // Record the post-/select-term state so loadFees doesn't
                    // refetch on the totals re-emit setTotals just triggered.
                    lastTotalsSnapshot = snapshotTotals(currentTotals);
                }

                if (data.tax_display) {
                    taxDisplay(data.tax_display);
                }
                if (data.term_surcharges) {
                    // Bump fetchSeq so any in-flight loadFees can't clobber
                    // the authoritative values returned by /select-term.
                    fetchSeq++;
                    applyTermSurcharges(data.term_surcharges);
                }

                confirmedTerm(days);
            }).fail(function (xhr, status, err) {
                if (mySeq !== selectSeq) {
                    return;
                }
                console.warn('Two_Gateway: select-term failed', status, err);
                revertSelection();
            }).always(function () {
                if (mySeq !== selectSeq) {
                    return;
                }
                isUpdating(false);
                var next = pendingTerm;
                pendingTerm = null;
                // A refused call reverted the chips, so its queue is stale.
                if (next !== null && next === selectedTerm() && next !== confirmedTerm()) {
                    surchargeModel.recalculateTotals(next);
                    return;
                }
                if (totalsMissedWhileUpdating) {
                    totalsMissedWhileUpdating = false;
                    // The snapshot above is of the totals this response merged
                    // into, so leaving it would dedup the fetch still needed.
                    lastTotalsSnapshot = null;
                    loadFees();
                }
            });
        }
    };

    return surchargeModel;
});
