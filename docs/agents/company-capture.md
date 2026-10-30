---
description: 'The vendored company-search panel, focus rules around the sole-trader signup popup, and region resolution in the company relay.'
---

# Company capture

## The company-search panel is ONE module, vendored twice

`view/frontend/web/js/model/company-search-panel.js` and the WooCommerce
plugin's copy are BYTE-IDENTICAL. `EDIT_LOCK_SHA256` in
`Test/Js/company-search-panel-vendored.test.js` fails this suite on any edit to
the file that did not move the digest with it, and the other plugin's suite
holds the same digest for its copy. **Two matching digests are the parity
check**; two different ones are the drift, and comparing them is the one thing
either repo can do alone (TWO-25503).

**A change to shared panel behaviour is TWO edits in ONE change set**: edit here,
apply the identical edit there, re-run both JS suites, and move both digests.
Re-copying the whole file is NOT a way to re-sync: once the copies differ it
reverts whatever only the target side held, and while they agree there is
nothing to copy.

**Everything platform-specific is an OPTION the host passes**, never an edit to
the file: the transport, the chips and their modes, the country source, the
rate-limit scope. A difference that cannot be expressed as an option is a
divergence, and it divides the two checkouts.

It is framework-free with a UMD tail — no RequireJS, jQuery or Knockout DEPENDENCY —
which is what lets the Hyvä checkout load this repo's own copy by
`Two_Gateway::` reference instead of reimplementing the panel. Anything that
makes it depend on this checkout's framework breaks that arrangement.

**There is no checkout-specific copy.** Every Magento checkout variant a store may
run — the default one and any third-party one-step replacement — loads this same
file, so a "fix it for that checkout" copy is a fork, not a fix.

**The unsupported-country gate withdraws SEARCH, never manual entry.** It hides
the panel's query row and the registered-company chip; the panel itself still
opens, because the chips inside it are the buyer's only route to manual entry and
the sole-trader flow, and the company field never carries the native `disabled`
flag. Anything that closes or refuses the panel on an uncovered country leaves a
buyer there with no way to name their company at all.

**The chip row is shown whenever it offers a mode the buyer is not already in**,
not merely whenever it holds two chips. A lone chip for the current mode is no
choice; a lone chip for a different mode is the buyer's whole way out.

**The company field opens the panel on FOCUS**, through the same `open()` a
mousedown runs, which puts the caret in the panel's query field — or on the first
offered chip where the query row is withdrawn, so no mode opens the panel with
focus nowhere. Same state a click leaves it in, and the same on every platform
that carries this control.

**Wherever the mode leaves no query row, a printable key keeps the caret on the
company field.** The panel opens onto a chip there and a chip is a `<button>`,
which swallows text, so the character was lost with nothing on screen to say so.
Both routes in are covered: a key pressed on the chip itself, and a
key pressed on the field, whose own opener would otherwise park the caret on the
chip before the character arrives. Space and Enter are excluded, since both
activate the focused chip. The field's `input` handler then leaves the buyer's
text where they can see it and, where a MODE withdrew the row, copies it into the
query ready for the mode change that reveals it — not where the country gate did,
since no search there can ever run.

**Closing the panel puts focus back on the company field** — Escape, a pointer
press outside, a company adopted from the results, manual entry taking the field
over, or the capture controller closing it when the hosted signup answers.
The field's own focus opener is held off for that one programmatic
focus alone, so any keydown on the field, a click on it, or focus arriving from
anywhere else brings the popover straight back; a flag left set is a popover that
never reopens.

**`holdFieldOpener()` holds that same FOCUS opener off for a whole signup
flight**, and the pointer and keyboard openers stay live throughout. A browser
re-fires `focus` and `focusin` on whatever the opener window still holds when it
regains focus, which is the field the launch parked focus on — and by then the
popup is gone, so nothing read at that moment tells the re-fire from the buyer.

**The hold ends on a focus pair on the field, and on nothing timed.** A pair with
the window's own `focus` beside it is that window's return — measured in a live
browser at anything from a second after the popup closed to a minute, however long
the buyer stays away — so the popup's close is bookkeeping and releases nothing;
that pair is swallowed whole and its `focusin` half clears the hold without
opening. A pair with no window `focus` beside it is a buyer arriving on the field
by Tab, and its `focusin` half clears the hold AND opens the popover, so a
keyboard-only buyer is never left without the control. The launch's own park is
neither: it reaches the field through the same programmatic path a close does,
which the focus opener skips outright. A `pointerdown`, a `click` or a keystroke
on the field clears the hold as well: a buyer who comes back and reaches for the
control gets the popover.

