#!/usr/bin/env bash
#
# Prints the API / checkout-page hosts the RUNNING container is actually
# configured to hit, by execing dev/probe-hosts.php inside the container and
# reformatting its output.
#
# Runs inside the container (not on the host) because developer mode and the
# TWO_*_BASE_URL overrides are read from the container's own process
# environment, baked in at container creation - see "Service URL overrides"
# in README.md. dev/probe-hosts.php is the one place that resolution logic
# lives (Model\Config\Repository::getCheckoutApiUrl/getCheckoutPageUrl);
# this script only parses its output, it does not re-derive the
# override-vs-default logic.
#
# There is no merchant-portal URL concept in this plugin (unlike PrestaShop's
# TWO_PORTAL_BASE_URL) - only the checkout API and the hosted checkout-page
# app are reported here.
#
# Usage: dev/print-resolved-hosts.sh <container-name>
# Prints nothing (and exits 0) if the container isn't reachable - callers
# use this for a "nice to have" status block, not a hard dependency.
set -euo pipefail

CONTAINER="$1"

DUMP=$(docker exec "$CONTAINER" php /data/extensions/workdir/dev/probe-hosts.php 2>/dev/null) || exit 0

API=$(sed -n 's/^getCheckoutApiUrl(): //p' <<< "$DUMP")
CHECKOUT=$(sed -n 's/^getCheckoutPageUrl(): //p' <<< "$DUMP")

[ -n "$API" ] && echo " API:               $API"
[ -n "$CHECKOUT" ] && echo " Checkout (signup): $CHECKOUT"

exit 0
