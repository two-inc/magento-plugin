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
content such as plans, transcripts, or implementation notes. In code
comments, commit messages and PR bodies alike, cite a Linear ticket id
and nothing else: a section, question or ruling number belonging to an
internal review document means nothing to a reader outside the company,
and neither does a person named as the authority for a rule.

## Branching & releases

-   **Day-to-day PRs target `staging`** (the GitHub default); branch off
    `origin/staging` — `version-bump.yml` decides the release version on PRs
    landing there.
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

## Which shop tracks `staging`

**`magento-dev.staging.two.inc` is the shared shop that serves this branch**, its
deployment carrying a `git-sync-gateway` container; each brand's own dev shop
git-syncs this repo's `staging` too, alongside that brand's overlay.
`magento.staging.two.inc` has no git-sync container at all and serves the deployed
image's code, which tracks `main`.

**Anything that verifies `staging` code targets the dev shop** — e2e, a manual
click-through, a screenshot. Point it at the other shop and it silently reports
on `main`: the run stays green for as long as the two branches happen to agree
and turns red, at the first specification that moved, against a storefront still
serving the widget the branch deleted (ABN-509). Confirm which code a shop has
from the git-sync container's checked-out HEAD; `pub/static/deployed_version.txt`
answers with an HTML 404 page on these shops and settles nothing.

A merge to `staging` triggers an in-place static redeploy on the dev shop and
the storefront 500s for roughly three minutes, so a suite that starts mid-sync
fails for environmental reasons. Warn testers before merging.

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

## Admin settings fail loud: an unrecognised stored value is never priced

The standard for EVERY admin setting, not only the surcharge method.

**Save refuses it.** A value outside the field's known set is rejected by the
field's backend model. A crafted POST, a hand-edited row, `config:set` or an
import therefore cannot leave behind a value nothing understands.

That holds for a path DECLARED as a field in `system.xml`. A path some other
backend model writes has no model of its own, so nothing runs on it — see the
surcharge grid's per-term cells in the section above, where the read path
carries the guarantee instead.

**Read paths raise.** The config repository is the single choke point for the
runtime read: it maps only the explicit unset key to the field's default and
raises a `LocalizedException` for anything else. Callers that price a fee or
build an order let that raise.

**Gates and totals collectors catch it.** The availability gate withdraws the
Two payment method and nothing else; the totals collector clears its own
segment and returns. Every other payment method, and the rest of checkout, is
untouched. The repository reports the offending value once per request, so the
catchers stay quiet.

**Buyer copy stays generic.** The buyer sees the existing "not available for
this order" wording. A setting name, a stored value or an enum key never
reaches the storefront — those belong in the log and in the admin field's own
validation message.

Degrading a junk value to a working default is the failure this replaces: it
prices an order under a configuration nobody chose, and nobody is told.

**An unresolvable merchant record does NOT withhold the payment method**
(ABN-519). A record fetch that 5xxes, times out or finds the host unreachable
says nothing about whether the API key works, and only the api-key verification
verdict may take the method off the storefront. The record's consumers each
degrade to their own "nothing configured" behaviour instead: the read path
offers no buyer term, and order composition refuses to fall back to the nominal
default term rather than pricing an order under terms nobody granted (ABN-495).
So a buyer can reach placement and be refused there — accepted, and the cost of
never hiding the method for a reason unrelated to the key. A 200 carrying no
merchant record counts as unresolved: a proxy, a captive portal or a maintenance
page answers 200 too.

**The admin save stays permissive, and a rejected key blocks only the key field.**
Refusing the save would lock the merchant out of correcting the very key that
resolves the record, and a `LocalizedException` from a config backend model rolls
the WHOLE section back — one mistyped key would discard every unrelated field
submitted with it. The key field turns off its own save through `_dataSaveAllowed`
and reports the rejection through the admin message channel, so the rejected value
is never stored and every sibling field still saves. Only a definitive upstream
rejection is blocking; unreachable, erroring and malformed verdicts save the
submitted key.

