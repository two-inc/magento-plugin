---
description: "Place Order gating, the term chips' radio-group contract and labels, and term confirmation before submit."
---

# Checkout payment tile

## A declined order intent refuses order placement

**It does so through the Place Order button's own BINDING** —
`isPlaceOrderEnabled()` over an observable verdict, never an imperative class or
attribute write (TWO-25657). Core's
billing-address subscription re-evaluates that button and clears anything
written onto it from outside the binding, silently, so an imperative disable
lasts until the buyer touches an address field.

**And it always says why, in wording no brand can withhold.** The decline
sentence is the buyer's only account of a control that has gone dead, so
`resolveOrderIntentDeclinedNotice()` falls back to platform copy wherever
`<intent_declined_notice_enabled>` withholds the brand's own — the rule the
order-intent error notice already followed. A shipped overlay
withholds it, which is how a declined buyer came to face a disabled button and
an empty tile. The brand switch chooses the wording; it cannot choose silence,
and re-widening it to suppression reopens the defect.

The sentence lands inside a live region rendered ahead of it — a `role="alert"`
element created together with its own text is announced inconsistently — and
the button points `aria-describedby` at that region while the decline stands.
Note that a natively `disabled` button is not focusable, so the announcement
comes from the region, not the association.

**Two unrelated conditions disable that button, and each owns its own region.**
A declined intent speaks through the decline region; an unreconciled term
speaks through the chips' status region (below). Neither reads the other's
state, so a buyer held for a stale total is never told they were declined, and
a declined buyer is never told their term is still applying. A third condition
added later needs its own region for the same reason.

## The term chips are a radio group

The chips are `button` elements carrying `role="radio"` inside a `radiogroup`,
and they implement that role's whole keyboard contract: the group is a single
tab stop — one chip at a time carries `tabindex="0"` — and the arrow keys move
the checked term and the focus together, Home and End jump to the ends, and both
ends wrap. Both halves or neither: roles without the keyboard
behaviour advertise something the group does not do, which is its own defect.

`onTermKeydown` returns true on every key it does not act on, because knockout's
`event` binding suppresses the default action unless a handler says otherwise —
without it the group swallows Tab.

`isTermChecked()` is the single definition of a selected chip, read by the
`aria-checked` binding and by the visual `--selected` class, so the tick and the
exposed state cannot drift apart. The tick is drawn with an empty alternative
text (`content: '\2713' / ''`), so the selection is stated by `aria-checked`
alone and never repeated inside the chip's accessible name. A selection matching
no chip leaves nothing checked, and `focusableTerm()` puts the tab stop on the
first chip so the group cannot drop out of the tab order.

**`termOptions` is a plain array, not a computed.** Knockout's `foreach` over a
recomputed array rebuilds every chip node, and a `/select-term` response
rewrites the per-term fee maps on its way back — so a computed drops the focus
the arrow keys just placed, one round trip later. Each chip reads its fee
through a computed of its own instead, which is what lets the nodes outlive a
refresh. The template CALLS those computeds: a binding negating one — the fee
label's `!isLoading()` — negates the function itself and is permanently false
without the call, where a binding handed the bare value unwraps it.

The focus ring is `:focus-visible`, not `:focus`: the group's single tab stop
makes the focused chip the only thing saying where the keyboard is, and a
clicked chip still gets no ring.

**Every ARIA association in the payment template is keyed on the payment code.**
The chip group's label, the consent checkbox and the consent sentence each take
their id from `getCode()`, as the declined-notice region does, or a second brand
tile's controls point at the first tile's text.

The method radio is named by a `label` carrying `for`, so the tile title is both
its accessible name and a click target for it; the subtitle and the about link
stay outside that label. The consent checkbox is named by `aria-labelledby` at
the consent sentence instead, because that sentence carries the terms link and a
link inside a label makes activation ambiguous.

## A chip states its term type, not just a day count

An end-of-month term falls due that many days after the end of the month, so a
chip reading "30 days" on an end-of-month shop states the wrong due date. The
visible text is `30 days` under standard terms and `EOM+30` under end of month,
and only the end-of-month chip carries a `title` and an `aria-label` spelling
that out: `EOM+30: pay 30 days after the end of the month`. The accessible name
opens with the visible token because WCAG 2.5.3 requires it to contain the
visible text, and a standard chip gets no name of its own because one that
merely restated `30 days` would risk the same criterion.

The name also states the surcharge, because an `aria-label` replaces the whole
accessible name and the `+€n.nn` rendered inside the chip is then announced
nowhere: `EOM+30: pay 30 days after the end of the month, plus a €7.25
surcharge`. Each wording is one translated sentence rather than an assembled
one, so a translator can order the clauses. A term carrying no surcharge, and a
term whose quote has not landed yet, name no amount at all.

