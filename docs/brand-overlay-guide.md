# Brand overlay guide

How to build a brand overlay module on top of `Two_Gateway` — a brand
overlay edition that rebrands the payment method without forking any code.

## Architecture in one paragraph

Every module may ship an `etc/brand.xml`. At runtime
`Two\Gateway\Model\Brand\Loader` enumerates installed modules via
`ComponentRegistrar`, parses each `brand.xml` into an immutable
`Two\Gateway\Model\Brand\Descriptor`, and
`Two\Gateway\Model\Brand\ActiveBrandResolver` picks the single active
brand for the install. Brand-aware code reads identity values through
`Two\Gateway\Api\BrandRegistryInterface`, whose default DI binding
(`Two\Gateway\Brand\DescriptorBackedBrandRegistry`) delegates to the
resolved descriptor. An overlay therefore changes behaviour by
_declaring data_, not by overriding classes.

## The single-overlay invariant

`ActiveBrandResolver` enforces **max one overlay brand atop Two**:

-   Two alone → Two is active.
-   Two + one overlay → the overlay is active.
-   Three or more brands → `DomainException` at first `resolve()`.

The resolver caches the active descriptor in-process. There is no
per-store-view brand switching; one install, one brand.

## Files an overlay module needs

```
your-overlay/
  registration.php        ComponentRegistrar::register + (optionally) a
                          class_alias for a legacy gateway FQCN — never a
                          subclass file, so autoload doesn't force-resolve
                          the parent at di:compile mid-upgrade
  etc/
    module.xml            <sequence> MUST list Magento_Backend,
                          Magento_Config, Magento_Payment AND Two_Gateway
                          explicitly: module sequence is not transitively
                          walked at config-merge time, and a missing entry
                          makes the admin section override silently no-op
                          on alphabetical-sort installs
    brand.xml             the brand declaration (schema below)
    di.xml                virtualType for the payment method +
                          BrandOverlayRegistry entry (below)
    config.xml            payment-method defaults (install-time only;
                          existing core_config_data rows are never
                          rewritten)
    payment.xml           gateway entry — carries NO <model> element
                          (that lives in config.xml)
    acl.xml               your `<Vendor>_<Module>::config` resource
    csp_whitelist.xml     if your brand adds origins
  view/                   logo + palette only — no PHP/view-model overrides
  i18n/                   brand-specific strings
```

Conventions enforced across the overlay ecosystem (see AGENTS.md, the
parity block): `composer.json` carries no `version:` field;
`etc/module.xml` omits `setup_version`; `payment.xml` carries no
`<model>`.

## di.xml wiring

Two things, both small:

```xml
<!-- Payment-method registration: a virtualType over the generic
     method. The brand's `code` is the only override; every other
     constructor argument resolves by type through the ObjectManager,
     so new required parent constructor params auto-inject. -->
<virtualType name="Acme\Gateway\Model\AcmePayment"
             type="Two\Gateway\Model\GenericPaymentMethod">
    <arguments>
        <argument name="code" xsi:type="string">acme_payment</argument>
    </arguments>
</virtualType>

<!-- Declare the overlay so brand-aware machinery can enumerate it -->
<type name="Two\Gateway\Model\BrandOverlayRegistry">
    <arguments>
        <argument name="overlays" xsi:type="array">
            <item name="acme_payment" xsi:type="string">acme_payment</item>
        </argument>
    </arguments>
</type>
```

Do **not** rebind `BrandRegistryInterface`, ship per-brand virtualTypes
for blocks/view-models, or override admin sections in your own
system.xml — brand identity resolves at request time via the active
descriptor, and admin sections are synthesised from the canonical
template (see `suppressed_fields` below for per-brand control hiding).

## brand.xml schema reference

Root: `<config>` with one or more `<brand>` elements (`brand.xsd`
enforces unique `code` per file; `Loader` throws on duplicate codes
across modules). Elements may appear in any order (`xs:all`).

**`<brand>` attributes**

| Attribute        | Required | Controls                                                                                                                                                                |
| ---------------- | -------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `code`           | yes      | Brand + payment-method code (`[a-z][a-z0-9_]*`). Keyed into `sales_order.payment.method` and `core_config_data` paths — frozen for live installs.                       |
| `tab_sort_order` | yes      | Admin Configuration tab ordering.                                                                                                                                       |
| `section_prefix` | no       | Prefix for synthesised admin section ids (`{prefix}_general`, `{prefix}_payment`, …) and the tab id `{prefix}_gateway`. Defaults to `code` minus a trailing `_payment`. |

**Elements**