**A cached merchant record is keyed on the ENVIRONMENT as well as the API key.**
One key configured against sandbox on one store view and production on another must
not share a slot, or a store view serves the other environment's merchant.

**An admin surface reads the record at the scope its form is editing** (ABN-530).
The API key field is website-scoped, so a website carries its own key and its own
offerable terms; flattening a website form to a store id judged the edit against
the default scope's merchant and silently dropped a stored term. Config reads take
the `(scope id, scope type)` pair `Model/Config/AdminScope` resolves, never a store
id alone. A website is never resolved through one of its stores: the child may
override the key, which makes the answer depend on which child was picked.

**The record entry has NO expiry.** The scheduled hourly refresh is the only
thing this module lets replace it (a cache backend under a memory-pressure
eviction policy is its own matter), so a key that stops verifying costs the
merchant nothing beyond the buyer-facing payment method: every admin control the
record drives keeps rendering indefinitely (ABN-519). The motivating case is a
merchant running two shops who rotates their key and updates only one — the
forgotten shop must lose the tile and nothing else, however long the key stays
wrong. Do not reintroduce a TTL on the record or its success stamp.

**Freshness is the stored success stamp, and staleness is never a verdict.** The
cron refreshes a record older than 24 hours. A record that reaches 26 hours says
the cron is not running, so a read stands in for it — one attempt per hour, the
held record returned either way, nothing withheld and no buyer told. A fetch is
bounded at 10 seconds, so a caller with its own wall-clock budget — a config save,
the admin refresh button, a storefront render — can hold to it. A failed fetch is
never cached as the record and never moves the stamp: last-known-good is served and
re-fetch is bounded, so an outage is not a fetch per read. The admin health
checklist reports both an absent-on-read mark and a stamp the record has outlived.

**That last-known-good DOES keep the method on offer through an outage** (ABN-533).
The availability chain reaches the api-key verification verdict before it reaches
the record, and only a DEFINITIVE rejection there withholds: `invalid_key` (Two
said no) and `not_configured` (there is no key). `unreachable`, `service_error`,
`error` and `malformed_response` fall through to the record, which never expires,
so a correctly configured shop keeps serving buyers for as long as the outage
lasts. `ApiKeyStatus::isDefinitiveFailure()` is the single definition of that set —
do not re-list the categories at a gate. No admin surface is gated on the verdict
at all; the one place it blocks an admin action is the api-key field refusing to
store a key the API definitively rejected, and the health checklist still reads
"Not verified" on every category short of a verified key.

**A cache type absent from `env.php` resolves as DISABLED**, and `cache.xml`
carries no default-state attribute, so an install has to write the state itself:
a data patch enables every type this module declares, on a fresh install and on
upgrade alike, so there is no manual enable step. It runs once, so a merchant who
later turns the type off keeps it off.

**What a disabled type breaks is the type-scoped CLEAN, not the caching.** The
records themselves resolve to the framework's default frontend and read and write
either way; `cache:clean two_gateway` and the admin cache-management row are what
stop working, and they report success while dropping nothing. Declaring the type
is what makes a targeted clean possible at all — a config clean does not touch
these records.

**The admin fee column is a cached last-known-good set too** (ABN-512). The fee
beside each payment term is fetched live per render, and an empty fee means that
term carries no fee — so a failed fetch may never leave the column blank and
silent. The last set retrieved for that merchant, buyer country and term list is
kept with no cache expiry and served instead, with the screen saying it could not
be refreshed and when it was retrieved. With nothing cached at all the screen says
the pricing service could not be reached. Do not restore a bare
`{success:false}` that the browser swallows.

## The order `isAvailable()` withholds in, and it is SILENT

Core's own checks; a configured non-empty API key; the api-key verification
verdict; the surcharge FX rate resolving and the stored surcharge method being
recognised; the buyer country; the fee quote for the term being charged; then an
Amasty store view returns true early, deferring only the minimum-order gate to
the client; then the platform and merchant minimum-order gate.