**The close-on-focus-leave path is the exception, and deliberately so.** It only
fires once focus has settled on another control, so taking focus back would undo
the buyer's own Tab (TWO-25326).

**Escape is bound to the PANEL and to the company field, not to the query
field.** Outside registered-company mode the query row is withdrawn and a chip
is what holds focus, and the popover is drawn over the control below the field
— so an Escape the query field alone answers leaves that buyer with no route
out at all. The field needs its own binding because it is the panel's SIBLING,
not a descendant: a mode change, and the sole-trader signup launch that parks
focus there while the popover is deliberately held open, both leave the
dismissal key on a node the panel's handler never sees.

**A press on the panel's own dead space is a no-op.** Its default action would
blur the caret out of the query field and leave the open popover holding
nothing, so the press is cancelled — except on a control, which a press is
entitled to focus, and except on a scrollbar, where cancelling would stop the
drag scrolling the results. The outside-press close is a different gesture and
unaffected: it is a press the panel does not contain.

**A pointer press outside the popover takes focus back only where the press left
it nowhere**, and one tick later rather than in the handler: the press's own
default action runs after the handler and either focuses what it hit or clears
focus entirely, so focusing the field from the handler is simply undone. Neither
default action exists in jsdom, which is why this needed a real browser.

**The open panel takes the field's tab stop**: `tabindex="-1"` while it is up, and
on close the field's PRIOR value restored exactly, which is removal when there was
none — a theme's own `tabindex` is given back, not removed (TWO-25503). Without
it the focus opener is a keyboard trap: the opener puts the
caret in the query field, Shift+Tab returns to the field, and the opener pushes
focus forward again, so the buyer cannot get back past the control (WCAG 2.1.2).

**Only one popover is open, page-wide.** Opening one closes whichever other one
was open, enforced at open time rather than inferred from focus leaving the
first: a real pointer press on a second capture need not deliver a focus event
to the control it hits. The popover that closes gives its own field's
tab stop back before the newly opened one takes its. A pointer press outside the
open popover closes it too, with the company field counted as inside the
control.

## What focus landing on the checkout does to an open signup popup

Every `focusin` while the hosted sole-trader signup window is up is classified
once, and these are the three rules (TWO-25658). A `mousedown` is classified the
same way (TWO-26775): a press on page content nothing can focus moves no focus
at all, and it is still the buyer back on the checkout.

-   **A Sole trader chip inside the capture's own popover is inert.** Arrival
    moves the popup neither way — only an activation raises it, and the browser
    delivers Enter and Space on a focused chip as a click.
-   **Any other target closes an open popup**, Enter manually included: its
    release focuses the parked field, so it closes the popup itself first.
-   **A target outside that capture's popover closes the popover too**, with the
    company field counted as INSIDE it: the field is the popover's own trigger
    and sits outside the panel node, and a buyer typing a query is still inside
    the control.

A `focusin` the browser re-fires on window return counts as the buyer focusing
that control, so an alt-tab back onto a control is classified like any other
arrival. Opening the popup blurs whatever held focus for exactly that reason.
A popover left on screen around a document focusing nothing reaches no keystroke
at all, so the launch parks that focus on the company field one tick later.
That one control is exempt from the rules above for the whole
flight: the window losing focus to the popup blurs the field, and a buyer
clicking back into the checkout would otherwise end their own enrolment. The
field's own focus opener is held for the same span, however many times the
buyer moves between the popup and the checkout, and only the window return
after the popup has gone releases it (TWO-26775). Focus arriving on the Sole
trader chip alone moves nothing; only a click, or Enter or Space on it, raises
the popup.

**The close is only abandonment while the checkout is still in sole-trader
mode**. The popup's close is noticed by a 300ms poll, so a chip the
buyer activated while the signup was up has already written the mode it asked
for by the time the poll runs — and a poll that returned to registered search
regardless swallowed that action. Manual entry was the visible loss: the field
came back a registered-search combobox with the registered chip pressed.
The poll that first finds the popup closed waits one more tick before it
abandons, so an `ACCEPTED` the popup posted as it closed, still queued behind
that tick, wins (TWO-26775).

