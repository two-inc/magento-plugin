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

## A surcharge cap of 0 is valid — never guard against it

**A configured surcharge limit of `0` must be relayed to the pricing
API as `cap => 0.0`. Do not throw, do not omit the key, and do not
add admin validation rejecting a typed `0`.** A zero cap clamps the
buyer fee to zero — no surcharge is applied — which is a legitimate
merchant configuration.

Only an *absent* (null) limit means "no cap"; that omits the `cap`
key and applies the percentage uncapped. Absent and zero are
different things and both must pass through faithfully.

TWO-25269 briefly added a guard that threw on a zero cap, on the
premise that a zero cap read as "no cap" downstream and would relay
an uncapped percentage. **That premise was false and the guard was
reverted** — a zero cap clamps the fee to zero, it never uncaps it.
In `fixed_and_percentage` mode the cap bounds the combined fee, so
`Limit = 0` suppresses the fixed component too, not just the
percentage part.

`Test/Unit/Service/Order/SurchargeCalculatorTest.php` pins this.

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