**The api-key verdict is the gate the ruling puts that power in** (ABN-519), and
only its definitive-rejection categories withhold (ABN-533). Do not widen it
back to every failure category, and do not add a further gate that withholds
because a call to Two failed; both are defects the rule exists to stop coming
back. The buyer fee quote is the one named exception (ABN-546): a term whose
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
outage runs (ABN-535).

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

## A field declared only in `system.xml` reaches no brand — nor does its model

Every admin pane is rendered from fields synthesised out of
`brand_form_template.xml`, and that deep merge only carries fields the template
already declares — so a field added to `system.xml` alone is dropped silently and
renders on no brand at all. `DiagnosticsSectionParityTest` compares the two field
lists for the Diagnostics section.

**The same applies to each field's `source_model`, `backend_model` and
`frontend_model`, and it fails far more quietly.** An overlay install renders ONLY
the synthesised sections (`HidePaymentSection` hides the static Two ones), so a
model wired in `system.xml` alone is a save-time guard, an option list or a field
renderer that exists on a Two-only install and on no overlay — the invariant holds
everywhere it is tested and nowhere a partner runs. That is how ABN-497 shipped a
surcharge tax treatment that could not be saved blank on Two and could on every
brand. `BrandFormModelWiringParityTest` compares all three slots across every
shared section and is the guard against the next one.

**A configured payment term is validated against the set the merchant is
entitled to offer**, in the field's backend model and again where the read path
intersects the stored set — `config:set` bypasses a backend model. The
payment-terms type selector is rendered only for a merchant already set to end
of month (TWO-25656); a merchant not on it is not offered it.

**With nothing offered there is no default term.** `getDefaultPaymentTerm()`
returns null rather than a day count, and no caller substitutes one: the term
set is offered empty and the buyer is refused at order placement rather than at
selection (ABN-544).

**The default term prefers 30 days.** `getDefaultPaymentTerm()` resolves the
admin's stored default, then the merchant record's own default term, then 30,
then the shortest offered term — each honoured only while it is in the offered
set (ABN-548). The differential surcharge baseline reads the same resolver, so
the reference term it prices against moves with the preference.

**Nothing but the admin synthesises a stored default term.** The field's first
option is Automatic — an empty value — and neither the field nor its JS ever
puts a day count in the select on the admin's behalf: the select posts on every
save, so one synthesised there is stored, becomes the resolver's first step, and
makes every later step unreachable on that scope. A stored term the merchant
withdrew has no matching option, so the select reads Automatic.

**The admin surfaces that NAME the default term resolve it, never read the
select.** With Automatic selected the select carries no day count, while the
surcharge grid still has to disable and zero the row differential mode prices
against and the differential option still has to name it. `SurchargeGrid` and
`Two_Gateway/js/default-term` each apply the resolver's order — the JS from the
ticked terms plus the merchant's own default term, published as
`data-merchant-default-term` on the checkboxes container. Reading
`default_payment_term` alone badges no row at all wherever the admin left the
choice to the resolver.

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

## The company-search panel is ONE module, vendored twice

`view/frontend/web/js/model/company-search-panel.js` and the WooCommerce
plugin's copy are BYTE-IDENTICAL. `EDIT_LOCK_SHA256` in
`Test/Js/company-search-panel-vendored.test.js` fails this suite on any edit to
the file that did not move the digest with it, and the other plugin's suite
holds the same digest for its copy. **Two matching digests are the parity
check**; two different ones are the drift, and comparing them is the one thing
either repo can do alone (TWO-25503).

**A change to shared panel behaviour is TWO edits in ONE change set**: edit here,
apply the identical edit there, re-run both JS suites, and move both digests. Re-copying
the whole file is NOT a way to re-sync: once the copies differ it reverts whatever only
the target side held, and while they agree there is nothing to copy.

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
buyer there with no way to name their company at all (ABN-525).

**The chip row is shown whenever it offers a mode the buyer is not already in**,
not merely whenever it holds two chips. A lone chip for the current mode is no
choice; a lone chip for a different mode is the buyer's whole way out.