**Closing the signup with nothing captured clears nothing** (TWO-26775). The
capture is still the one from before the Sole trader click, which itself
clears nothing either, and the mode follows it back: `restingMode()` answers
Sole trader for an adopted one, else the mode the click left. That mode is read
off the click rather than off the name and number, because a manual name and a
registry hit with no number look the same. Leaving a sole-trader mode that
adopted nobody, by any chip, keeps the company too; only an adopted sole
trader is discarded on the way out. Every popover close settles a sole-trader
mode with no popup, no flight and no adoption the same way, so a dismissed
blocked-popup panel cannot leave the chip selected over nothing.

**A hand-close closes the popover and gives focus back, but only where focus is
still unplaced**. A close that left `document.activeElement` on the body or
nothing at all has nowhere for the buyer to be, so focus goes to the control the
signup was launched from while it is still on screen (the "Select a different
sole trader" link), and otherwise to the company field (guide row 1.39). The
flight's own park is dropped before that is read, so what it judges is the
unplaced focus the launch left. A close by one of the rules above
(`closeSignupPopup({ byRule: true })`) is not a hand-close: the popover and
focus stay where the buyer's press or focus put them. A launch forgets how the
previous popup closed, so its own hand-close is never misread as a rule close.

**A blocked popup gets a panel over the popover, never a link** (TWO-26775). When
`window.open` returns null the popover (opened first where it is closed) is
covered by one centred button, "Sign up or log in as a sole trader", whose
click is a fresh gesture a blocker allows. The button keeps its own width,
centred inside the popover's padding. Focus moves to the button, the rest of
the popover is `inert` underneath, and the panel is a plain container with no role
or name of its own: the caption is said once, as the focused button's name. The
panel's live region exists from the moment the panel attaches and stays empty
here, so nothing says the caption twice. A chip click, any mode change and the popover's close take
it down, and so does a launch that opens.

**After a signup reports ACCEPTED, the buyer lookup has three outcomes**
(TWO-26775). A buyer with a number is adopted. A failed lookup shows the error
and, the close watcher having stood down for it, runs the abandon itself. A
lookup that finds nobody, or a record with no number, asks again as PrestaShop
does: the payment-tile mount retires the finished popup's watchers and shows the
blocked-popup panel, the address-step mount opens a second popup, which a
blocker turns into the same panel.

**An autofill is adopted by PrestaShop's rules, on every adoption** (TWO-26775).
The click adopts a held answer without a popup only where its email matches
the checkout's (a signed-in buyer's own email where the quote carries none). A
record with no number is refused whole and writes nothing. The name and number
are overwritten, except that a blank name keeps the name in the field. A blank address value or phone writes nothing,
and a field the record leaves out is cleared only where it still holds the
plugin's own marked write. The region is written only into an empty or
plugin-written field. Country, email and the buyer's own names are never
written.

**A signup open anywhere on the checkout owns focus, and that is what the close
watcher asks.** Handing the popup over to another capture launches that
capture's signup, and that launch blurs its own chip — so focus at the close is
unplaced either way and says nothing about who owns it. The watcher instead
polls every live flow for an open popup: one still up is the buyer's place to
be, and the abandoning capture leaves it alone. A handover to a capture that
adopts an autofilled sole trader raises no popup, so the reclaim stands.

Read at the close, never latched at the handover: a receiving signup the buyer
has since closed owns nothing.

**A mode change places focus again, wherever it took it from.** The chip row is
rebuilt from scratch, so activating a chip deletes the node the activation
arrived on; and a mode that withdraws the query row hides the input the caret
was in, which is what a pointer buyer's chip click leaves focus in, since the
chip's own press cancels the native focus. Either way the buyer ends up on the
body.

**It asks whether the NODE survived, never where focus is now**, and that is the
part a reclaim written against `document.activeElement` gets wrong: a browser
does not blur the caret out of a hidden row until it restyles, which is after
the handler that hid it, so focus still reads as the input inside the sync. A
chip-only rule missed the case entirely, because a pointer buyer's focus was
never on the chip. Focus is placed again only where the sync itself took the
holder away, so focus the buyer put elsewhere is left alone: inside the panel
where it is still open, on the company field where it is not.

The reclaim decision is read BEFORE returning to registered mode, which can
remount the panel and so unplace focus the buyer had put somewhere themselves.

The panel's own restore leaves its open state alone, which takes cancelling the
pending focus-out close, since the company field sits outside the panel node and
arriving on it otherwise reads as leaving the control.

