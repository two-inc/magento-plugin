---
description: 'Every gate `isAvailable()` withholds the Two method in, and why each one is silent.'
---

# Payment method availability

## The order `isAvailable()` withholds in, and it is SILENT

Core's own checks; a configured non-empty API key; the api-key verification
verdict; the surcharge FX rate resolving and the stored surcharge method being
recognised; the buyer country; the fee quote for the term being charged; then an
Amasty store view returns true early, deferring only the minimum-order gate to
the client; then the platform and merchant minimum-order gate.

**The api-key verdict is the gate the ruling puts that power in**, and
only its definitive-rejection categories withhold. Do not widen it
back to every failure category, and do not add a further gate that withholds
because a call to Two failed; both are defects the rule exists to stop coming
back. The buyer fee quote is the one named exception: a term whose
fee cannot be priced cannot be charged, so `Service\Order\FeeQuoteGate` prices
it rather than wait to be told — the term-chip endpoints answer after the
payment list has rendered, and the totals collector prices only once this
method is already selected, so no later request is guaranteed to notice.

The gate resolves the charged term through `Service\Order\ChargedTermResolver`,
the same resolver the totals collector uses, so the two cannot disagree; a
selection the merchant has since withdrawn falls back to the default rather
than pricing a term the order would be refused for at placement. It prices the
fee-EXCLUSIVE total, as the collector and both chip endpoints do, so the fee
already on the quote is neither compounded nor a cache miss against their
quote. `SurchargeCalculator` bounds every surcharge quote at one ceiling,
below the adapter default because a quote is also made on a render path, where
a hanging endpoint would otherwise stall the payment step. Gate and charging
path share that ceiling by construction, so the gate cannot refuse a fee
placement would have priced. A refusal — including a malformed
response, which is caught as broadly as the collector catches it — withholds
the method for that request and that cart only; the next request re-asks, so
recovery needs no expiry and one buyer's refused quote cannot reach another's
checkout. During a pricing outage every payment-method render therefore spends
one such call, bounded by the gate's own ceiling, and that cost is accepted so
a slow-but-healthy pricing service is never mistaken for a refusing one.

Guards run before any call and concede the method without one: the adminhtml
area, no surcharge configured, no cart carrying items and a positive
fee-exclusive total, no currency, no term offered. The area guard is what keeps
admin order create from pricing: Magento evaluates payment availability there
against a quote, and `canUseInternal()` is not consulted first, so without it
an admin session's term would be quoted.

The admin settings page prices nothing either way: its fee preview reads
merchant rates through `Service\Merchant\FeeRatesProvider`, never the buyer
quote, so a merchant is never locked out of the settings needed to fix this.

**Every buyer-facing surface asks that same question, and must keep asking it.**
Three besides `isAvailable()`: `Model\Ui\ConfigProvider::getConfig()`, whose
emptiness is a withhold in all but name because the Luma company-search widget
and the payment renderer both mount behind `getActiveTwoBrandCode()` finding a
`payment` subtree with a truthy `redirectUrlCookieCode`;
`Model\Webapi\OrderIntent::place()`, reachable whenever the tile is offered, so a
refusal there is a red unavailability notice on a working checkout; and
`Model\Webapi\CompanyLookup::merchantParams()`. Widen any of them past
`isDefinitiveFailure()` and an outage puts the method on offer with no config,
or a notice, or unattributed lookups.

**Merchant IDENTITY on those surfaces falls back to the record.**
`ApiKeyStatus::getStatus()['merchant']` is populated only on a success, so a
fall-through reads `SettingsProvider::getMerchantIdentity()` — the
never-expiring record's `id` and `short_name`. Both sources normalise through
`SettingsProvider::identityFrom()`, so the checkout config publishes
`{id, short_name}` either way rather than the whole verify_api_key body on a
success; the merchant's commercial fields have no business in the page. The one
state with no identity to send is a shop where no record has ever resolved, and
the order-intent route still refuses there.

Two on the list are NOT the store's own configuration and are worth knowing
about. The surcharge FX gate resolves its rate table from Two, and the
minimum-order gate fails closed when it cannot convert at that same table. Both
are nonetheless safe against an outage, because the rate table is cached with no
expiry and keeps its last-known-good on a failed fetch, exactly as the merchant
record does — so an unreachable API loses neither. What remains reachable is the
narrow case of a table that was never fetched, or a cache flushed while Two is
unreachable. The rate table's own refresh-on-read is bounded at 10 seconds, as
the merchant record's fetch and the api-key verification are, so a table past
its refresh interval cannot hold a storefront render longer than that while an
outage runs.

The FX gate is not simply removable: it exists because an unresolvable rate used
to throw inside the totals collector and error the whole checkout, which is
worse than withholding one method.

**There is no captured-company condition anywhere on that path.** The
company-number guard runs at placement, not at render — do not reach for
`isAvailable()` to explain a company-capture symptom.

**Every one of those withholds is invisible to the buyer**: the method simply
vanishes, with no message, no error node and an empty message area. Each gate
writes a log line and that is the only account of it — debug at the gate, error
where the underlying service reports the cause — so the log is where a "why is the
method missing" question gets answered. The fee-quote gate is the one that error-logs
at the gate itself, class and message only: nothing downstream records a corrupt
cached quote or a failing session read. An unrecognised stored surcharge method
throws with a buyer-facing string that no buyer ever sees.
