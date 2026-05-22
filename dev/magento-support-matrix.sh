#!/usr/bin/env bash
# dev/magento-support-matrix.sh — discover the install-smoke / PHPStan
# matrix dynamically from upstream.
#
# Usage:
#   ./dev/magento-support-matrix.sh                       # human-readable report
#   ./dev/magento-support-matrix.sh --emit-matrix         # GHA matrix JSON: Magento × PHP cross-product
#   ./dev/magento-support-matrix.sh --emit-php-lint-matrix # GHA matrix JSON: PHP-only (for the PHP lint job)
#
# Strategy:
#   1. Query github.com/magento/magento2 tags for bare-semver 2.4.x.
#   2. Apply Adobe's lifecycle policy: current minor + N previous minors
#      (default 2, ie. a 3-minor support window).
#   3. Drop any version on `intentionally_excluded` (e.g. docker image
#      not yet published by the CI-image maintainer).
#   4. For each remaining Magento minor: fetch its raw composer.json
#      and parse `require.php` to get the PHP constraint (e.g.
#      "~8.2.0||~8.3.0||~8.4.0" → minors [8.2, 8.3, 8.4]).
#   5. Query php.net/releases for currently-supported PHP minors
#      (active + security). Intersect with the per-Magento constraint
#      to drop combinations PHP itself no longer supports.
#   6. Emit the cross-product as a GHA matrix.
#
# This replaces the hand-maintained EOL list AND the hand-maintained
# min-PHP map with discovery from upstream (Doug 2026-05-22 follow-up
# to r5 #10). The script is the single source of truth for which
# Magento × PHP combinations CI exercises.

set -euo pipefail

# How many minor lines (current + previous) we claim to support. Adobe's
# policy is "current + previous 2" → 3 total.
SUPPORT_WINDOW=${SUPPORT_WINDOW:-3}

# Versions Magento has published upstream but we cannot test yet
# (e.g. michielgerritsen/magento-project-community-edition image not
# built for that combination). Format: `<version>:<reason>`. Each entry
# documents WHY a version is excluded so future maintainers know when
# the exclusion can drop.
intentionally_excluded=(
    "2.4.9:michielgerritsen/magento-project-community-edition image not yet published for php83-fpm-magento2.4.9"
)

mode=report
case "${1:-}" in
    --emit-matrix) mode=matrix ;;
    --emit-php-lint-matrix) mode=php_lint ;;
    "") mode=report ;;
    *) echo "Unknown flag: $1" >&2; exit 2 ;;
esac

log() {
    [ "$mode" = "report" ] || return 0
    echo "$@"
}

# Authorise GitHub API when a token is available to dodge anon rate-limit.
gh_headers=()
[ -n "${GH_TOKEN:-${GITHUB_TOKEN:-}}" ] \
    && gh_headers=(-H "Authorization: Bearer ${GH_TOKEN:-$GITHUB_TOKEN}")

# ---------------------------------------------------------------------------
# Step 1: discover Magento support window from upstream tags.
# ---------------------------------------------------------------------------
tags_json=$(curl -sH 'Cache-Control: no-cache' "${gh_headers[@]}" \
    'https://api.github.com/repos/magento/magento2/tags?per_page=100' \
    || { echo "::error::Could not reach github.com/magento/magento2 tags" >&2; exit 2; })

