---
description: 'Offline invoices before the fulfilment trigger, status-change fulfilment, and refunding unitemized fees.'
---

# Fulfilment and refunds

## An offline invoice before the fulfilment trigger is commented, not refused

With a fulfilment trigger other than `invoice`, `Two::canCapture()` is false, so
the admin invoice form submits an offline capture and core records a Paid invoice
without calling Two. An unverified order is refused that invoice outright
(`Plugin\Model\Sales\RefuseInvoiceWhileUnverified`, TWO-26294). A verified one
is allowed, and `Observer\InvoiceRegisteredOffline` adds one merchant-only order
comment on `sales_order_invoice_register`, which core dispatches once per invoice
(TWO-26302). Online captures and other payment methods get nothing.

**The plugin's own fulfilment invoices are flagged.** The shipment and
status-change flows also register offline invoices, after Two has been told, and
set `InvoiceRegisteredOffline::FULFILLED_WITH_PROVIDER` on the invoice first. A
new code path that registers an invoice after fulfilling with Two must set it
too, or the merchant is told Two was not notified when it was.

**The status-change fulfilment is gated on the fulfilment marker, not on an
invoice.** `SalesOrderSaveAfter` used to return early on `hasInvoices()`, which
made a merchant's offline invoice suppress the fulfilment for good. It now
returns early only once the payment carries `marked_completed`, which every
successful fulfilment sets (its own, the shipment observer's and
`Two::capture()`) and which the order save persists with the payment. A
fulfilment sets it, with the completion comment, only when Two's response
carries the fulfilled order's id; Two returns that id on every accepted full or
partial fulfilment, so a response without it marks nothing. A merchant
invoice for everything leaves a zero-total invoice, which is not created; a
partial one leaves the rest for the plugin's invoice. `FulfilmentInvoiceTest`
saves the order twice per case, in two requests, and pins one fulfil.

**The fulfilment waits for the commit.** Core's refund routes save the order and
the credit memo one after the other, so `sales_order_save_after` can fire with
the memo not yet saved: the REST routes (`RefundOrderInterface`,
`RefundInvoiceInterface`) save the order first. So `Observer\SalesOrderSaveAfter`
only decides, and `Service\Order\StatusFulfilment` sends:

-   **Synchronous, as before:** the gate (Two order, `complete` trigger, a
    fulfil-on status, no marker) and the whole-order check, which still refuses
    the merchant's save with its error.
-   **Then deferred to the commit.** The observer registers a commit callback on
    the sales connection, which core's `ExecuteCommitCallbacks` plugin on the
    database adapter runs after the outermost commit and drops on a rollback.
    The callback reloads the order through `OrderFactory` and the resource model
    (the repository hands back the instance the save was working on),
    re-checks it and fulfils it. Once Two accepts, the marker, the comment, the
    plugin's invoice and the order with its invoiced totals are saved in one
    transaction on the sales connection. With no transaction open it runs at
    once; the order's own save always holds one when the event fires, so that
    branch only serves a dispatch outside a save.

**This relies on core running the refund saves inside one transaction.** From
2.4.6, the module's minimum, it does on every core route: the REST routes run
inside the order lock, whose executor wraps the order and memo saves in one
transaction, and the admin credit memo (`CreditmemoService::refund()`) wraps both
in an adapter transaction. A refund made by another extension's own code that
saves the order and the memo without a transaction is not covered: the callback
runs after the order's own commit, before the memo exists, so what that memo
refunds of the fee-provider lines and the other charges is not netted out.

**A failed fulfilment is commented, not thrown.** When it runs, the merchant's save
or refund has already succeeded, and throwing would report a failed refund that in
fact went through. The order gets the comment "Failed to fulfil order with Two.
Reason: ...", the wording a failed refund uses; an admin request also gets it as an
error message (REST and other callers get nothing extra); and
`StatusFulfilmentFailed` is logged at error. That covers a refusal by Two and one by
the postprocessing hook alike. The marker stays unset, so the next save of the
order while it is in a fulfil-on status tries again. If Two accepts but saving
the order and invoice then fails, all of it rolls back, and the comment says
instead that Two fulfilled the order but it could not be saved
(`StatusFulfilmentNotSaved` at error). If that rollback itself fails, it is
logged on its own line (`StatusFulfilmentRollBackFailed` at error), and the
original reason the save failed is still reported in the log and the admin
message. The order comment is written only if the connection still works: a
rollback that fails because the connection was lost leaves the adapter inside
the transaction, so the comment's save fails too and is logged as a
`report_error`. The admin message is shown before the comment is saved, so a
failed comment cannot suppress it.