**The company field opens the panel on FOCUS**, through the same `open()` a
mousedown runs, which puts the caret in the panel's query field — or on the first
offered chip where the query row is withdrawn, so no mode opens the panel with
focus nowhere. Same state a click leaves it in, and the same on every platform
that carries this control.

**With the search withdrawn, a printable key keeps the caret on the company
field.** The panel opens onto a chip there and a chip is a `<button>`, which
swallows text, so every character the buyer typed was lost with nothing on screen
to say so — the field's own `input` handler, which leaves those keystrokes where
they were put, never ran at all (ABN-554). Space and Enter are excluded: both
activate the focused chip.

**Closing the panel puts focus back on the company field** — Escape, a pointer
press outside, a company adopted from the results, manual entry taking the field
over, or the capture controller closing it when the hosted signup answers
(ABN-554). The field's own focus opener is held off for that one programmatic
focus alone, so any keydown on the field, a click on it, or focus arriving from
anywhere else brings the popover straight back; a flag left set is a popover that
never reopens.

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
dismissal key on a node the panel's handler never sees (ABN-554).

**A press on the panel's own dead space is a no-op.** Its default action would
blur the caret out of the query field and leave the open popover holding
nothing, so the press is cancelled — except on a control, which a press is
entitled to focus, and except on a scrollbar, where cancelling would stop the
drag scrolling the results. The outside-press close is a different gesture and
unaffected: it is a press the panel does not contain (ABN-554).

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
to the control it hits (ABN-510). The popover that closes gives its own field's
tab stop back before the newly opened one takes its. A pointer press outside the
open popover closes it too, with the company field counted as inside the
control.

## What focus landing on the checkout does to an open signup popup

Every `focusin` while the hosted sole-trader signup window is up is classified
once, and these are the three rules (TWO-25658):

-   **A Sole trader chip inside the capture's own popover is inert.** Arrival
    moves the popup neither way — only an activation raises it, and the browser
    delivers Enter and Space on a focused chip as a click.
-   **Any other target closes an open popup.**
-   **A target outside that capture's popover closes the popover too**, with the
    company field counted as INSIDE it: the field is the popover's own trigger
    and sits outside the panel node, and a buyer typing a query is still inside
    the control.

A `focusin` the browser re-fires on window return counts as the buyer focusing
that control, so an alt-tab back onto a control is classified like any other
arrival. Opening the popup blurs whatever held focus for exactly that reason.
A popover left on screen around a document focusing nothing reaches no keystroke
at all, so the launch parks that focus on the company field one tick later
(ABN-554). That one control is exempt from the rules above until focus leaves
it: a window return re-fires `focusin` there with no `focusout` before it, and
that is not the buyer arriving.

**The close is only abandonment while the checkout is still in sole-trader
mode** (ABN-565). The popup's close is noticed by a 300ms poll, so a chip the
buyer activated while the signup was up has already written the mode it asked
for by the time the poll runs — and a poll that returned to registered search
regardless swallowed that action. Manual entry was the visible loss: the field
came back a registered-search combobox with the registered chip pressed.

**Closing the signup with nothing captured gives focus back, but only where
focus is still unplaced** (ABN-561). A close that left `document.activeElement`
on the body or nothing at all has nowhere for the buyer to be, and the company
field takes it. The flight's own park is dropped before that is read, so what
it judges is the unplaced focus the launch left. A close the buyer caused by
focusing another control keeps focus where they put it.

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
where it is still open, on the company field where it is not (ABN-554).

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
order-intent error notice already followed (ABN-563). A shipped overlay
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
ends wrap (ABN-554). Both halves or neither: roles without the keyboard
behaviour advertise something the group does not do, which is its own defect.

`onTermKeydown` returns true on every key it does not act on, because knockout's
`event` binding suppresses the default action unless a handler says otherwise —
without it the group swallows Tab.