| Element                          | Required | Type                      | Controls                                                                                                                                                        |
| -------------------------------- | -------- | ------------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `provider`                       | yes      | string                    | Short provider name (admin/UI copy).                                                                                                                            |
| `provider_full_name`             | no       | string                    | Legal entity name.                                                                                                                                              |
| `product_name`                   | yes      | string                    | Customer-facing product name (checkout, emails, admin).                                                                                                         |
| `tab_label`                      | yes      | string                    | Admin Configuration tab label.                                                                                                                                  |
| `tab_css_class`                  | no       | string                    | CSS class on the admin tab.                                                                                                                                     |
| `checkout_subtitle`              | no       | string                    | Subtitle under the method title at checkout.                                                                                                                    |
| `checkout_url_template`          | yes      | string                    | Hosted-checkout URL template (`https://%s.…`).                                                                                                                  |
| `brand_tag`                      | no       | string                    | Checkout-page URL query param (`?brand=<tag>`). **Never sent in order bodies.**                                                                                 |
| `sign_up_url`                    | no       | string                    | Merchant signup link in admin.                                                                                                                                  |
| `documentation_url`              | no       | string                    | Docs link in admin.                                                                                                                                             |
| `api_base_url`                   | yes      | string                    | Two API base for this brand.                                                                                                                                    |
| `surcharge_rounding_steps`       | no       | `<step>` list             | Narrows the admin "Rounding step" dropdown (major units, each `> 0`). Absent or empty inherits the parent default set. Values are deduped and sorted ascending. |
| `csp_origins`                    | no       | `<origin>` list           | Extra CSP origins.                                                                                                                                              |
| `admin_resource`                 | yes      | string                    | ACL resource gating the admin section.                                                                                                                          |
| `module_label_chain`             | no       | `<module label="…">` list | Admin Version-panel rows; rows for missing modules silently skip.                                                                                               |
| `allowed_currencies`             | no       | `<currency>` list         | Currency allow-list.                                                                                                                                            |
| `allowed_countries`              | no       | `<country>` list          | Country allow-list.                                                                                                                                             |
| `extra_http_headers`             | no       | `<header name="…">` list  | Extra headers on API calls.                                                                                                                                     |
| `suppressed_fields`              | no       | `<field path="…">` list   | Hides admin controls for this brand (below).                                                                                                                    |
| `inline_term_fees`               | no       | boolean                   | Show per-term merchant fee beside Payment Terms checkboxes in admin (default true).                                                                             |
| `intent_approved_notice_enabled` | no       | `true` \| `false`         | On/off switch for BOTH the "order intent approved" and "order intent declined" notices. Default `true`. **See below.**                                          |
| `intent_approved_notice`         | no       | string                    | Copy override for the approved notice — wording only, **not** an off switch. **See below.**                                                                     |

There is deliberately **no** `intent_declined_notice` element. See below.

### The intent notices — one on/off switch, one wording override

The notices are buyer-facing "order intent approved" / "order intent not
approved" lines rendered inline in the checkout payment tile — as of the
2026-08-03 ruling (TWO-25326 §7.3), this is the ONLY place the buyer's
captured company NAME is displayed in the tile; the earlier standalone
`.two-company-label` element is gone, not relocated. The company NUMBER
also renders separately, independent of these notices, via the tile's
`.two-company-id-text` label (2026-08-04 ruling, TWO-25326 §5/§7
follow-up), mirroring the address step's equivalent label.
Both notices are controlled by **one shared on/off switch**, but only the
APPROVED notice has a wording override. **This is deliberate, not an
oversight** (2026-08-04 ruling, TWO-25326): the declined/not-available
notice must render identical platform-default copy for every brand,
approved-only overrides are how each brand overlay puts its own
branding on the reassurance message while the "not available" wording
stays neutral. Do not add an `intent_declined_notice` copy-override
element — `Model\Brand\Loader` hard-fails if a brand.xml declares one.

**Do not overload the switch with wording meaning** — an off switch
expressed as the absence of content is indistinguishable from an
unfinished string, and any tidy-up that deletes the "empty, unused"
declaration silently turns the notice back on.

#### `intent_approved_notice_enabled` — the on/off switch for BOTH notices

Explicit boolean only:

| brand.xml                                                                | Behaviour                                                                                  |
| ------------------------------------------------------------------------ | ------------------------------------------------------------------------------------------ |
| `<intent_approved_notice_enabled>true</intent_approved_notice_enabled>`  | Both notices **ON** (whichever one an order-intent outcome selects).                       |
| `<intent_approved_notice_enabled>false</intent_approved_notice_enabled>` | Both notices **suppressed entirely** — no element is emitted into the DOM, not an empty wrapper. There is no separate `intent_declined_notice_enabled` element; the ruling treats "the intent message" as one on/off unit, approved or declined. |
| element absent                                                           | Documented explicit default **`true`** (notices ON).                                        |
| anything else (`1`, `0`, `yes`, empty, whitespace)                       | **Error.** Never a silent third behaviour.                                                 |

Absent-means-`true` is deliberate: it keeps a third-party overlay that
declares nothing on ON. Base plugins declare `true` explicitly anyway, so
the file states its position rather than relying on omission.

The invalid case is caught twice, because `brand.xsd` is not validated at
runtime (see the validation warning below):

-   `brand.xsd` restricts the element to the enumeration `true|false`, so
    developer-mode config validation fails loudly; and
-   `Model\Brand\Loader` throws a `\DomainException` naming the offending
    `brand.xml` path, the element and the bad value — the same treatment
    `<surcharge_rounding_steps>` gets in the same method.

Note `xs:boolean` is deliberately **not** used: it would also accept `1`
and `0`, and this switch is meant to read as a decision.

#### `intent_approved_notice` — the copy override (approved only)

| brand.xml                                            | Behaviour                                                                                                                                              |
| ---------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------ |
| element absent                                       | Platform default translated copy.                                                                                                        |
| empty or whitespace-only                             | **Inert** — same as absent. It does **not** mean "off".                                                                                                  |
| `<intent_approved_notice>…</intent_approved_notice>` | Used verbatim as the approved-notice template. `%1` = brand product name, `%2` = buyer company name, `%3` = buyer organisation number (added 2026-08-03). |

`Descriptor::getIntentApprovedNotice()` returns `null` for the first two
rows and the template for the third; it never returns `''`. The declined
notice has no equivalent override — `Model\Ui\ConfigProvider` always
renders its own literal default copy for that outcome, and only consults
the switch above to decide whether to ship EITHER payload to the
renderer at all.

**Every white-label brand overlay is expected to declare
`intent_approved_notice`** with brand-specific copy (2026-08-04 ruling) —
falling through to the platform default here for a live overlay is a
bug, not a valid "no opinion" state.

#### Deploy order

**Merge order is `magento-plugin` (parent, owns the parsing) → the brand
overlay repo → `magento-hyva-extension`.** Out of order there is a window
in which Hyvä renders the notice for a brand that asked for it off.

An overlay that declares an empty `<intent_approved_notice>` and no
`<intent_approved_notice_enabled>` resolves to notice **ON** — wrong for a
brand that wants it off, but not broken. Empty deliberately stays inert
rather than being a hard error: that would turn a wrong notice into a
broken store. Declare the boolean.

Hyvä guards the reverse skew with `method_exists()` against a parent that
lacks the registry method — a missing method means "no brand opinion",
i.e. notice ON.

The company-unknown copy variant always stays on the platform default.
In practice it is unreachable: an order intent is only ever placed once
the buyer's company name **and** company number are known.

A company number is not always DISPLAYABLE, though, even when it is
known. A value beginning with the literal prefix `TWO:` is an internal
reference rather than a registry number and is never shown to the buyer
on any surface. In the notice sentence the renderer then removes the
company-number token **together with the brackets around it**, so the
copy reads `… by Acme Ltd …`, never `… by Acme Ltd () …`. An override
that places `%3` outside brackets is handled the same way. If you are
writing override copy, do not assume the number will be present.

**Keep `%3` inside round or square brackets in override copy.** The
renderer removes the token together with a bracket pair around it; it
does not know about other separators, so copy shaped `%2 – %3` or
`%2, %3` leaves the dangling separator behind when the number is
withheld.

This parent plugin's storefront renderer (Luma/Amasty/Fire, one shared
code path) emits each notice as a persistent inline element with class
`two-order-intent-message approved` / `two-order-intent-message declined`
inside the payment-method tile, as does PrestaShop (approved variant
only, as of writing). WooCommerce uses `twoinc-intent-approved` instead,
so grep for the platform-specific class names when sweeping the four
checkout surfaces. `magento-hyva-extension` shares this parent's
`BrandRegistryInterface`/brand.xml, so a brand's `intent_approved_notice`
override applies to Hyvä too without any Hyvä-side declaration.

The `intent_approved_notice_enabled` key carries the same name and
semantics on WooCommerce and PrestaShop, where it is a real PHP boolean
rather than an XSD enumeration. **The failure mode differs:** an invalid
value throws here and is a logged error plus the default `true` there,
because those resolvers run while rendering a buyer-facing checkout,
where a white screen is worse than a notice that stays on. Don't assume
Magento's throw when working across platforms. None of the four
platforms has (or should ever grow) a copy-override element for the
declined/not-available notice.

