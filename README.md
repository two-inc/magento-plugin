<p align="center">
  <img src="view/frontend/web/images/logo.svg" width="128" height="128"/>
</p>
<h1 align="center">Two — Magento 2 Payment Plugin</h1>

B2B Buy Now, Pay Later for Magento 2.3.3+. This plugin integrates [Two](https://www.two.inc/) as a payment method, letting merchants offer invoice-based checkout with flexible net terms.

## What it does

**For merchants:**

- Instant credit checks on business customers
- B2B guest checkout (up to 36% conversion uplift)
- Flexible invoice terms from 14 to 90 days
- Automatic invoicing via the [PEPPOL](https://peppol.eu/) e-invoicing network
- Partial capture and refunds
- Instant payment on fulfilment — Two assumes the credit risk
- Optional product-page promotion: a message and a buy button, both off by
  default ([how to turn them on](#product-page-promotion))

**For buyers:**

- Frictionless checkout with no onboarding
- Flexible repayment terms
- PDF and electronic invoicing straight to their ERP

## Installation

Install via Composer:

```bash
composer require two-inc/magento2
php bin/magento module:enable Two_Gateway
php bin/magento setup:upgrade
php bin/magento cache:flush
```

In production mode, also deploy static content:

```bash
php bin/magento setup:static-content:deploy
```

Then configure the plugin under **Stores > Configuration > Sales > Payment Methods > Two**.

### Cache types

`setup:upgrade` enables the module's `two_gateway` cache type, which holds the
merchant profile values fetched from Two. Drop just those values with:

```bash
php bin/magento cache:clean two_gateway
```

The type must be enabled for that to do anything: a disabled cache type
accepts the command and drops nothing.

### Post-install steps

Run these immediately after `setup:upgrade` to refresh the DI graph
and clear any stale cache types:

```bash
php bin/magento setup:di:compile
php bin/magento cache:flush
```

If admin Configuration is missing expected Two/brand sections, the
cause is almost certainly one of:

- A plugin registered only under `etc/adminhtml/di.xml` or
  `etc/crontab/di.xml` instead of `etc/di.xml`. See AGENTS.md
  for the DI-scope rule.
- An FPM worker holding stale opcache. Restart PHP-FPM
  (`systemctl reload php-fpm` or `kill -USR2 <fpm-master-pid>`).
- A cache type (config / layout / full_page) in stale state.
  `bin/magento cache:flush` is the canonical fix.

## Product-page promotion

Two optional surfaces on the product page, added in **2.4.0**. Both are
**off by default** and independent: a shop may run either, both or neither.

Find them in the admin under **Stores → Configuration → Two → Checkout
fields → Title & display**.

| Setting | What it does |
| --- | --- |
| **Show message on product pages** | Adds a short line telling the buyer they can pay on invoice with Two. |
| **Show buy button on product pages** | Adds a button under Add to Cart that puts the item in the basket and opens checkout with Two preselected where it is available. |

The button never places the order. It carries the buyer to checkout, and
they confirm there as usual, so a misclick costs nothing. It sits beside
the theme's Add to Cart rather than replacing it, and respects the
quantity and any variant the buyer has chosen.

**Preselection is conditional, by design.** A product page has no cart, so
the button cannot know whether minimum order value, buyer country or
currency will allow the method. When the finished basket does not qualify,
checkout opens with nothing selected and the buyer chooses as they would
have anyway. See `Block/Product/ExpressButton.php` for the gates the button
does apply.

**Brand overlays only see what they list.** From 3.0.0 an overlay renders
only the paths in its `<allowed_fields>`, so these controls appear under an
overlay's own tab only if it names
`checkout_fields/display/product_message_enabled` and
`checkout_fields/display/product_button_enabled`. See
[docs/brand-overlay-guide.md](docs/brand-overlay-guide.md).

Both settings are per store view, so you can trial them on one storefront
before rolling them out.

## Upgrading to 4.0

4.0 removes the plugin's own shipping tax settings: the **Default shipping tax
class** field and the deprecated flat-percentage **Default shipping tax rate**
field. `setup:upgrade` deletes their stored values and does not carry them
over anywhere.

Where Magento records no tax rate for a shipping line, the fallback now uses
Magento's own **Stores > Configuration > Sales > Tax > Tax Classes > Tax Class
for Shipping**, and only on stores where the fallback has been enabled (see
below). Anywhere else such a line is sent at 0% with its tax as charged, and
Two's API validates it. This includes orders placed on 3.x.

## Shipping tax fallback

The shipping tax fallback resolves a shipping line's rate from Magento's own
**Tax > Tax Classes > Tax Class for Shipping** when Magento recorded no rate for
that line. It is off by default and has no admin field. Contact Two before
enabling it for a store:

```bash
bin/magento config:set --scope=stores --scope-code=<store_code> payment/two_payment/enable_shipping_tax_fallback 1
bin/magento cache:flush config
```

On a brand overlay the path is `payment/<brand code>/enable_shipping_tax_fallback`.

The fallback is **populated** when it is enabled for the store and the Tax Class
for Shipping is set. Enabled with no class set, it does nothing.

| Shipping line | Fallback blank (the default) | Fallback populated |
|---|---|---|
| Magento recorded a rate, including an explicit 0% | Sent at the recorded rate. The plugin does not check it; the postprocessing hook runs, then Two's API validates it. | Same as blank. |
| Magento recorded no rate, whatever the line's tax (0 included) | Sent as is: rate 0, tax as charged. The plugin does not check it; the hook runs, then Two's API validates it. | The rate comes from the Tax Class for Shipping, and the line's tax must reconcile with it within 0.02. The line is built at that rate and the hook runs; with no subscriber, a line that does not reconcile is then refused. |

With the fallback blank, the plugin never refuses a request over shipping tax.

"Recorded a rate" means Magento applied a tax rate to the shipping line when
the order was placed. A Tax Class for Shipping of **None**, or no tax rule
matching the address, applies none, so it reads as no rate. A rule applying 0%
is recorded when shipping prices exclude tax.

Placement stores which case applied on the order, in `two_shipping_tax_rate_source`
(`declared` or `none`), with the rate in `two_shipping_tax_rate` (percent): the
recorded rate, 0 included, or for `none` the fallback's rate, empty when the
fallback was blank. Magento does not save a 0% shipping rate with the order,
so this record is what update, capture, shipment and refund read, never the
current configuration: a later change to the fallback or the tax rules does not
move an order already placed. An order placed before this record existed has
both empty and resolves as it would at placement. A refund takes the order's
shipping rate and does not re-check the tax it carries.

This reconcile is a shop-match check: it runs after the postprocessing hook,
in the plugin's default handler, on order intent, create, update and capture.
A subscriber on the hook takes it over, and it does not run unless the
subscriber opts back in (see "Stable extension contract: order
postprocessing").

## Tax codes on 0% lines

Every line the plugin sends at a 0% tax rate can carry a Two `tax_code` saying
why it is zero. For a merchant whose Two account is in Spain the API requires
one on every 0% line. The plugin adds it to order create, order edit, partial
capture, shipment and refund lines, while it builds the request and before the
postprocessing hook, so a subscriber can still change it. Lines at any other
rate are sent exactly as before.

A line's code comes from the first of these that gives one:

1. **Your mapping.** **Order management > Tax codes for 0% lines** has one row
   per product tax class (including **None**), each with a dropdown of Two's
   tax codes for your country, fetched from Two and cached for a day. A
   product line uses its product's tax class (a configurable product, its
   child's). The shipping line uses Magento's **Tax Class for Shipping**
   (marked "(shipping)" in the list; with that set to **None**, map the
   **None** row), and the payment terms fee its own surcharge tax class. Every row defaults to
   (none). Codes that need an exemption reason the plugin has no way to supply
   are not offered; set those, with their reason, in the postprocessing hook.
2. **Derivation, for merchants in Spain only.** Physical products are goods;
   virtual and downloadable products are services, and so is any item Magento
   marks virtual, such as a bundle, gift card or configurable product with
   nothing to ship. Shipping and other fee
   lines count as goods when the order has a physical product, and as services
   when it has none. Goods follow the delivery address (the billing address
   when there is none). Services follow the buyer company's country, which is
   the billing country the plugin sends.

   | Line | Where | Code |
   |---|---|---|
   | Goods | Delivered outside the EU | `ES_IVA_EXPORT` |
   | Goods | Delivered to the Canary Islands, Ceuta or Melilla (Spanish postcodes starting 35, 38, 51 or 52) | `ES_IVA_EXPORT` |
   | Goods | Delivered to another EU state, buyer in an EU state other than Spain | `ES_IVA_INTRA_COMMUNITY` |
   | Goods | Delivered in mainland Spain or the Balearics, or to another EU state for a Spanish buyer | none |
   | Service | Buyer in an EU state other than Spain | `ES_IVA_REVERSE_CHARGE` |
   | Service | Buyer in Spain or outside the EU | none |

   Monaco counts as part of the EU (through France). Two only sells to
   verified businesses, so every buyer counts as a business.
3. **Otherwise no code is sent.**

**The plugin never refuses; the API does.** A 0% line with no code is sent
as is, and Two's API decides. For a Spanish merchant it refuses such a line, so
map the tax classes that produce 0% lines nothing above covers (for example
domestic exempt sales, or services to buyers outside the EU). A non-Spanish
merchant with no mapping sends exactly what it sent before.

Placement records each line's code, or that it had none, on the order
(`two_tax_codes`): per product line, for shipping, for the payment terms fee,
and once for all other fee lines ("Other charges" and fee-provider lines),
which share one code whatever their id. Order edit, capture, shipment and
refund send those codes, so a later change to the addresses, the mapping or a
product's tax class does not move a placed order. These resolve afresh
instead: the refund adjustment line, a product line placement could not match
to its item (its SKU was changed by another extension, or its item has no
quote item), a fee line on an order that had none at placement, and every
line of an order placed before this record existed.

## Stable extension contract: order postprocessing

Every request this plugin sends to Two about an order passes through one
extension point first. It presents the entire request body and lets your code
change any part of it: split a charge differently, change an amount, add a
field. Use it when the shop is not your accounting source of record and the
invoice Two issues has to follow your books rather than the shop's figures.

**Name and mechanism.** An `after` plugin on
`Two\Gateway\Api\OrderPostprocessingInterface::process(array $payload, array $context): array`.
The default implementation returns the payload unchanged. Plugins chain by
`sortOrder`, and the final result is sent as returned.

```xml
<!-- your module's etc/di.xml -->
<type name="Two\Gateway\Api\OrderPostprocessingInterface">
    <plugin name="acme_order_postprocessing" type="Acme\Two\Plugin\OrderPostprocessing"/>
</type>
```

**Parameters.**

- `$payload`: the complete request body exactly as it would be sent, amounts as
  2dp decimal strings: `line_items`, `tax_subtotals`, `net_amount`,
  `tax_amount`, `gross_amount`, buyer, addresses and the rest. A partial
  capture carries its lines under `partial`; a refund carries `amount`. A
  request with no body (confirm, cancel, whole-order capture) is `[]`. A 0%
  line may carry `tax_code` (see "Tax codes on 0% lines"); a subscriber may
  change it, and may add `tax_exemption_reason_code` for a code that needs a
  reason the plugin cannot supply.
- `$context`, an array:

| Key | Type | Meaning |
|---|---|---|
| `request_type` | string | `order_intent`, `order_create`, `order_update`, `order_confirm`, `capture`, `refund` or `cancel` |
| `trigger` | string | What caused the request: `checkout`, `admin_edit`, `confirmation`, `invoice`, `shipment`, `status_change`, `credit_memo`, `cancel`, `buyer_cancel` |
| `endpoint` | string | The API path, for example `/v1/order/{id}/refund` |
| `quote` or `order` | object | The quote for `order_intent`, the order for everything else |
| `invoice`, `shipment`, `creditmemo` | object | Present where the request has one |
| `intent_order` | object | `order_intent` only: the unsaved order the plugin converted the quote into and built the lines from. Never saved |
| `shipping_tax_rate` | float or null | The rate Magento's **Tax Class for Shipping** applies at the order's tax address, whether or not the shipping line was taxed. `0.21` means 21%. Null when no class is set |
| `fallback_shipping_tax_rate` | float or null | The shipping tax fallback's rate, null unless the fallback is enabled for the store |
| `contract_version` | int | `1` |

**Return.** The full payload, edited or not.

**When it fires.** Immediately before every order request is sent, once per
request:

| Request type | When |
|---|---|
| `order_intent` | The checkout's approval check. The body is built here from the quote, with the same lines as the order |
| `order_create` | Order placement |
| `order_update` | An admin edit of the order's address. Not sent once the order can no longer be edited, for example once Two has invoiced all or part of it: the admin sees a notice instead |
| `order_confirm` | The buyer returns from Two's checkout |
| `capture` | An online invoice, a shipment, or the order reaching a fulfil-on status, per the fulfilment trigger |
| `refund` | A credit memo |
| `cancel` | The order is cancelled or voided, or the buyer abandons Two's checkout |

A few specifics:

- A whole-order capture has no body and its `invoice` is null. That covers an
  invoice for everything still open and the fulfil-on status trigger (which
  carries no `invoice` key at all).
- If Two answers a whole-order capture with `PARTIAL_ORDER_MISSING_DATA`, the
  plugin retries it as a partial capture of the latest invoice, so the hook
  fires twice for one capture: first with `[]`, then with the `partial` body.
- For `order_intent` the plugin builds an unsaved order from the live checkout
  quote. It re-collects the quote's totals and runs core's quote-to-order
  conversion in memory, which dispatches `sales_convert_quote_to_order`. That
  happens on every approval check, and no order is placed. An observer on that
  event, or on the totals collection, that assumes a real placement (reserving
  stock, numbering, writing records) will run then too.

**Unchanged means unchanged.** When no subscriber changes the payload, every
request is sent byte for byte as the plugin composed it, and is accepted or
refused exactly as it was before this hook existed.

**What you return is sent.** The payload goes out as your subscriber returns
it, once it passes the internal-consistency checks below, and Two's API
validates it as it validates any request. If the API rejects your payload, its
response is written to `var/log/two/debug.log` and its message is shown to the
admin for an admin action, or to the buyer at checkout.

**Checks.** The plugin checks a payload in two ways.

- *Internal-consistency checks* ask whether the payload adds up by itself.
  They always run, after the hook, on the payload about to be sent, whether or
  not a subscriber is registered. They do not constrain what you declare, only
  that it adds up: order create and update refuse a line other than shipping
  whose tax does not follow from its own declared rate and net, with a generic
  notice and `TaxReconciliationFailed` in the error log. The tolerance allows
  for per-unit rounding and for tax on the undiscounted base.
- *Shop-match checks* ask whether what the plugin built matches what the shop
  worked out. There is one: a shipping line whose rate the shipping tax
  fallback supplied must carry the tax Magento charged at that rate (see
  "Shipping tax fallback"). It applies to the shipping line the plugin built,
  on order intent, create, update and capture.

**The default handler and when it stands down.** The plugin registers its own
`after` plugin on this interface, `two_gateway_shop_match_checks`, which runs
the shop-match checks. It runs them only when no other handler is registered on
`process()`: any `before`, `around` or `after` plugin on the interface or on
`Two\Gateway\Model\OrderPostprocessing`, or a preference that replaces that
default implementation. Detection reads the same interception config the hook
runs on, in the area of the request (storefront, admin, REST, cron), so a
plugin that is disabled, belongs to a disabled module, or is declared for
another area does not count. When the default handler stands down, it writes
`OrderPostprocessingShopMatchDelegated` to the debug log for each request,
naming the handlers.

**With a subscriber, shop-match correctness is yours.** Whatever you change,
nothing in the plugin compares your result with the shop's figures. For
example, a subscriber that adds a line for a cost the shop adds to the cart
total outside a carrier, split at its own rate, is sent as returned as long as
its lines add up.

**Opting back in.** To keep the plugin's shop-match checks on the payload you
return, or on the parts you did not touch, inject
`Two\Gateway\Api\OrderPostprocessingShopMatchInterface` and call
`check($result, $payload, $context)` with the payload you are about to return
and the `$payload` and `$context` your plugin received. Each check applies to
the line the plugin built it for, and only while your result still carries that
line unchanged: a line you edited, replaced or removed is yours. A failing check
throws `Two\Gateway\Exception\ShopMatchRefusedException`, and the request is
refused exactly as the default handler refuses it.

```php
public function afterProcess(OrderPostprocessingInterface $subject, array $result, array $payload, array $context): array
{
    $edited = $this->addHandlingLine($result);
    $this->shopMatch->check($edited, $payload, $context);

    return $edited;
}
```

A shop-match refusal reaches the buyer as a generic notice on order intent,
create and update, and the admin as the reason on a capture, and is logged
with `ShippingTaxFallbackMismatch`.

A subscriber that throws, returns something other than an array, or returns a
payload that cannot be JSON-encoded has a bug. That request is refused and
logged with `TWO_ORDER_POSTPROCESSING_HOOK_FAILED`:

| Request type | Effect of a refusal |
|---|---|
| `order_intent` | The approval check is refused and the buyer sees a generic notice |
| `order_create` | Checkout is refused with a generic notice |
| `order_update` | The update is not sent to Two. The address edit still saves in Magento, and the admin sees the error as a warning and in the order's history |
| `capture` | The invoice, shipment or fulfil-on status change that triggered it is blocked with the error |
| `refund` | The credit memo is refused with the error |
| `order_confirm`, `cancel` | Never refused. These take no body, so a subscriber that throws or adds one is logged with `TWO_ORDER_POSTPROCESSING_HOOK_FAILED` or `TWO_ORDER_POSTPROCESSING_BODY_NOT_ACCEPTED`, and the request is sent empty. Magento has already confirmed or cancelled the order by then, and a Two order left live could still be invoiced |

The plugin never recomputes anything after the hook, because that would
overwrite your edits. If you change a line, move the totals and subtotals it
affects, or the API will reject the request: inject
`Two\Gateway\Api\OrderPostprocessingTotalsInterface` and call
`recompute($edited, $before)`, passing the payload you received as `$before`.
It sets `net_amount`, `tax_amount` and `gross_amount` (or a refund's `amount`)
to the sum over your lines plus whatever the total carried outside its lines
in `$before` (store credit, a gift card, an unitemised fee), and does the same
for each `tax_subtotals` bucket by rate. A bucket residual under half a cent is
float noise and is dropped. A total you set by hand before the call is replaced, not
counted twice.

Every change is written to the debug log with the request type and each
changed field's JSON pointer, old and new value, and noted in the order's
comment history where there is an order.

**Your code owns what it declares.** With a subscriber that changes amounts,
the invoice Two issues can differ from what the shop charged. That is your
decision: only the internal-consistency checks, and the shop-match checks you
opt back into, look at your result.

**Requirements on a subscriber.**

- Deterministic: the same inputs give the same output.
- Cheap: it runs on every approval check during checkout as well as on every
  order request.
- Present: a disabled module simply means the shop's own figures are sent, and
  the plugin's default handler runs its shop-match checks again.

**Example.** Treat untaxed shipping as VAT-inclusive at the shop's shipping tax
rate. A 29.00 shipping line becomes net `round(29.00 / 1.21, 2)` = 23.97 and tax
5.03, gross unchanged. A partial capture carries its lines under `partial`, so
the edit covers both places:

```php
namespace Acme\Two\Plugin;

use Two\Gateway\Api\OrderPostprocessingInterface;
use Two\Gateway\Api\OrderPostprocessingTotalsInterface;

class OrderPostprocessing
{
    public function __construct(private OrderPostprocessingTotalsInterface $totals)
    {
    }

    public function afterProcess(OrderPostprocessingInterface $subject, array $result, array $payload, array $context): array
    {
        $rate = $context['shipping_tax_rate'];
        if (!$rate) {
            return $result;
        }
        $resplit = static function (array $line) use ($rate): array {
            if ($line['type'] !== 'SHIPPING_FEE' || (float)$line['tax_amount'] != 0.0) {
                return $line;
            }
            $gross = (float)$line['gross_amount'];
            $net = round($gross / (1 + $rate), 2);
            $line['net_amount'] = number_format($net, 2, '.', '');
            $line['tax_amount'] = number_format($gross - $net, 2, '.', '');
            $line['unit_price'] = $line['net_amount'];
            $line['tax_rate'] = number_format($rate, 6, '.', '');
            $line['tax_class_name'] = 'VAT ' . number_format($rate * 100, 2) . '%';
            return $line;
        };

        $edited = $result;
        if (isset($edited['line_items'])) {
            $edited['line_items'] = array_map($resplit, $edited['line_items']);
        }
        if (isset($edited['partial']['line_items'])) {
            $edited['partial']['line_items'] = array_map($resplit, $edited['partial']['line_items']);
        }

        return $this->totals->recompute($edited, $result);
    }
}
```

`Test/Integration/OrderPostprocessingFixture` is a working module that does
this, and CI runs it inside a real Magento on every change.

**Versioning.** This contract is permanent. The interface, its method and the
v1 context keys and request types are never removed or renamed, and it fires
consistently in response to the same events in every release. New context keys,
new request types or triggers, and relaxed checks may be added without notice.
Removing a key, changing a unit (rates stay decimal fractions), tightening a
check a v1 subscriber could already pass, or firing on fewer requests is never
done. A genuinely incompatible change would arrive as a new interface, with this
one still firing alongside it.

## Development

The development environment runs Magento in Docker with the plugin bind-mounted, so file changes are reflected immediately.

`docker-compose.yml` brings up three containers: Magento, MariaDB and OpenSearch.
The Magento image (`ghcr.io/brtkwr/magento-dev`, built from
[brtkwr/magento-helm](https://github.com/brtkwr/magento-helm)) is published for
both amd64 and arm64, so on Apple Silicon it runs natively instead of under
QEMU emulation. It also means **no Magento marketplace keys are needed** — the
plugin is mounted as an `app/code` module and the language packs ship inside the
image, so nothing runs `composer` against `repo.magento.com` on your machine.

### Prerequisites

- Docker
- Make
- A Two API key ([request sandbox access](https://www.two.inc/))

### Quick start

```bash
# Create the Magento container and install the plugin
make install

# Configure with your API key
make configure TWO_API_KEY=<your-key>

# Start / stop
make run
make stop
```

The first `make install` takes a few minutes: the container installs the shop
on first boot, and `make install` waits for it to finish before configuring
anything.

After install, Magento is available at http://localhost:1234/ (admin: http://localhost:1234/admin, credentials: `exampleuser@two.inc` / `examplepassword123`).

To use a different port: `make install PORT=5678`.

By default, the plugin points at Two's staging environment for `@two.inc` gcloud accounts, or sandbox for everyone else. You can override the API and checkout URLs explicitly:

```bash
make install TWO_API_BASE_URL=http://localhost:8000 TWO_CHECKOUT_BASE_URL=http://localhost:3000
```

In production mode these are ignored — the URLs are derived from the `mode` setting in the admin panel (sandbox/staging/production).

`make install`, `make run` and `make debug` print the resolved API / checkout-page hosts (honouring the overrides above and the developer-mode gate) in their status block, so you can see at a glance which real hosts your local instance will actually talk to without having to run `dev/probe-hosts.php` yourself.

Run `make help` to see all available targets.

### Local-dev perf — disabled modules and what breaks

`make install` runs `module:disable` on a fixed list of modules that aren't needed for plugin development but add significant load to `setup:di:compile` (every module's DI is re-generated) and to the storefront's RequireJS dependency graph (every enabled module's JS gets pulled into the boot, even on pages that don't use it). Disabling them cuts `setup:di:compile` time and drops storefront button-enable latency from ~10s to under a second on the sample-data catalog.

| Module(s) | Why it's disabled in dev |
|---|---|
| `Magento_AdminAdobeImsTwoFactorAuth`, `Magento_TwoFactorAuth` | TOTP setup required on every admin login — friction for local dev |
| `Magento_Analytics`, `Magento_AdminAnalytics`, `Magento_CatalogAnalytics`, `Magento_CustomerAnalytics`, `Magento_QuoteAnalytics`, `Magento_ReviewAnalytics`, `Magento_SalesAnalytics`, `Magento_WishlistAnalytics`, `Magento_GoogleAnalytics`, `Magento_GoogleOptimizer` | JS/tracking hooks that fire on every storefront load |
| `Magento_PageBuilder`, `Magento_PageBuilderAnalytics`, `Magento_CatalogPageBuilderAnalytics`, `Magento_CmsPageBuilderAnalytics`, `Magento_PageBuilderAdminAnalytics`, `Magento_AwsS3PageBuilder` | Loads the full PageBuilder ContentTypes JS tree on **every** storefront page — biggest single contributor to client-side boot time |

`Magento_NewRelicReporting` is **not** disabled — `Magento_GraphQl` declares a hard dependency on it, and disabling it cascades through every GraphQL module. It stays quiet at runtime when un-licensed.

**Consequence:** PageBuilder-driven CMS content (banners, slides, promo blocks edited via the visual editor) **will not render** in a `make install` environment. If you're testing brand content that relies on PageBuilder blocks, re-enable them manually inside the container:

```bash
docker exec magento php bin/magento module:enable Magento_PageBuilder Magento_PageBuilderAnalytics Magento_CatalogPageBuilderAnalytics Magento_CmsPageBuilderAnalytics Magento_PageBuilderAdminAnalytics Magento_AwsS3PageBuilder
docker exec magento php bin/magento setup:upgrade
docker exec magento php bin/magento setup:di:compile
docker exec magento php bin/magento cache:flush
```

Install also runs:

- `config:set dev/js/merge_files=1`, `dev/js/minify_files=1`, `dev/css/merge_css_files=1` — flatten the inline RequireJS bootstrap into a single merged bundle in the HTML.
- `setup:static-content:deploy --area frontend --theme Magento/luma --no-html-minify -f --jobs 4 en_US` — pre-bake the Luma theme so RequireJS's ~hundreds of runtime XHRs hit plain file IO instead of falling through Magento's `pub/static.php` router (a full Magento bootstrap per asset). Without this, on the sample catalog the storefront's "Add to Cart" button-enable latency is ~10s; with it, ~1s warm.

### Brand overlays

Brand-specific overlay packages live in their own Composer packages and are
installed separately from this plugin, not through `make install`. See
`docs/brand-overlay-guide.md` for how overlay modules are structured.

### Debugging

Xdebug is installed automatically by `make install` but is disabled by default. To start in debug mode:

```bash
make debug
```

This activates Xdebug (port 9003) and disables all Magento caches for hot reload — PHP changes, templates, layout XML, and config changes are picked up on the next request without manual cache flushing. The only exception is DI wiring changes (new classes, plugins, or preferences in `di.xml`), which still require `make compile`.

**Setting breakpoints in VSCode:**

1. Install the [PHP Debug](https://marketplace.visualstudio.com/items?itemName=xdebug.php-debug) extension
2. Press **F5** to start listening (uses the included `.vscode/launch.json`)
3. Click the gutter next to any line in the plugin code to set a breakpoint
4. Browse to the Magento store — every request will trigger the debugger automatically

The debugger will pause at your breakpoint with full access to variables, call stack, and step-through execution.

### HTTPS proxy

For testing integrations that require HTTPS callbacks (e.g. the Two checkout flow), you can expose your local instance via an [FRP](https://github.com/fatedier/frp) reverse proxy.

**Setup (one-time):** install the FRP client (`frpc`):

- macOS: `brew install frpc`
- Linux: download from [GitHub releases](https://github.com/fatedier/frp/releases) and place `frpc` on your PATH

**Authentication:**

The proxy needs an `FRP_AUTH_TOKEN` to connect to the FRP server. The `start-proxy.sh` script resolves the token in this order:

1. **Command-line argument:** `./start-proxy.sh <token>`
2. **Environment variable:** `export FRP_AUTH_TOKEN=<token>` (or set it in `.env`)
3. **GCP Secret Manager:** falls back to `gcloud secrets versions access latest --secret=FRP_AUTH_TOKEN --project=two-beta`

Edit `frpc.toml` to point at your FRP server, then provide the token via any of the methods above.

**Usage:**

```bash
# Proxy starts automatically with make run / make debug.
# To run the proxy standalone in the foreground:
make proxy
```

### Tests

```bash
# Unit tests
make test

# End-to-end API tests (requires a valid API key)
make test-e2e TWO_API_KEY=<your-key>
```

### Other useful targets

| Target | Description |
|--------|-------------|
| `make compile` | Recompile Magento DI (after adding/changing PHP classes, plugins, or preferences) |
| `make logs` | Tail the Two plugin debug and error logs |
| `make format` | Run Prettier on frontend JS/CSS/templates |
| `make clean` | Stop and remove the Magento container |

## Releases

The version is computed on the pull request that lands on `staging`, from that PR's own commits. `main` computes nothing — it tags the version already in the tree and cuts the GitHub Release.

### The version-bump convention

| Change | What happens |
|---|---|
| PR into `staging` | the version is computed from that PR's own commits and committed onto the PR's branch (`.github/workflows/version-bump.yml`) |
| merge into `staging` | nothing — the merge brings in the version its PR computed |
| `staging` into `main` | nothing is computed; `main` tags the version already in the tree and cuts the GitHub Release |

With `M` the version on `origin/main` and `C` the version on the PR head, the PR's own commits (`origin/staging..HEAD`, `--no-merges`) decide the candidate: a `!` type or a `BREAKING CHANGE:` footer gives `(M.major + 1).0.0`, a `feat:` gives `M.major.(M.minor + 1).0`, and anything else — `fix` and `chore`/`docs`/`ci`/`test`/`refactor` alike — gives `M.major.M.minor.(M.patch + 1)`. The result is clamped with `max(C, candidate)`, which makes it idempotent (a re-run, the `synchronize` the bump commit itself fires, or a second fix commit on the same PR all write nothing) and means the version can never regress while `main` is behind `staging`.

A **major** is an explicit escape hatch, and overrides the rule above. Two independent signals, the higher wins:

- **Declared** — a root `.next-major` file whose first whitespace-delimited token is the target major, with a short human reason on the same line:

  ```
  3  # overlay migration, 3.0.0 release
  ```

  Reviewable in the PR that decides it, so a *planned* major with no single breaking commit still lands as a major. The file is never cleared by CI: it disarms itself once the major it names has shipped. A declaration that has fallen *below the major on `main`* is a hard CI failure — delete or raise it.

- **Discovered** — a `!` on a conventional-commit type (`feat!:`, `TWO-1/fix(scope)!:`) or a `BREAKING CHANGE:` footer in **this PR's own commits** only. Deliberately not the cumulative `main..staging` range: a break that already landed on `staging` must not be re-discovered by every later PR.

The new version for a major is exactly `<target>.0.0`, so a declaration may skip more than one major.

`.github/scripts/decide-bump-level.sh` owns this decision, is unit-tested by `.github/scripts/test-decide-bump-level.sh`, and is shared byte-identically across the plugin repos. It logs the full decision — inputs included — to the workflow log on every run.

### Bumping (on the PR) and tagging (on `main`)

`.github/workflows/version-bump.yml` runs on every `pull_request` into `staging`. It:

1. Runs the unit tests for the computation, then computes an absolute version with `.github/scripts/decide-bump-level.sh origin/staging HEAD`.
2. If that version differs from the one on the PR head, runs `bumpver update --set-version <X.Y.Z> --no-tag-commit --no-push` to rewrite `composer.json`, `etc/config.xml` and `bumpver.toml`, and pushes the commit onto the PR's own branch under the org GitHub App identity. Otherwise it writes nothing.

The push goes out under the App token rather than `GITHUB_TOKEN` for two reasons: `GITHUB_TOKEN` pushes do not trigger workflows, so CI would never re-run on the bump SHA; and the App holds the ruleset bypass. Because the commit lands on a feature branch, it is outside `terraform-managed-branch-protection` (which targets only `refs/heads/{main,release,staging}`) entirely.

`.github/workflows/release.yml` is triggered by the `CI` workflow completing on `main`. When CI's conclusion is `success`, it:

1. Skips itself if the branch tip drifted from the SHA CI signed off on, or if the SHA already carries a numeric tag. (That last check is what makes the merge-back a no-op: after a `main` release fast-forwards into `staging`, staging's tip already carries the tag.)
2. Reads the version out of `bumpver.toml` — it does not compute or bump one.
3. Tags `X.Y.Z` (bare numeric, matching the established tag convention), pushes the tag, and creates a GitHub Release with a bucketed changelog (Breaking / Features / Fixes / Internals / Other).

`.github/workflows/merge-back.yml` keeps `staging` fast-forwarded to match `main` after each release (falling back to a sync PR if the two have diverged).

`.github/workflows/auto-pr.yml` runs on every push to `staging` (a merge is a push) and keeps a single rolling `staging → main` promotion PR open, no-opping when one already exists or when `staging` is not ahead of `main`.

To trigger a release, merge that `staging → main` PR. CI runs on the merged commit; once green, `release.yml` fires.

`.github/workflows/release-dispatch.yml` fires on the published Release and notifies the infrastructure repository, which resolves the newly published version and raises the pull request that moves the release-tracking Magento staging shop onto it. Because that shop installs from Packagist, a dispatch can arrive before Packagist has indexed the new tag; the infrastructure repository's daily reconcile covers that case, so nothing here needs to wait or retry (TWO-25769).

## Links

- [Two developer documentation](https://docs.two.inc/)
- [Magento plugin setup guide](https://docs.two.inc/developer-portal/plugins/magento)

## License

OSL-3.0 / AFL-3.0. See [composer.json](composer.json) for details.
