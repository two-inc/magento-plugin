---
description: "Tax rates on composed lines, the postprocessing hook's shop-match checks, tenders as prepaid, and 0% line tax codes."
---

# Order composition and tax

## The order/tax composition path never derives a tax rate from amounts

A line's `tax_rate` is whatever the store's tax engine declared for that
line, relayed verbatim. `tax / net` is a different statement: rounding,
combined rates and a discounted base all put the quotient on a rate no tax
rule declares, and Two validates the declared rate against the line's own
amounts.

Product lines read `tax_percent` off the item. Shipping has no such column,
so `getTaxRateShipping()` reads the shipping-typed entry out of the order's
`item_applied_taxes` extension attribute and sums its applied taxes, falling
back to `OrderTaxManagementInterface::getOrderTaxDetails()`. The extension
attribute is what makes this work at PLACEMENT time: composition runs from
`Two::authorize()` inside `Order::place()`, before the order is saved, so it
has no entity id and the `sales_order_tax_item` rows the management interface
reads do not exist yet. That interface stays the source for the post-save
consumers (capture, refund).

Nothing declared is "no rate provided" (TWO-26117), whatever the line's tax.
The shipping tax fallback decides what happens then. It has no admin field,
so a merchant only gets it after talking to us:

```
bin/magento config:set --scope=stores --scope-code=<store_code> payment/two_payment/enable_shipping_tax_fallback 1
```

(`payment/<brand code>/...` on an overlay; drop the scope flags for the whole
install.) It is populated only when enabled AND Magento core's own shipping tax
class (`tax/classes/shipping_tax_class`, store scope) is set. Blank, the line
goes at 0% with its tax as charged, unchecked, and the API validates it: the
plugin never refuses over shipping tax. Populated, the rate resolves through
that class and the tax rules engine, with the arguments core's quote-time tax
calculator uses, including the tax class of the customer group the order was
placed under (TWO-26073), and the line's tax must reconcile with it within
0.02 or the request is refused. That reconcile is a shop-match check
(TWO-26276): the builders compose the line with `getTaxRateShipping($entity,
false)`, and the postprocessing hook's default handler runs
`assertShippingTaxFallback()` after the hook, unless a subscriber took it over. A declared rate, 0% included, is always sent
as is and never checked by the plugin. A refund relays the rate, and when the
placement record says the fallback supplied it, the refunded shipping's tax must
reconcile with that rate (`assertRefundShippingTaxFallback()`, TWO-26309). The plugin has no shipping tax setting of its own.