### A warning about validation

`brand.xsd` is enforced by CI/IDE tooling only — **nothing validates
brand.xml against the schema at runtime** (`Loader` uses plain
`simplexml_load_file`; the `xsi:noNamespaceSchemaLocation` hint is
passive). Two consequences:

1. A typo'd element is **silently ignored**, not rejected. Deploying an
   overlay that uses a new element against an older parent that doesn't
   parse it produces a silently-absent feature, not a deploy failure.
   Always verify the feature's observable behaviour after deploy.
2. Where silent mis-parsing would be dangerous, `Loader` carries its own
   guards (duplicate/empty `code`, `<surcharge_rounding_steps>`,
   `<intent_approved_notice_enabled>`) that throw `DomainException` at load.
   Follow that pattern when you add fields whose zero-value would
   silently disable a constraint.

## suppressed_fields: hiding admin controls per brand

```xml
<suppressed_fields>
    <field path="payment/payment_terms/payment_terms_duration_days"/>
</suppressed_fields>
```

`path` is `section_suffix/group/field` against the synthesised section
(`{section_prefix}_payment` → `payment_terms` group here).
`SynthesiseBrandAdminForm` sets `showInDefault/Website/Store="0"` on
the matching field during section injection: the control stays declared
in the canonical template but doesn't render for this brand. Use this
instead of shipping a `<section>` stub in the overlay's system.xml —
a static stub inserts itself into the merged Structure first and
short-circuits the synthesised section ordering.

## Worked example: adding a brand-driven field

`surcharge_rounding_steps` is the reference implementation for extending
`BrandRegistryInterface` with a new brand-driven value. Six touch
points, in dependency order:

1. **Schema** — `etc/brand.xsd`: add the element to `brandType`
   (optional, `minOccurs="0"`, so existing brand.xml files stay valid)
   plus its type. Constrain what you can there
   (`surchargeRoundingStepsType` → `positiveDecimalType`), and document
   the accepted values in an XSD comment.

2. **Loader** — `Model/Brand/Loader.php` `buildDescriptor()`: parse the
   element, **normalise and validate** — because nothing validates the
   xsd at runtime, a typo'd value would otherwise coerce to `0.0` and
   silently disable whatever it drives. Throw `DomainException` naming
   the brand.xml path, the element and the bad value. Pass the result as
   a constructor argument to `Descriptor`.

3. **Value object** — `Model/Brand/Descriptor.php`: append a readonly
   constructor property + getter. Mirror the same getter on the
   deprecated `Model/Brand.php` value object — both implement
   `BrandRegistryInterface` and must stay in lockstep while that class
   exists (see the deprecation note in
   `Brand/DescriptorBackedBrandRegistry.php`).

4. **Interface + adapter** — `Api/BrandRegistryInterface.php`: declare
   the getter with the full return-shape docblock (null = feature
   absent). `Brand/DescriptorBackedBrandRegistry.php`: delegate to the
   resolved descriptor.

5. **Consumer** — the code that reads the value lands in the same PR
   (no speculative brand fields).

6. **Tests** — unit tests for the Loader parse/validation and the
   consumer's boundaries.

**Release ordering:** the parent release containing steps 1–6 must be
deployed before an overlay brand.xml that uses the new element —
on an older parent the element is silently ignored (see the validation
warning above), so verify the feature's observable behaviour after
deploy.

**brand.xml or the API?** Reserve brand.xml for values that are
intrinsically brand-static: URLs, labels, CSP origins, admin-form shape.

Anything the platform owns and may change per merchant comes from
`GET /v1/merchant`, never brand.xml, so the storefront and checkout-api
can never disagree:

-   minimum order value — `min_order_amount/currency/basis`, read via
    `Service/Order/MinimumOrderProvider` and enforced by
    `Service/Order/MinimumOrderGate`;
-   offerable payment terms — `available_terms`, read via
    `Service/Merchant/SettingsProvider`;
-   buyer-surcharge cap — `surcharge_limit`, same provider.

## Local development

`make up` in this repo runs a vanilla Magento dev stack on port 1234;
a brand overlay repo's `make up` typically runs a brand-flavoured stack
on a different port — both can co-run. The overlay repo's `dev/install.sh` supports
`BASE=released|develop|tag:|sha:|ref:|path:` to test an overlay against
any parent version, which is exactly what the release-ordering caveat
above requires before shipping a new brand field.
