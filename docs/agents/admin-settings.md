---
description: 'How admin settings fail loud, brand form field parity, payment-term validation and the custom-header table.'
---

# Admin settings

## Admin settings fail loud: an unrecognised stored value is never priced

The standard for EVERY admin setting, not only the surcharge method.

**Save refuses it.** A value outside the field's known set is rejected by the
field's backend model. A crafted POST, a hand-edited row, `config:set` or an
import therefore cannot leave behind a value nothing understands.

That holds for a path DECLARED as a field in `system.xml`. A path some other
backend model writes has no model of its own, so nothing runs on it — see the
surcharge grid's per-term cells in [surcharge.md](surcharge.md), where the read path
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

**An unresolvable merchant record does NOT withhold the payment method**.
A record fetch that 5xxes, times out or finds the host unreachable
says nothing about whether the API key works, and only the api-key verification
verdict may take the method off the storefront. The record's consumers each
degrade to their own "nothing configured" behaviour instead: the read path
offers no buyer term, and order composition refuses to fall back to the nominal
default term rather than pricing an order under terms nobody granted.
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

**An admin surface reads the record at the scope its form is editing**.
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
record drives keeps rendering indefinitely. The motivating case is a
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

**That last-known-good DOES keep the method on offer through an outage**.
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

**The admin fee column is a cached last-known-good set too**. The fee
beside each payment term is fetched live per render, and an empty fee means that
term carries no fee — so a failed fetch may never leave the column blank and
silent. The last set retrieved for that merchant, buyer country and term list is
kept with no cache expiry and served instead, with the screen saying it could not
be refreshed and when it was retrieved. With nothing cached at all the screen says
the pricing service could not be reached. Do not restore a bare
`{success:false}` that the browser swallows.

## The Environment setting follows one rule on all three plugins

The WooCommerce and PrestaShop plugins implement the same rule (TWO-26604),
and `Test/Unit/Model/Config/_files/environment-cases.json` is byte-identical in
all three repos; change it in all three or in none.

-   Merchants are offered Production and Sandbox (`Model/Config/Source/Mode`).
    There are also internal environments for the use of Two employees only,
    offered only on Two's own servers. The `config.xml` default is `sandbox`.
-   `Model/Config/Backend/Mode` refuses anything else on save, wired in both
    `system.xml` and `brand_form_template.xml`. The refusal is field-level
    (`_dataSaveAllowed`), as WooCommerce refuses it: the stored value stays and
    every sibling field still saves.
-   `getMode()` normalises through `Model/Config/EnvironmentMode`: trimmed,
    lowercased, `prod` aliased, and anything unrecognised resolves to
    `sandbox`. This is the one read path that does not raise on an
    unrecognised value: the rule is shared with the other plugins, and sandbox
    can neither take real money nor accept a production key.
    `getStoredMode()` is the raw value the dropdown and the health checklist
    judge.
-   The health checklist marks an unrecognised value, and an environment the
    plugin does not offer on this server, as needing action. The health
    checklist never rewrites a stored value.
-   `Setup/Patch/Data/NormaliseEnvironmentMode` rewrote legacy rows once, on
    the Two payment codes only (`payment/<code>/mode` is a common key).

## A field declared only in `system.xml` reaches no brand — nor does its model

Every admin pane is rendered from fields synthesised out of
`brand_form_template.xml`, and that deep merge only carries fields the template
already declares — so a field added to `system.xml` alone is dropped silently and
renders on no brand at all. `AdminFormFieldParityTest` compares the two field
lists in every group of every shared section at any nesting depth, reading that
list of groups out of the forms themselves so a newly added one is covered
without being listed. A section id outside the `two_` / `{{section_prefix}}_`
prefix matches neither form's xpath, so the provider rejects one rather than
walking past it, and per form every `<field>` that is not a `<depends>`
reference must sit at a path those cases reach, so a field in a shape the
enumeration does not walk fails rather than going uncompared.

**The same applies to each field's `source_model`, `backend_model` and
`frontend_model`, and it fails far more quietly.** An overlay install renders ONLY
the synthesised sections (`HidePaymentSection` hides the static Two ones), so a
model wired in `system.xml` alone is a save-time guard, an option list or a field
renderer that exists on a Two-only install and on no overlay — the invariant holds
everywhere it is tested and nowhere a partner runs. That is how one release shipped a
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
selection.

**The default term prefers 30 days.** `getDefaultPaymentTerm()` resolves the
admin's stored default, then the merchant record's own default term, then 30,
then the shortest offered term — each honoured only while it is in the offered
set. The differential surcharge baseline reads the same resolver, so
the reference term it prices against moves with the preference.