**Never twice.** The callback's own order save fires the observer again, and the
marker it has just set stops it; two saves in one transaction register two
callbacks, and the second finds the marker. `Service\Order\FulfilmentAttempts`
records each order sent in the request, refused or not, which stops a copy of
the order loaded before the marker was set from fulfilling again when it is
saved later in the same request. The payment resource is version-controlled, so
an unmodified stale payment is not written and the saved marker survives; the
record matters when that copy's payment was changed, since its save then writes
the payment back without the marker, and after a refusal or timeout, when there
is no marker at all. The request's own order instance (and the one in the
repository registry and in `sales_order_save_commit_after`) is stale once the
callback has run: a later modifying save of it in the same request would write
the pre-fulfilment totals back over the callback's. No core route does this.

**Refunds before that fulfilment are netted out.** Once the merchant can invoice
first, they can also credit-memo first. The whole-order check counts refunded and
cancelled quantity as not waiting to ship, and when anything was refunded or
cancelled the fulfilment is sent as a `partial` body built by
`ComposeShipment::executeNetOfRefunds()`: each line prorated to its net quantity,
shipping only while none of it was refunded. It is the only fulfilment the order
gets, so unlike a shipment's partial it also carries the charges no item owns,
each less what the credit memos refunded of it: the surcharge (its amount less
`two_surcharge_refunded`), each fee-provider line (less that provider's lines on
the saved memos) and the other-charges residual (less the memos'
`two_other_charges_amount`). The memos come from a fresh credit memo query, not
`Order::getCreditmemosCollection()`: the order caches that collection once
loaded, and the memo whose refund moved the order into the fulfil-on status can
be missing from it. A cancelled memo refunded nothing and counts for nothing.
Tax shrinks in proportion, so each line keeps its declared rate, and a charge
with nothing refunded goes in exactly as the order line. With nothing left, nothing is sent. The Two remainder of such a partial
fulfilment stays open, as it does after a partial shipment the merchant never
completes.

**An offline invoice is also one with no capture case** where the method cannot
capture online: the REST invoice route names no capture case.

**The comment names the trigger.** On shipment, or on reaching one of the
configured fulfil-on statuses by their labels. With no status configured the
complete trigger never fulfils, so no comment is added there.

## An unitemized fee is reconciled per entity, and refundable

`findVerifiedResidualTaxRate()` reconciles a taxed residual against the rates
Magento's own tax engine applied, which is enough to itemize the fee in the
payload. It is not enough to leave the fee as a residual: whatever reaches the
residual is offered to the merchant to refund, so a fee whose extension runs
its own credit-memo total collector — Amasty's "Extra Fee" among them — must be
claimed by a `FeeLineProviderInterface` instead. It resolves an invoice or credit
memo to its own order and reads the rates there: the residual on either is a
share of the same order-level fee at the same rate. It reads every rate the
order records: the `applied_taxes` extension attribute, the item-level tax
rows, and the order-level tax rows. All three are read rather than the first
one that is populated, because an order can carry its products' rate in the
item rows and a differently-taxed fee's rate only at order level. The admin
invoice and credit-memo controllers load the order through `OrderFactory`,
which never populates the attribute, and a fee contributed by a totals
collector has no taxable item row of its own, so without both persisted
sources a taxed fee stays unrefundable on exactly the screen the merchant
uses.

Reconciling the refund payload is not enough on its own, because an unowned
fee that reaches the grand total through a totals collector rather than a quote
item never reaches the credit memo at all — the refund totals omit it and the
merchant cannot refund it. `Model\Total\Creditmemo\OtherCharges` prorates the
order's residual onto the credit memo by refunded subtotal share, and
`Block\Sales\Total\OtherCharges` renders it as "Other charges".

Both take the residual from `Service\Order\OtherChargesResolver`, which runs
the composition path's own `getOtherChargesLineItem()` over
`getKnownLineAmountsOrder()` plus any registered provider's fee lines — the
same reconciliation `reconcileOtherCharges()` performs. None of it names an
extension: the residual is defined by what the grand total exceeds, never by
whose fee it is. The resolver answers only for a Two order — by payment-method
INSTANCE, since a brand overlay's `GenericPaymentMethod` extends `Two` under
its own per-brand code — because a store-wide fee extension applies to every
order and this module has no business moving anyone else's refund total, nor
offering an editable charge row on someone else's credit memo. The collector
re-checks it before it resolves anything.

`getKnownLineAmountsOrder()` counts what composition _should_ itemize, which
is deliberately not identical to what it actually emits. Two known
divergences: it counts an item whose product no longer loads, where
`getLineItemsOrder()` drops it and the dropped item's own value would read as
an unitemized fee and be refunded as one; and it reads the surcharge only
from the order columns, where `ComposeOrder::execute()` still falls back to
the checkout session. It also loads no products, which a totals collector
re-run on every credit-memo render cannot afford, and it avoids
`getShippingLineOrder()`, because resolving the shipping tax rate queries the
tax engine and can refuse the line (TWO-26117).

