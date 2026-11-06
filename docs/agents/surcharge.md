---
description: 'Surcharge cap rules (zero cap, merchant fixed-surcharge cap) and 2dp rounding of the pricing request.'
---

# Surcharge caps and rounding

## A surcharge cap of 0: refused at entry, relayed faithfully at runtime

Two rules that look contradictory and are not. Keep both.

**Runtime — never guard.** A configured surcharge limit of `0` that
somehow exists must be relayed to the pricing API as `cap => 0.0`.
Do not throw, do not omit the key, do not turn it into "no cap".
A zero cap bounds the buyer fee at zero — no surcharge is applied —
and only an _absent_ (null) limit means "no cap", which omits the
`cap` key and applies the percentage uncapped. Absent and zero are
different values and both pass through faithfully.

TWO-25269 briefly added a guard that threw on a zero cap, on the
premise that a zero cap read as "no cap" downstream and would relay
an uncapped percentage. **That premise was false and the guard was
reverted** — a zero cap bounds the fee at zero, it never uncaps it.
In `fixed_and_percentage` mode the cap bounds the combined fee, so
`Limit = 0` suppresses the fixed component too, not just the
percentage part.
`Test/Unit/Service/Order/SurchargeCalculatorTest.php` pins this.

**Admin — refuse zero.** Separately, TWO-25289 stopped a zero limit
being _configurable_: `Model\Config\Backend\SurchargeGrid` rejects
`limit === 0` on save, and the grid refuses it in the browser too.
An EMPTY limit stays valid and still means "no limit".

This is not the reverted guard under another name. It is an
admin-boundary decision rather than a runtime one, and the reason is
different: a merchant who wants no fee on a term says so directly
with 0% and 0 fixed, so a zero limit has no legitimate use — while on
the sibling plugins a zero cap was being normalised to _absent_ and
relayed genuinely uncapped, overcharging the buyer. Refusing it at
entry closes that consistently across all three plugins.

**Read path — junk is absent, zero is not.**
`Model\Config\Repository::getSurchargeConfig()` no longer casts the
stored limit blindly. The admin grid refuses junk, but the stored value
can still arrive from a hand-edited row, `config:set` or an import, and
a bare `(float)` cast turned `abc` into a hard cap of 0 (suppressing
the fee) and `-10` into a negative cap (refused upstream, so the buyer
sees a generic failure). Non-scalar, empty, non-numeric, non-finite and
negative all resolve to NULL — absent, i.e. no cap. A genuine `0` is
still relayed verbatim, because a zero cap clamps the fee to zero and
that is a different instruction from absence. Same shape as the sibling
plugins.

So: if you are asked to remove the admin validation, that is the
runtime rule being misread. If you are asked to make the runtime
throw on a zero cap, that is the reverted guard being reintroduced.
Neither follows from the other.

## The merchant's fixed-surcharge cap is enforced where the fee is CHARGED

**Not the cap the section above is about.** That one is the per-term **Limit**
column: a merchant-configured ceiling on the fee, relayed to the pricing API as
`cap` and never guarded at runtime. This one is `surcharge_limit_amount` and
`surcharge_limit_currency` on the merchant record — the largest FIXED surcharge
the merchant is permitted to configure at all. Neither rule transfers to the
other.

**The admin form is the early warning, not the guarantee.**
`Model\Config\Backend\SurchargeGrid::validateValue()` refuses a fixed amount
above the cap and that refusal stays. It cannot be the only enforcement, for two
reasons neither of which is fixable at the form:

-   The per-term paths
    `payment/<code>/surcharge_<days>_{fixed,percentage,limit}` are **not
    declared in `system.xml`** — that backend model writes them itself, and
    `surcharge_grid` is the only declared field. They therefore carry no backend
    model at all, so a `config.php` import or a direct `core_config_data` write
    applies one with no plugin code running. Magento's importer invokes only
    `beforeSave()`/`afterSave()` on a declared path's model and never supplies
    the posted `groups` the grid reads, so even a save that does reach this model
    validates nothing.