`isEndOfMonthTerms` reaches the renderer from `ConfigProvider`; the chip text and
the explanation are built by `termChipText()` and `termChipExplanation()`, which
the sole-term branch reads too. The explanation is a computed over the same fee
map the visible amount reads, so the name follows the quote in. An empty
explanation reaches the `attr` binding as `false`, not as `''`, because knockout
renders a blank attribute and removes a false one — which is why both bindings
call the computed rather than passing it unwrapped.

## The sole offered term is a disabled button

One offered term is not a choice, but it still has to carry the name that spells
the term out, and ARIA prohibits naming a role-less element — which a bare
`span` is. So the sole chip is a `button` with the native `disabled` attribute:
naming works, and a natively disabled button is not focusable, so the tab order
skips a chip that has nothing to select. It keeps the `two-term-chip--single`
class, which is its whole appearance; the base chip rules already set border,
background, padding and font because the multi-term chips are buttons too.

## The selected term must be CONFIRMED before submit

The order is composed on the term the chips show as selected, so a selection
the server has not confirmed it priced the quote on can be charged against a
total the summary never showed. `surchargeModel.termStatusMessage()`
is the whole invariant: it names the reason placement is refused — a call in
flight, or a selection no `/select-term` confirmed — and is empty only when the
chips agree with the priced quote. `isTermReconciled()` is that string being
empty, so the gate and the buyer's explanation cannot drift apart, and
`confirmedTerm` moves only when a `/select-term` response actually carries the
totals it re-collected.

It gates placement twice: `isPlaceOrderEnabled()`, so the button is disabled
rather than only answering a click, and `placeOrder()` as the belt, which
surfaces that same string.

**Do not gate on the chip fees instead.** Comparing the summary's
`two_surcharge` value against the chip map looks stronger and is weaker: the
map is documented above as lagging a `/totals-information` transition by one
step, and `loadFees()`'s own snapshot dedup can then decline to refresh it — so
a numeric comparison can refuse a settled checkout permanently, behind a
message that says it is still updating.

**Only one `/select-term` is ever in flight**, and `recalculateTotals()` itself
is what holds that — not its caller. A chip clicked during a call is held and
sent once that call settles, and dropped if it was refused. Overlapping calls are
serialised by the server on the session lock, which can take them in the opposite
order to the one they were sent in — so the client's own send order is no
evidence of which term the session ended on, and confirming from it can leave the
session holding a term the chips discarded as superseded.

Every write a settled response makes runs inside a `try`. A totals subscriber or
a knockout binding throwing would otherwise abort the rest of jQuery's callback
chain, leaving the updating flag latched and the Place Order button disabled for
the life of the page, with every later totals emission discarded as this module's
own. **The term is confirmed even when writing the summary partly failed** — the
server answered, so it holds that term, and reverting the chips against it is
what charges a term nobody selected.

**The chips state every reason the button is dead, and they read that one
string.** The button is disabled by then, so the click-time message cannot be
reached, and a primary button greying out against an empty tile reads as a
broken checkout. A selection outlives the call that would have confirmed or
reverted it whenever a chip binding throws out of `selectTerm()`: that aborts
before `/select-term` is sent, so nothing is in flight and nothing reverts. The
status node is rendered unconditionally and its text toggled, because a live
region created together with its text announces nothing.

Server side, `Model/Webapi/TermSelection.php` stages the session term. The
surcharge collector prices on that term, so the rollback restores the session
first and only then reprices — repricing while the staged term still stands
prices the staged term again. The session ends up holding whatever term the last
persisted save priced: if the repricing back fails in turn, it is deliberately
LEFT on the staged term, because a session that agrees with the saved quote lets
placement refuse the disagreement it can see, while one that disagrees lets an
order carry one term's fee against another.

The call carries a `timeout`. Without one a hung request holds `isUpdating()`
true for the rest of the session, and with it the Place Order button disabled.

A totals emission that arrives during a chip click is dropped by the subscriber,
so the fees are re-evaluated once the click settles — and the snapshot the
response wrote is cleared first, or the dedup would suppress exactly the fetch
the dropped emission needed. The module's own write-back of the response totals
is excluded, so a settled click does not refetch what it just received.

A `/select-term` the server did not take reverts the chips to the confirmed
term and says so. A 200 that carried no re-collected total segments counts as
not taken: nothing confirms the term without them, so leaving the selection
standing would refuse placement with no message and nothing to click — the
chip the buyer appears to have selected already is a no-op.

The message is raised before the chips are written back. A knockout observable
assigns its new value before notifying its subscribers, so a chip binding that
throws on that notification aborts the rest of the revert — the selection is
already reverted by then, and the explanation would never be reached, leaving
Place Order live above a choice the buyer sees silently undone.