**Nothing but the admin synthesises a stored default term.** The field's first
option is Automatic — an empty value — and neither the field nor its JS ever
puts a day count in the select on the admin's behalf: the select posts on every
save, so one synthesised there is stored, becomes the resolver's first step, and
makes every later step unreachable on that scope. A stored term the merchant
withdrew has no matching option, so the select reads Automatic.

**The admin surfaces that NAME the default term resolve it, never read the
select.** With Automatic selected the select carries no day count, while the
surcharge grid still has to disable the row differential mode prices against,
showing its stored amounts, which a disabled cell never posts anyway,
and the differential option still has to name it. `SurchargeGrid` and
`Two_Gateway/js/default-term` each apply the resolver's order — the JS from the
ticked terms plus the merchant's own default term, published as
`data-merchant-default-term` on the checkboxes container. Reading
`default_payment_term` alone badges no row at all wherever the admin left the
choice to the resolver.

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
-   **23 header names are reserved**, matched case-insensitively and
    exactly (a prefix like `X-Upgrade-Path` is the merchant's to use).
    Five groups: names the integration sets itself (`host`,
    `content-type`, `content-length`, `accept`, `accept-language`,
    `x-api-key`, `idempotency-key`, `x-idempotency-key`,
    `two-delegated-authority-token`); the proxy identity the
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

## Every write sends an Idempotency-Key

Every POST, PUT, PATCH or DELETE the adapter sends carries `Idempotency-Key`
(never `X-Idempotency-Key`); a GET carries none (TWO-26658). The key names one
logical operation: the same on every retry of it, different for every other.
`Service\Api\IdempotencyKey` builds it from durable ids only, never time or
randomness held only in memory, as `<operation>_<ids>[_<short hash>]`,
lowercase ASCII, at most 100 characters (the API's limit). Callers set it with
`Adapter::withIdempotencyKey()`, a copy like `withLogSubject()`. A key is never
shared between buyers: a caller missing the id its key needs (an order intent
with no quote id, a token request with no quote or session) passes null and
sends no key, and the adapter has no fallback of its own.

| Operation | Key |
| --- | --- |
| Order create | `create_<increment id>_<attempt reference>`: the reference is 16 random hex characters minted per checkout attempt and stored as `two_order_reference` |
| Order edit | `edit_<two order id>_<hash of the last accepted edit's key, or the create key, plus the composed edit>`; an accepted edit stores its key in the payment information |
| Confirm | `confirm_<two order id>`, shared by its one retry |
| Whole fulfil | `fulfil_<two order id>`, on an invoice, a shipment or a status change |
| Partial fulfil | `fulfil_<two order id>_shipment-<id>` or `_invoice-<id>` (`_invoice-n<N>-<hash>` while unsaved) |
| Cancel | `cancel_<two order id>` |
| Refund | `refund_<two order id>_creditmemo-<id>` (`_creditmemo-n<N>-<hash>` while unsaved) |
| Order intent | `order_intent_<quote id>_<hash of the intent>` |
| Other writes | `fee_quote_<hash>`, `merchant_rates_<hash>`, `delegation_token_<quote id>_<hash of the session id>`, `autofill_token_<quote id>_<hash of the session id>`, `invoice_upload_<invoice id>_<index>` |

Magento calls the payment method before it saves the invoice or credit memo,
so an unsaved one is named by its place among the order's saved records of
that kind plus a hash of its amount and lines (`creditmemo-n3-<hash>`): the
same on a retry, which saves nothing, and different for any other record.
Two answers a key it has seen with the operation it stored, so the answer is
checked: a refund's refunded total must move by the amount sent (the total is
kept in the payment information as `two_refunded_total`), and a partial
fulfil must name a fulfilled order no saved invoice records. Otherwise the
operation is refused with an order comment, an `[idempotency-replay]` error
log line and an exception, so Magento never records it as done.

Every order write (create, edit, confirm, fulfil, cancel, refund) logs an
`[idempotency-key]` line with its key and HTTP status (0 for no answer)
whatever the outcome and the debug setting. The key also appears in the
refusal and transport failure lines and, with debug logging on, in the
request headers (where it is not redacted). `idempotency-key` and `x-idempotency-key` are reserved names in the
custom-header table, so no stored row can replace or shadow it.

**A browser-ticked header must already be allowed by the API on
browser-originated calls**, or the one direct call the browser makes
fails CORS preflight and the sole-trader autofill silently finds no
buyer. The field help says so; nothing enforces it.
