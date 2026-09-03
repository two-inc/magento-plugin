# Magento Plugin (Two_Gateway)

Two's Magento 2 BNPL payment plugin. Brand-aware single-module
extension; brand-specific identity values resolve through
`Two\Gateway\Api\BrandRegistryInterface`. The default DI
binding in `etc/di.xml` resolves to
`Two\Gateway\Brand\DescriptorBackedBrandRegistry`.
Building a partner overlay or adding a brand-driven field:
see docs/brand-overlay-guide.md.

Standard Magento dev workflow: composer install, bin/magento
setup:di:compile, setup:upgrade, cache:flush. PHPUnit under Test/.

This is a **public repository**. Do not commit session-specific
content such as plans, transcripts, or implementation notes.

## Branching & releases

-   **Day-to-day PRs target `staging`** (the GitHub default and the
    staging shop's deploy branch); branch off `origin/staging` —
    `version-bump.yml` decides the release version on PRs landing there.
    `auto-pr.yml` opens the staging → main promotion PR on every push to
    `staging`; `main` is prod. `merge-back.yml` syncs `main → staging`
    after merges (ff-only, else a sync PR). There is no `develop` branch.
-   **Releases are automated** — `release.yml` runs on CI success on
    `main` and does not compute a version: it reads the version committed
    by `version-bump.yml` on the PR that landed on `staging` (bump level
    from that PR's own conventional-commit types: `feat!:` → major,
    `feat:` → minor, else patch), tags it, and creates the GitHub Release.
    Don't hand-run bumpver.
-   `bumpver.toml` `current_version` MUST equal the version strings in the
    files it patches (`composer.json` `"version"`, `etc/config.xml`
    `<version>`) or every release dies at the bump step with "No match for
    pattern". Both fields are functional (rendered by the adminhtml Version
    field) — keep them.
-   **Re-cutting an exact version after deleting its tag:** deleting the
    tag + Release is not enough — reset `current_version` to the highest
    surviving semver tag first, or the next release overshoots (prev-tag +
    accumulated commits can bump past the intended version). Deleting +
    recreating a tag that has a Release demotes it to a draft — repair with
    `gh release delete` + `gh release create --verify-tag`.
-   Packagist syncs off this repo's webhook. If a tag doesn't appear on
    Packagist, check `gh api repos/two-inc/magento-plugin/hooks`
    `last_response.code` — 403 means stale Packagist-side authorization for
    the package (fix on Packagist, not GitHub); redeliver the hook to
    confirm.

## Local-dev modules disabled by `make install`

`make install` disables PageBuilder and the Analytics module family
in the local Docker container so that `setup:di:compile` and the
storefront's RequireJS bootstrap stay fast. See README's
"Local-dev perf" section for the full list, the rationale, and the
re-enable recipe.

**If a future enquiry surfaces along the lines of "why isn't this
PageBuilder banner / promo block / CMS slide rendering in my local
build", the answer is almost certainly that `Magento_PageBuilder` is
disabled by `make install`** — point them at the README section,
which includes the commands to re-enable PageBuilder for testing
brand content that relies on it. Same applies to anything
analytics-driven (e.g. NewRelic dashboards, GA events).

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

## The custom-header table

`custom_headers` (Diagnostics → Admin controls) lets the merchant send any
number of named HTTP headers on calls to the Two API, each with its own
"also send from browser" tick. It replaced a single `firewall_token` field
plus a browser toggle.

**There is deliberately no data patch.** Those fields never reached `main`
on any platform — only `staging` — so no merchant ever had one configured
in production and there is nothing to carry over. Do not add one on the
assumption that stored values exist.

`Model\Config\Backend\CustomHeaders` is the entry gate and owns the stored
format. It refuses an empty name, an empty value, a duplicate name, and a
name outside the RFC 7230 token charset. Two further rules are worth
spelling out, and every rule below plus the name charset is re-applied on
the read path in `Model\Config\Repository`, so a value from `config:set` or
an import cannot bypass any of them:

-   **Values are printable ASCII** (`^[\x20-\x7E]+\z`), refused at save
    with a message naming the rule. CR/LF is a response-splitting sink,
    other control characters a log-injection one, and non-ASCII is
    ambiguous on the wire. The pattern ends `\z`, not `$` — `$` matches
    before a final newline and would let exactly the worst byte through.
    A value is trimmed of spaces and tabs ONLY, so a stray control byte
    survives to be named rather than silently stripped.