all_minors=$(echo "$tags_json" \
    | jq -r 'map(.name)
             | map(select(test("^2\\.4\\.[0-9]+$")))
             | sort_by(. | split(".") | map(tonumber))
             | reverse
             | .[]')

if [ -z "$all_minors" ]; then
    echo "::error::No bare-semver 2.4.x tags found on github.com/magento/magento2" >&2
    exit 2
fi

supported=$(echo "$all_minors" | head -n "$SUPPORT_WINDOW")
log "Magento support window ($SUPPORT_WINDOW most-recent minors):"
log "$supported" | sed 's/^/  /'

# Build excluded map.
declare -A excluded_map=()
for entry in "${intentionally_excluded[@]:-}"; do
    [ -z "$entry" ] && continue
    ver="${entry%%:*}"
    reason="${entry#*:}"
    excluded_map["$ver"]="$reason"
done

# ---------------------------------------------------------------------------
# Step 2: discover currently-supported PHP minors from php.net.
# ---------------------------------------------------------------------------
php_releases_json=$(curl -sH 'Cache-Control: no-cache' \
    'https://www.php.net/releases/?json' \
    || { echo "::error::Could not reach php.net/releases JSON feed" >&2; exit 2; })

# Union of `supported_versions` across all majors, e.g. ["8.2","8.3","8.4","8.5"].
supported_php_minors=$(echo "$php_releases_json" \
    | jq -r '[.[].supported_versions[]?] | unique | .[]' \
    | sort -V)

if [ -z "$supported_php_minors" ]; then
    echo "::error::php.net/releases returned no currently-supported PHP minors" >&2
    exit 2
fi

log "Currently-supported PHP minors (php.net):"
log "$supported_php_minors" | sed 's/^/  /'

# ---------------------------------------------------------------------------
# Step 3: for each Magento minor, fetch composer.json and parse php constraint.
#
# Magento's constraint format is "~8.2.0||~8.3.0||~8.4.0" — one tilde
# range per supported minor, OR-joined. `~8.X.0` means ">=8.X.0,<8.(X+1).0",
# so each clause uniquely identifies one PHP minor.
# ---------------------------------------------------------------------------
declare -A magento_php_minors=()  # magento_minor → space-separated PHP minors
for minor in $supported; do
    [ -n "${excluded_map[$minor]:-}" ] && continue
    composer_json=$(curl -sH 'Cache-Control: no-cache' "${gh_headers[@]}" \
        "https://raw.githubusercontent.com/magento/magento2/$minor/composer.json")
    php_constraint=$(echo "$composer_json" | jq -r '.require.php // empty')
    if [ -z "$php_constraint" ]; then
        echo "::error::magento/magento2@$minor composer.json missing require.php" >&2
        exit 2
    fi
    # Extract every "~X.Y.0" clause → "X.Y".
    php_minors_for_magento=$(echo "$php_constraint" \
        | grep -oE '~[0-9]+\.[0-9]+\.0' \
        | sed -E 's/~([0-9]+\.[0-9]+)\.0/\1/' \
        | sort -V)
    if [ -z "$php_minors_for_magento" ]; then
        echo "::error::Could not parse php constraint '$php_constraint' for Magento $minor" >&2
        exit 2
    fi
    magento_php_minors["$minor"]=$(echo $php_minors_for_magento)
    log "  $minor accepts PHP: ${magento_php_minors[$minor]} (from '$php_constraint')"
done

# ---------------------------------------------------------------------------
# Step 4: build the cross-product matrix, intersected with PHP support.
# ---------------------------------------------------------------------------
matrix_entries=()
excluded_warnings=()
declare -A php_lint_minors=()  # union of PHP minors actually emitted

for minor in $supported; do
    if [ -n "${excluded_map[$minor]:-}" ]; then
        excluded_warnings+=("$minor — ${excluded_map[$minor]}")
        continue
    fi
    accepted="${magento_php_minors[$minor]:-}"
    [ -z "$accepted" ] && continue

    # Intersect Magento's accepted PHP with php.net-supported PHP.
    matched=()
    for php in $accepted; do
        if echo "$supported_php_minors" | grep -qxF "$php"; then
            matched+=("$php")
        fi
    done
    if [ ${#matched[@]} -eq 0 ]; then
        excluded_warnings+=("$minor — every PHP in '$accepted' is past upstream PHP support")
        continue
    fi

    for php in "${matched[@]}"; do
        php_image="php$(echo "$php" | tr -d '.')-fpm"
        matrix_entries+=("$(jq -nc \
            --arg magento "$minor" \
            --arg php "$php" \
            --arg php_image "$php_image" \
            '{magento: $magento, php: $php, php_image: $php_image}')")
        php_lint_minors["$php"]=1
    done
done

if [ ${#excluded_warnings[@]} -gt 0 ]; then
    log "Excluded from this run:"
    for w in "${excluded_warnings[@]}"; do
        log "  $w"
    done
fi

if [ ${#matrix_entries[@]} -eq 0 ]; then
    echo "::error::Support matrix is empty — every supported minor is excluded" >&2
    exit 1
fi

matrix_json=$(printf '%s\n' "${matrix_entries[@]}" | jq -sc '.')
php_lint_json=$(printf '%s\n' "${!php_lint_minors[@]}" \
    | sort -V \
    | jq -R . | jq -sc 'map({php: .})')

case "$mode" in
    matrix)
        echo "$matrix_json"
        ;;
    php_lint)
        echo "$php_lint_json"
        ;;
    report)
        echo "Install-smoke / PHPStan matrix:"
        echo "$matrix_json" | jq .
        echo ""
        echo "PHP lint matrix:"
        echo "$php_lint_json" | jq .
        echo ""
        echo "magento-support-matrix OK."
        ;;
esac
