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
*declaring data*, not by overriding classes.

## The single-overlay invariant

`ActiveBrandResolver` enforces **max one overlay brand atop Two**:

- Two alone → Two is active.
- Two + one overlay → the overlay is active.
- Three or more brands → `DomainException` at first `resolve()`.

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

| Attribute | Required | Controls |
|---|---|---|
| `code` | yes | Brand + payment-method code (`[a-z][a-z0-9_]*`). Keyed into `sales_order.payment.method` and `core_config_data` paths — frozen for live installs. |
| `tab_sort_order` | yes | Admin Configuration tab ordering. |
| `section_prefix` | no | Prefix for synthesised admin section ids (`{prefix}_general`, `{prefix}_payment`, …) and the tab id `{prefix}_gateway`. Defaults to `code` minus a trailing `_payment`. |

**Elements**

| Element | Required | Type | Controls |
|---|---|---|---|
| `provider` | yes | string | Short provider name (admin/UI copy). |
| `provider_full_name` | no | string | Legal entity name. |
| `product_name` | yes | string | Customer-facing product name (checkout, emails, admin). |
| `tab_label` | yes | string | Admin Configuration tab label. |
| `tab_css_class` | no | string | CSS class on the admin tab. |
| `checkout_subtitle` | no | string | Subtitle under the method title at checkout. |
| `checkout_url_template` | yes | string | Hosted-checkout URL template (`https://%s.…`). |
| `brand_tag` | no | string | Checkout-page URL query param (`?brand=<tag>`). **Never sent in order bodies.** |
| `sign_up_url` | no | string | Merchant signup link in admin. |
| `documentation_url` | no | string | Docs link in admin. |
| `api_base_url` | yes | string | Two API base for this brand. |
| `available_payment_terms` | yes | `<term>` list | Day counts offered (positive integers). |
| `surcharge_fixed_max` | no | `amount` + `currency` attrs | Cap on the fixed surcharge component. |
| `csp_origins` | no | `<origin>` list | Extra CSP origins. |
| `admin_resource` | yes | string | ACL resource gating the admin section. |
| `module_label_chain` | no | `<module label="…">` list | Admin Version-panel rows; rows for missing modules silently skip. |
| `allowed_currencies` | no | `<currency>` list | Currency allow-list. |
| `allowed_countries` | no | `<country>` list | Country allow-list. |
| `extra_http_headers` | no | `<header name="…">` list | Extra headers on API calls. |
| `suppressed_fields` | no | `<field path="…">` list | Hides admin controls for this brand (below). |
| `inline_term_fees` | no | boolean | Show per-term merchant fee beside Payment Terms checkboxes in admin (default true). |

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
   guards (duplicate/empty `code`) that throw `DomainException` at load.
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

`surcharge_fixed_max` is the recipe for extending
`BrandRegistryInterface` with a new brand-driven value. Six touch
points, in dependency order:

1. **Schema** — `etc/brand.xsd`: add the element to `brandType`
   (optional, `minOccurs="0"`, so existing brand.xml files stay valid)
   plus its complexType (attribute-pair idiom:
   `<surcharge_fixed_max amount="25.0" currency="EUR"/>`).

2. **Loader** — `Model/Brand/Loader.php` `buildDescriptor()`: parse the
   element, **normalise and validate** — because nothing validates the
   xsd at runtime, a typo'd amount would otherwise coerce to `0.0` and
   silently disable whatever the value drives. Throw `DomainException`
   on malformed input. Pass the value as a constructor argument to
   `Descriptor`.

3. **Value object** — `Model/Brand/Descriptor.php`: append a readonly
   constructor property + getter. Mirror the same getter on the legacy
   `Model/Brand.php` value object — both implement
   `BrandRegistryInterface` and must stay in lockstep until the legacy
   interface is deleted (see the deprecation note in
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
intrinsically brand-static (URLs, labels, payment terms, CSP origins).
A value the platform owns and may change per merchant — the minimum
order value is the canonical case — belongs in the Two API instead:
the gate reads `GET /v1/merchant`'s `min_order_amount/currency/basis`
via `Service/Order/MinimumOrderProvider` (TWO-24775), so the storefront
and checkout-api can never disagree on the threshold. The brand.xml
`<minimum_order>` element that originally shipped with TWO-24743 was
removed in favour of that lookup.

## Local development

`make up` in this repo runs a vanilla Magento dev stack on port 1234;
a brand overlay repo's `make up` typically runs a brand-flavoured stack
on a different port — both can co-run. The overlay repo's `dev/install.sh` supports
`BASE=released|develop|tag:|sha:|ref:|path:` to test an overlay against
any parent version, which is exactly what the release-ordering caveat
above requires before shipping a new brand field.