Magento 2.4.7 core records a 0% shipping rate on the quote only when shipping
prices exclude tax (`getAppliedTaxes()` skips 0% on the tax-inclusive path),
and `ConvertQuoteTaxToOrderTax` never saves a 0% row, so after placement the
tax rows cannot tell a declared 0% from no rate. Placement therefore records
the case on `sales_order`: `two_shipping_tax_rate_source` (`declared`/`none`)
and `two_shipping_tax_rate` (percent; the declared rate, or for `none` the
fallback's resolved rate, NULL when the fallback was blank). Every later
request reads that record and never the current configuration; a `none` with a
rate is re-checked against it, on refund too. Both NULL is an order placed
before the record existed, which resolves live as at placement.

Other lines are reconciled against their declared rate only as a shop-match
check (TWO-26309): on create and update, in the default handler, on a line the
plugin composed from Magento's own figures and only while the payload still
carries it unchanged, so a mismatch means the shop's tax does not match the
rate it declared. A subscriber owns its own lines, and every other request
leaves line arithmetic to Two's API (TWO-26284). Do not add any other line
check after the hook: only a shop-match check, which compares with shop data
the API cannot see, belongs there.

## The postprocessing hook's checks: shop-match only

After the hook, `OrderPostprocessor` refuses only a subscriber code fault
(it throws, returns a non-array, or returns a payload that cannot be
JSON-encoded) and lets a shop-match refusal through. Whether the payload adds
up is left to Two's API (TWO-26284).

Shop-match checks ask whether what the plugin built matches the shop.
`Service\Order\ShopMatchChecks` holds them (TWO-26309): the shipping tax
fallback reconcile, each other composed line's tax against its declared rate
(create and update), and the lines against the order's grand total plus its
tenders (refused on create and update, logged on intent). It is exposed as
`Api\OrderPostprocessingShopMatchInterface::check()` for a subscriber that opts
back in, and as `Api\OrderPostprocessingShopMatchLinesInterface::checkLines()`
for one that wants the single-line checks only; the second is its own
interface so a class implementing the first is not broken. Every refusal the
checks raise is recorded in `Service\Order\ShopMatchRefusals`, and
`OrderPostprocessor` passes through only those: a `ShopMatchRefusedException`
a subscriber constructs is `HOOK_FAILED`. Failure logs carry the exception's
class and throw site, never its message. The change log is Debug Mode only,
compact and redacted, and never an order comment. They run in the hook's default handler,
`Plugin\OrderPostprocessing\ShopMatchDefaultHandler`, a plugin named
`two_gateway_shop_match_checks`, which stands down and logs
`OrderPostprocessingShopMatchDelegated` when any other handler is registered.
Each applies to the line the plugin built while the result carries it
unchanged.

`Service\Order\PostprocessingSubscribers` detects other handlers from the
shared `PluginListInterface`, walking `getNext()` from `__self` through each
around plugin as the generated interceptor does, for the interceptor's own
type, so what it counts is exactly what runs in the request's area; a
preference replacing `Model\OrderPostprocessing` counts too. Do not replace it
with a read of the merged di.xml: that ignores area, `disabled` and module
state. A shop-match refusal is a `ShopMatchRefusedException`, which
`OrderPostprocessor` lets through its catch-all, so it never reads as
`HOOK_FAILED`. Do not move a shop-match check back into a builder, where a
subscriber cannot take it over.

## Tenders are declared to Two as prepaid

A tender (gift card, store credit, reward points) paid part of the order
outside its lines, so Magento's grand total is the lines less the tenders
(TWO-26309). `Api\OrderTendersInterface` lists them (`Model\OrderTenders`
reads Adobe Commerce's `gift_cards_amount`, `customer_balance_amount` and
`reward_currency_amount`, and the quote's `*_used` fields, as plain data, so
Open Source reads none); a merchant adds others with an `after` plugin.
`Service\Order\TenderReader` normalises them once per entity. Every builder
reconciles the lines against grand total plus tenders, and an order body
carries `invoice_amount`, `payable_amount` (the grand total, equal to
`gross_amount`) and `prepaid_amount` only when there are tenders
(`Service\Order::orderAmounts()`), so a body with none is byte for byte as
before. Fulfilments and refunds on an order with tenders declare their prepaid
from Two's own ledger, read before sending (`Service\Order\PrepaidLedger`):
the order's prepaid, less what earlier fulfilments declared, adjusted by what
refunds declared, bounded so Two's unfulfilled rest never holds negative
prepaid or more prepaid than invoice. Within those bounds a fulfilment declares
the tenders Magento still holds (less every memo's, online or offline) not yet
declared, and a refund what its memo returned. Do not replay Magento's history
instead: an offline memo moves Magento and never Two, which is how the earlier
front-loading broke. An order placed with tenders before they were declared
has prepaid 0 at Two: `Service\Order::declaredTenders()` answers 0 for it, so
every builder sends it exactly what it was sent before (lines reconciled
against the grand total, no prepaid fields), edits included. The ledger reads
the root order: Two refuses an edit of a partly fulfilled root and the plugin
only addresses the root, so the remainder is always the root less its
completed fulfilments. `PrepaidLedgerSequenceTest` simulates Two's ledger over
every sequence of shipments, offline memos and refunds on a few orders, with
an oracle that what the buyer owes Two equals the live lines less the tenders
kept wherever Two can follow (an offline memo of a fulfilled line, or one that
pulls back declared tenders, it cannot), and
`TwoAmountRulesContractTest` runs every body through a port of Two's amount
rules: keep both in step with the API.

## A 0% line carries a tax code, resolved in the builder

`Service\Order\TaxCodeResolver` adds `tax_code` to every composed line whose
`tax_rate` is 0 (TWO-24877). Each `Compose*::execute()` that sends lines calls
`Service\Order::applyTaxCodes()` before returning, so the code is on the
payload before the postprocessing hook runs and a subscriber can override it.
Order intent is left alone: the API does not check codes there.

The rows live in `tax_code_map` as JSON keyed `<product tax class>|exempt`,
`<class>|rate:<tax rate code>` and `<class>|none` (`Model\Config\Backend\TaxCodeMap`).
The form posts the whole map as one hidden JSON field that
`Two_Gateway/js/tax-code-map` rewrites from the dropdowns: a rate code can
hold any character, and one field per row could pass `max_input_vars`, where
PHP silently drops the rest and the save would delete rows. A post that is not
a JSON object is refused, never stored as empty. The shipping line keys on core's shipping tax class, the
surcharge on its own; lines with no class (`OTHER` fees, a flat-rate
surcharge, the refund `adjustment`, an unmatched product line) have no key.
For each 0% line the first match wins (TWO-26153; by design the plugin
never decides how the merchant levies tax, it reads which of their rows the
line falls under):

1. Exempt buyer: billing country and tax address both in the EU VAT area
   (`EU` plus GB with a BT postcode) and not the merchant record's country,
   which must be known, and `buyerVatNumber()` non-empty. The class's `exempt` row.
2. The class's rates at the tax address from core, via `Service\Order\ShopTaxRates`:
   `Calculation::getRateRequest()` with the order's addresses, the
   order-time customer group's tax class and the store, then
   `getAppliedRates()` per product class. Core drops 0% rates from applied
   taxes (`AbstractCalculator::getAppliedTaxes()`), so the order holds no
   record of them, but `getAppliedRates()` keeps them with their `code`. All
   0%: the first rate's `rate:<code>` row. Any rate above 0%: no code.
3. No rate: the class's `none` row.
4. No class: the single code the payload's step 1 to 3 lines (and, on a later
   payload, the recorded lines other than `fee`) share; none if they disagree.

A matched row left on (none) never falls through. The tax address is the
rate request's (core's `tax/calculation/based_on`), the same address core
taxed the line on. `ZeroTaxRates` lists each class's 0% rates for the admin
rows and for the `FanOutTaxCodeMap` data patch, which copies each old
`class => code` entry to that class's `exempt`, `none` and existing rate rows.