`isTermChecked()` is the single definition of a selected chip, read by the
`aria-checked` binding and by the visual `--selected` class, so the tick and the
exposed state cannot drift apart. A selection matching no chip leaves nothing
checked, and `focusableTerm()` puts the tab stop on the first chip so the group
cannot drop out of the tab order.

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
total the summary never showed (ABN-550). `surchargeModel.termStatusMessage()`
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

## A popup window is in no tab listing

`window.open` returns a window outside a browser extension's tab group, so a
tab list can never answer "did the popup open" — nor can a hang. The
authoritative check is the page's own retained handle and its `.closed`, which
means wrapping `window.open` before the action that should raise one. Judging
from a tab list yields a confident false "no window opened".

## jsdom cannot verify keyboard navigation

jsdom implements no sequential focus navigation: a dispatched `Tab` keydown
moves focus nowhere, so no jsdom suite can observe a focus trap, a wrong tab
order or a reverse-Tab dead end, however many cases it carries and however
green it is. Assert the observable proxy instead — that the handler leaves the
event undefaulted, that the control's parts are one contiguous run in document
order, that a closed panel carries `hidden` — and say in the suite that the
keyboard behaviour itself is verified in a real browser. A passing jsdom Tab
test is never evidence that a trap is absent. Focus a handler moves ITSELF, with
`element.focus()`, is the exception: jsdom performs that, so arrow-key traversal
inside a composite control is directly observable where Tab order is not.

Three traps in the same suites:

-   **A real chip click fires no `focusin`.** The chip's `mousedown` handler calls
    `preventDefault()`, which suppresses the native focus, so a rule written only
    against `focusin` never sees a pointer buyer at all.
-   **jsdom's `getElementById` answers with the first-REGISTERED node, not the
    tree-first one**, so a fixture carrying a duplicate id silently resolves to
    the wrong element.
-   **A mutation proves NEW coverage only when re-run against the base ref.** One
    the existing suite already catches proves the suite is sensitive, not that the
    case added covers anything.

## A NON-EXECUTABLE guard is invoked through `bash`

A script whose mode is `100644` and which is run as `./script.sh` exits 126. On a
CI dashboard that is indistinguishable from a check that ran and failed, so the
guard's own absence reads as its verdict. A guard committed executable runs
directly; anything else is invoked `bash script.sh`, and every guard prints what
it checked.

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

## An unitemized fee is reconciled per entity, and refundable

`findVerifiedResidualTaxRate()` reconciles a taxed residual against the rates
Magento's own tax engine applied, so a fee extension that registers its tax
normally needs no `FeeLineProviderInterface`. It resolves an invoice or credit
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

Reconciling the refund payload is not enough on its own, because a fee that
reaches the grand total through a totals collector rather than a quote item
never reaches the credit memo at all — the refund totals omit it and the
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

`getKnownLineAmountsOrder()` counts what composition *should* itemize, which
is deliberately not identical to what it actually emits. Two known
divergences: it counts an item whose product no longer loads, where
`getLineItemsOrder()` drops it and the dropped item's own value would read as
an unitemized fee and be refunded as one; and it reads the surcharge only
from the order columns, where `ComposeOrder::execute()` still falls back to
the checkout session. It also loads no products, which a totals collector
re-run on every credit-memo render cannot afford, and it avoids
`getShippingLineOrder()`, because resolving the shipping tax rate queries the
tax engine and throws when none is declared.

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
since the allowance core assigns covers every unitemized charge at once
(ABN-560).

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

## A table prefix is not supported, and only one route applies it

**The module is not verified against an installation configured with a
database table prefix, and its direct `core_config_data` queries do not
survive one** (ABN-558). The gap is tolerated because no merchant has reported
it; closing it is an audit of every raw query plus a prefixed-install test run,
a project of its own. Nothing guards it, so a new query inherits the gap
silently.

Two methods share the name `getTableName()`. The injected `ResourceConnection`'s
own method prepends the configured prefix, and is the one to call. The database
adapter's method, reached through `->getConnection()`, only shortens an
over-long identifier and prepends nothing — which is the form in use, and is
why a prefixed install addresses a table that does not exist while every
unprefixed one behaves correctly.