**Focus arriving on ANOTHER capture's Sole trader chip hands the popup over.**
That chip is a different control, so this popup and popover close first; the new
one is then raised by invoking that chip's own click handler, the single place a
launch is spelled out. The exemption is per capture and survives a re-render
because the popover is resolved live as the field's sibling — a stored popover
node goes stale when a morph deletes the wrap and keeps the field, which makes a
capture's own rebuilt chip read as a sibling's and inverts the rule on it
(TWO-25658).

**The POINTER route reaches none of this.** A chip's `mousedown` cancels, so a
real click fires no `focusin`: a buyer clicking a second capture's chip with the
mouse can hold two signup POPUPS open at once. Closing that means changing the
chip's click path, not the focus rule. The popover is a separate matter — its
single-open invariant is enforced at open time and does not depend on these
rules.

## Enter manually keeps the name and drops the number everywhere

Enter manually leaves the current name in the field, editable, and keeps the
address (TWO-26775). The number goes from every place it could reach the order:
the identity; the address step's `company_id` and the `companyData` section,
which `address-autocomplete.js` republishes once as manual entry starts (not
per keystroke, which would fire order intents); and the quote address, whose
`company_id` `gateway_method.js` no longer writes back into a manual identity
from `fillCompanyData()` or `updateBillingAddress()`. A billing panel in manual
entry resolves as itself, never falling back to the shipping panel's number.
The component no longer calls a host's `clearField`: a host that blanked the
field there would undo the kept name. A saved address-book entry still carries
the number of its last Two order, and the buyer's address book is left alone:
instead `company-capture.js` blanks `company_id` on every quote address a
manual panel speaks for, and records the manual name per panel in
`sessionStorage`, so a reload re-enters manual entry before the renderer reads
the saved address. The record stands while manual entry is the panel's resting
mode, a pending Sole trader click included, and goes when a registered pick or
an adopted sole trader replaces it. It belongs to one quote and one customer:
a placed order, a login or a logout moves on from it, and the next checkout
drops it rather than restore another checkout's name. Order placement itself reads the number only from the
payment's additional data, never from an address.

## The company relay resolves an ISO 3166-2 region to the store's own

The company lookup answers an address region as an ISO 3166-2 code ("ES-M",
"IT-RM") for some countries, which no region select is labelled with. `CompanyLookup::get()` passes
each address through `Service\Address\IsoRegionResolver`, which adds
`region_id` and `region_code` from the store's own region rows when the code
resolves, and leaves `region` exactly as answered (TWO-26263). Both checkouts
select `region_id` when their select offers it, and otherwise fall back to
matching the region text, where an unmatched own-country code is left out of
the city (TWO-26258).

The match is against the live directory rows, so a merchant's edited region
list is respected. Only a code for the address's own country is considered,
then the whole code, then its suffix (numeric suffixes as numbers, for France).
Released core codes that are neither (all of ES, DE, AT and FI; some of FR,
EE, CR, IS, IN, CO and LV) resolve through a table holding every pairing in
core's own `UpdateRegionCodesFor<Country>V1` patches that those rules miss,
plus the ES-IB code those patches give the Balearic province. IN-DH is left
out: it is a merged territory, and core's older "DN" is only half of it, so a
match would select the wrong region for the other half. A core region
the patches leave alone has no current ISO code (abolished Italian, Indian and
Latvian units, US military and Pacific codes).

Registries outside Spain do not all answer ISO codes: Italy's answers a bare
province code ("RM") and France's a region name. After the ISO rules, and still
within the address's own country only, the resolver takes a value equal to
exactly one store region code, then a value equal to exactly one store region
name (`RegionDirectory::namesForCountry()`: the default name and the store
locale's name), both case-insensitively and the name ignoring accents
(TWO-26266). A value two regions share selects neither: the US military "AE"
code, and on a store carrying core's recoded Spanish rows the name Cantabria,
both a province (ES-S) and a community (ES-CB); the recoding renames the other
communities ("Madrid, Comunidad de"), so their provinces keep a name of their
own. Checked over core 2.4.9 and develop region data, with core's renames
applied: every region's own code and name resolves to itself or, for those
shared ones, to nothing, no code equals another region's name, and every
ISO-code result is unchanged. A French region name such as "ILE DE FRANCE" names
no row in core's French list, which holds departments, so it still resolves to
nothing. Anything else resolves to nothing and the address is relayed as before;
do not add a guess or a fuzzy match, because a wrong region is worse than none.
Both checkouts never append a region they selected to the city; one that
resolved to nothing is appended as before.