There is no step after these: the plugin never works a code out itself
(TWO-26153). A line whose rows are not set gets no code, and so does a
classless line when the step 1 to 3 lines give nothing to share. Do not add a
fallback that picks a code from the addresses or the product type; the
merchant's rows are the only source.

Step 1's VAT number comes from `TaxCodeResolver::buyerVatNumber()`, which
reads the billing address `vat_id`, then
the order's `customer_taxvat`, and uses it exactly as entered, trimmed of
leading and trailing whitespace and nothing else (no case change, no
characters stripped, no prefix guessed; blank is no number). A `vat_id`
refused by Magento's VAT check (`vat_request_success` true and `vat_is_valid` set and
false) gives no number at all and never falls back to `customer_taxvat`, which
often holds the same number. Core stores a failed request as invalid too, and
that alone must not drop the number. The same number goes on order
create only as `buyer_vat_number` (`vatNumberToSend()`), for an ES merchant
and a non-ES buyer: the API requires an ES buyer's VAT number to equal its
organisation number, an edit that omits the key keeps the stored value, and
every other merchant's payload stays byte-identical. **The plugin never
refuses over a missing code**: the API does. Do not add a guard that declines
a Spanish 0% line with no code; the line is sent and Two decides.

Two rules hold the invariants and should not be loosened:

-   A non-zero line, and every line of a non-Spanish merchant with no row set,
    is composed byte for byte as before. `TaxCodeResolverTest` pins that by
    composing each payload with and without the resolver.
-   The merchant's country is the merchant record's, never the store's
    configured country: the API rejects a code whose country differs from the
    Two merchant's.

An unreadable stored map (anything the form could not have written) throws
from `Repository::getTaxCodeMap()` (TWO-26309): `isAvailable()` withholds Two,
and create, or an edit with a line needing the map, refuses. Capture, shipment
and refund pass `mapRequired` false: recorded codes go as placed and a line
needing the map goes uncoded, so a broken setting never blocks a refund.

Placement stores the code each 0% line resolved to, only where it got one, in
`sales_order.two_tax_codes` (product lines keyed `item:<quote_item_id>`, then
`shipping`, `surcharge`, and one shared `fee` key for every other `OTHER` or
`BUYER_FEE` line), the same pattern as the shipping rate record. Fee lines
share a key because they all resolve alike (no class, so step 4) and because
a fee can change id after placement: a provider that itemizes only a saved
order leaves the create with an "Other charges" residual and the edit with its
own line. The record's `shared` key lists the codes steps 1 to 3 gave at
placement; the refund `adjustment` line is not recorded and takes step 4 over
that list. A record written before the key existed shares the codes its
recorded lines carry instead, other than `fee`'s; with none, or disagreeing
ones, no code. Edit, capture, shipment and refund read the record and
never resolve those lines
again; a line it does not cover (placed with no code, added since), or an
order placed before it existed, resolves live. A `null` an older record holds
counts as not covered. Only `PHYSICAL` and `DIGITAL` lines are looked up as order
items, so a fee provider's numeric id is never taken for one, and a product
line no item matches has no class, so step 4. At placement
the items have no id, so `ComposeOrder` matches its product lines to the
items behind them on SKU, the name only breaking a tie, never by position
(`matchLineItemSources()`), so a plugin that reorders or adds lines cannot
shift classes. The admin section (TWO-26153) is decided by
`Model\Config\TaxCodeSection` from the facts `TaxCodeSectionResolver` reads at
the edited scope: the record's country and `Service\Api\TaxCodes::getList()`,
which caches a country's answer a day, keeps its last answer in the flag
table (a cache flush does not clear it),
waits five minutes between failed fetches and caches 400
`COUNTRY_NOT_SUPPORTED` as an empty list. The table shows only where the list
says codes are mandatory at 0%; otherwise no row renders, so nothing posts.
The backend model stores rows only while the table shows, and "Clear saved
tax codes" posts `TaxCodeMap::CLEAR` in place of the map and stores `{}`. The
hidden field starts as the saved map and carries saved rows the table no
longer shows (a rate deleted or raised above 0%).