-   **21 header names are reserved**, matched case-insensitively and
    exactly (a prefix like `X-Upgrade-Path` is the merchant's to use).
    Five groups: names the integration sets itself (`host`,
    `content-type`, `content-length`, `accept`, `accept-language`,
    `x-api-key`, `two-delegated-authority-token`); the proxy identity the
    checkout rate limiter resolves callers through (`x-forwarded-for`,
    `x-real-ip`); RFC 7230 hop-by-hop headers, which govern connection
    handling rather than request content so a value here malforms the call
    (`connection`, `keep-alive`, `proxy-authenticate`,
    `proxy-authorization`, `te`, `trailer`, `transfer-encoding`,
    `upgrade`); transport negotiation the HTTP client owns, where a
    merchant value breaks every response parse or the request handshake
    (`accept-encoding`, `expect`); and the generic credential carriers
    (`authorization`, `cookie`).

`Service\Api\Adapter` case-folds when merging, so a differently-cased row
cannot add a second conflicting `X-API-Key` even if one were stored.

**A browser-ticked header must already be allowed by the API on
browser-originated calls**, or the one direct call the browser makes
fails CORS preflight and the sole-trader autofill silently finds no
buyer. The field help says so; nothing enforces it.

## An optional constructor argument is NOT autowired

A constructor parameter with a default of `null` is left at its default by
the object manager — it is never resolved from its type hint. Adding a
dependency that way and relying on DI to fill it in gets you a silent
`null` at runtime while every unit test (constructor skipped) still passes.
`bin/magento dev:di:info <class>` reports it as `"_vn_": "string 1"`
(value null) instead of `"_i_"` (instance); that is the check.

`Service\Order::$orderTaxManagement` and `Service\Order::$feeLineProviderPool`
are both declared optional for constructor BC and both named explicitly in
`etc/di.xml` on the abstract parent, which all four `Compose*` subclasses
inherit.

## The order/tax composition path never derives a tax rate from amounts

A line's `tax_rate` is whatever the store's tax engine declared for that
line, relayed verbatim. `tax / net` is a different statement: rounding,
combined rates and a discounted base all put the quotient on a rate no tax
rule declares, and Two validates the declared rate against the line's own
amounts.

(`Service\Fee\Provider\AmastyExtraFee` derives its own rate this way, but
that provider requires a persisted order id and never runs from the
validated placement path — see the DI section below.)

Product lines read `tax_percent` off the item. Shipping has no such column,
so `getTaxRateShipping()` reads the shipping-typed entry out of the order's
`item_applied_taxes` extension attribute and sums its applied taxes, falling
back to `OrderTaxManagementInterface::getOrderTaxDetails()`. The extension
attribute is what makes this work at PLACEMENT time: composition runs from
`Two::authorize()` inside `Order::place()`, before the order is saved, so it
has no entity id and the `sales_order_tax_item` rows the management interface
reads do not exist yet. That interface stays the source for the post-save
consumers (capture, refund).

Nothing declared and no shipping tax charged is 0% — a store whose shipping
is untaxed records no tax row at all, and 0% is a statement rather than a
guess. Nothing declared but tax charged consults the "Default Shipping Tax
Rate" admin field, and with that unset the order is refused rather than
given an assumed rate.

`validateTaxReconciliation()` closes the same loop at composition time: a
line whose declared tax does not follow from its own declared rate and net
declines the checkout with a generic buyer notice. It never corrects the
numbers. The tolerance is not a flat 0.02 — it carries a per-unit term for
the "Unit Price" tax algorithm (which rounds per unit and sums) and a small
fraction-of-net term, and a discounted line may reconcile against
`net + discount` as well as `net`, because "Before Discount" tax calculation
taxes the undiscounted base.

## DI registration scope for Structure / Config Reader plugins

**Plugins that target `Magento\Config\Model\Config\Structure\Reader`
(or any class whose output gets cached under an area-specific cache
key like `adminhtml::backend_system_configuration_structure`) MUST
be registered in `etc/di.xml` (global), NOT `etc/adminhtml/di.xml`.**

Reason: CLI invocations of `bin/magento` (`config:set`,
`app:config:import`, `deploy:mode:set`, `admin:user:create`, etc.)
populate the adminhtml-scoped Structure cache but bootstrap with
the CLI process's DI graph — which loads `etc/di.xml` +
`etc/crontab/di.xml` and does **NOT** load `etc/adminhtml/di.xml`.
Plugins registered only under adminhtml therefore never fire for
CLI-driven cache writes; the cache lands incomplete, and subsequent
admin web requests read the broken cached Structure from
`Scoped::_loadScopedData`.

This is exactly how the admin-tab-vanishes-after-pod-restart bug
happened — `SynthesiseBrandAdminForm` was originally
registered under adminhtml; every CLI command in the init/setup
hooks repopulated the cache without invoking synthesis.

If your plugin's `afterRead` body only mutates the adminhtml shape,
firing in other areas is harmless (wasted parse on a payload no
consumer reads). The cost of registering globally is essentially
zero; the cost of getting this wrong is a recurring restart-time
production bug that masks itself behind cache-flush workarounds.

The inverse trap applies to `etc/crontab/di.xml`: a plugin registered
ONLY there fires in CLI processes (cron, indexer) but NOT in HTTP
requests. If you find yourself reaching for crontab-scope DI, ask
whether the symmetric case (HTTP request misses the plugin) would
break correctness — almost always yes; register globally instead.
