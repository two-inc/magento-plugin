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

## Development

The development environment runs Magento in Docker with the plugin bind-mounted, so file changes are reflected immediately.

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

After install, Magento is available at http://localhost:1234/ (admin: http://localhost:1234/admin, credentials: `exampleuser` / `examplepassword123`).

To use a different port: `make install PORT=5678`.

By default, the plugin points at Two's staging environment for `@two.inc` gcloud accounts, or sandbox for everyone else. You can override the API and checkout URLs explicitly:

```bash
make install TWO_API_BASE_URL=http://localhost:8000 TWO_CHECKOUT_BASE_URL=http://localhost:3000
```

In production mode these are ignored — the URLs are derived from the `mode` setting in the admin panel (sandbox/staging/production).

Run `make help` to see all available targets.

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
2. **Environment variable:** `export FRP_AUTH_TOKEN=<token>` (or set it in `.env.local`)
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

## Links

- [Two developer documentation](https://docs.two.inc/)
- [Magento plugin setup guide](https://docs.two.inc/developer-portal/plugins/magento)

## License

OSL-3.0 / AFL-3.0. See [composer.json](composer.json) for details.