**The fee's VAT is not already on the credit memo.** Core's
`Creditmemo\Total\Tax` builds the tax up from item `tax_invoiced` plus
shipping tax, then treats the order's allowance two different ways: a `min()`
ceiling on a partial memo, but a straight assignment on the last one (and only
when shipping is not partially refunded). So a fee belonging to no item and no
shipping is in `tax_amount` already on that last memo and absent on every
other. The sibling `Creditmemo\Surcharge` collector measures its own grant the
same way rather than assuming core's proration granted it. A baseline drawn
from the order surcharge instead claims VAT core withheld: on a partial memo it
understated that memo's tax total and grand total by the whole VAT on the
surcharge being refunded, left the memo short against its own lines so this
collector refused the fee there, and on the last memo — where core assigns the
allowance in full — it over-refunded VAT on a surcharge share the merchant had
held back. Both baselines are capped by what the charge still has refundable,
since the allowance core assigns covers every unitemized charge at once.

How much core granted THIS fee is read the way `ComposeRefund` reads it — the
memo's tax less the tax of every line composition itemizes (items, shipping,
surcharge), in ORDER currency, where the payload evaluates its residual —
never from the tax headroom, which can be zero for reasons unrelated to the
fee, and never in base currency, which desyncs the two on a converted order.

**Every ceiling is applied by solving the NET, at the fee's own rate.** There
are three: the proration share (less what earlier memos took), the tax
allowance, and `validateForRefund()`'s base grand-total ceiling. Clamping a
net and a VAT that were chosen separately cannot preserve a rate — scaling
two legs while the already-granted VAT stays fixed changes the quotient — and
a fee declared at any other rate is refused by `ComposeRefund` while the
grand total still carries the money. So the net is solved as the minimum
those ceilings allow and the VAT follows from it: `taxDelta = rate × net −
granted`. A smaller share refunded at the exact rate beats the whole share at
a wrong one. Entitlement is cumulative — `feeNet × (refunded subtotal share
including this memo) − already refunded` — so a share an earlier memo could
not take is recovered by a later one rather than stranded, and the last memo
lands on the whole charge exactly with no rounding residue. The one exception
is the stranding case below.

**The merchant can refund part of the charge, or none of it.** The credit-memo
form carries the amount as `creditmemo[two_other_charges_amount]`, which
`Plugin\Model\Sales\CreditmemoFeeOverride` — one parser for that field and the
surcharge's — stamps on the memo before `collectTotals`. A stamped value
replaces the proration entirely and is bounded only by what the charge has
left, so the whole charge is refundable on a memo carrying no items; a cleared
field is an explicit zero rather than a fall back to the proportional default.
The cap is checked at the form against the resolver's derived residual less
what earlier memos took, with a one-cent tolerance for a default that
round-tripped through a 2dp display — the collector clamps to the real cap
regardless, so the tolerance cannot over-refund. With no charge on the order
nothing is stamped at all, or the value would persist to the memo's own column
and render there as a refund that never happened.

**The field offers only what the collector resolved**, and 0.00 when it
resolved nothing. Recomputing a proportional default for the input instead
prefills an amount the collector has already deferred, and a merchant saving
that untouched form posts it as an explicit instruction — turning a fee the
memo silently omits into a refusal that blocks the credit memo.

**A ceiling refuses an override out loud.** Every ceiling below is silent on
the proration, which simply takes less; against a typed amount each raises a
`LocalizedException` naming the ceiling and the amount that would fit, because
a merchant who types 7.25 and is handed 0.00 has been told nothing. That
includes the two solved ceilings, whose clamp is refused rather than applied,
and an unusable conversion rate.

Three cases defer rather than pay out on the proration path, all logging
`OtherChargesDeferred`. A
NEGATIVE granted amount means some other total's tax is missing from the
memo — on a partial memo of a surcharged order core omits the surcharge VAT
that `ComposeRefund` declares in its surcharge line — and adding it here
would refund another total's VAT under this fee's name and at a rate that is
not this fee's. A granted amount larger than `rate × net` cannot be reduced,
since the collector only ever adds tax. And no ceiling leaving any room at
all resolves the net to zero.

**Known gap: a surcharged order refunded across two or more partial memos
strands the fee permanently**, rather than deferring it to a memo that can
state it. Memo 1 defers on the negative granted amount; the last memo's
granted then contains the surcharge VAT memo 1 never booked, so it defers
again. No money is misstated — this is the pre-existing behaviour for that
configuration — and the root cause is `Creditmemo\Surcharge`'s tax-delta
assumption above, not this collector.