-   The cap lives on the merchant record and is refreshed by cron, so it can be
    **lowered under an amount that was within it when saved**. No write happens
    at that moment, so no write-time check of any kind can ever observe it.

**`SurchargeCalculator::capFixedSurcharge()` is the guarantee.** It bounds the
converted `surcharge` member in the order currency, immediately before the
pricing request carries it, so no stored value and no write route reaches a
buyer above the cap.

**A reduction is always reported.** The admin grid goes on showing the
configured amount, so reducing the fee silently would be a fresh defect rather
than a fix: the clamp error-logs the configured amount, the cap, the currency
and the term. Do not quieten it to a debug line, and do not "fix" any of this by
removing the cap, the setting or the field.

**A cap no rate converts refuses to price.**
`SurchargeCapProvider::inCurrency()` reports that case as `exact => false`
rather than handing back a figure in the wrong currency, and the charging path
raises instead of guessing — an unconverted ceiling quoted in a weaker currency
would admit a fee many times the real cap. `isSurchargeResolvable()` reports it
as well, so the method is withheld ahead of pricing and the buyer meets an
unofferable method rather than a checkout error. The admin form does compare
against that unconverted figure, which can only refuse MORE than the real cap
would; that asymmetry is deliberate, and is why one resolver serves both sides
with the distinction exposed rather than buried.

Both sides read the cap through `SurchargeCapProvider`, so the arithmetic cannot
drift: the cap is truncated to whole units, and a converted figure is rounded
up. An amount the form accepted must never be reduced when the buyer is charged,
and that only holds while a single implementation answers for both.

## Monetary values in the pricing request are rounded to 2dp

`SurchargeCalculator::convertAmount()` rounds `cap` and `surcharge` to
two decimal places before they go on the wire. The API refuses
anything finer rather than rounding it itself, so an unrounded FX
conversion used to be rejected upstream and surface to the buyer as a
generic "temporarily unavailable" error.

Plain half-up rounding, deliberately. Sub-cent caps, away-from-zero
rounding and zero-decimal currencies are all explicitly out of scope
(TWO-25289).

That is safe for anything a merchant can **configure**, because the
grid refuses any limit that rounds away at 2dp — not just an explicit
`0` but anything under half a cent. So the rounding direction never
decides whether a configured cap survives.

What it does **not** cover is an FX conversion landing under half a
cent: that collapses to `0.00` and suppresses the fee. Accepted, not
overlooked — pinned by
`testASubCentCapRoundsDownToZeroWhichIsAcceptedScope` so it reads as a
decision. Do not "fix" it with away-from-zero rounding without
reopening the scope question.

Also note the zero rule is **skipped, not applied and not deleted**, on
the Limit column when the surcharge type has no percentage component.
The grid JS hides that column, but a hidden input still posts, so a
limit stored under an earlier percentage type keeps arriving. Rejecting
a zero there would fail the whole section save over a cell the admin
can neither see nor clear, so the rule is skipped and the cell is
stored exactly as posted.

Do not "tidy" that into deleting the cell. Deleting discards a VALID
limit on any save made while the surcharge is fixed-only or off — a
normal round trip — while the equally inapplicable percentage cell
survives it; and at a non-default scope deleting an override does not
retire a value at all, it re-exposes the parent's. A legacy zero simply
surfaces again when the column comes back into view, which is where the
admin can act on it. The visibility flag is threaded from `afterSave()`
into `validateValue()` and pinned there by
`testProductionAfterSaveWiresTheLimitColumnVisibilityIntoTheZeroRule`.

A term the grid does not render at all — one deselected from "Payment
terms" — is a different case, because no cell for it is POSTED and the
per-cell rule therefore never sees it. `assertNoStaleZeroLimits()` scans
those stored rows at the scope being saved whenever the Limit column
becomes live, and refuses the save naming the terms. That is not the
dead end above: reselecting the term brings its cell back into the grid,
where it can be cleared. The scan runs before the write loop so a refusal
leaves nothing half-applied.
