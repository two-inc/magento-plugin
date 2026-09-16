#!/usr/bin/env bash
# CI guard: no hardcoded brand name on a surface a brand overlay cannot debrand.
#
# An overlay debrands by rebinding BrandRegistryInterface and by {{provider}}
# synthesis of the admin form, so a literal "Two" in static XML or in a `__()`
# msgid shows in EVERY locale, English included — the English msgid itself
# carries the brand, so no i18n/*.csv row can reach it. The fix is a %N filled
# from the brand registry; Model/Ui/CheckoutTileCopy is the pattern.
#
# Not gated, by design: etc/adminhtml/system.xml is the vanilla Two form, which
# an overlay replaces wholesale via deepMergeOverlay rather than translating;
# etc/db_schema.xml holds DB column comments no user ever sees.

set -euo pipefail

cd "$(cd "$(dirname "$0")/.." && pwd)"

PYTHON=$(command -v python3) \
    || { echo "ERROR: python3 required but missing"; exit 4; }

hits=$("$PYTHON" - <<'PYEOF'
import glob
import os
import re

# `Two.inc` is the legal entity in copyright headers; `Two\…` and `…\Two` are
# FQCN segments naming real classes.
FORBIDDEN = re.compile(r'(?<!\\)\bTwo\b(?!\.inc\b|\\)')
XML_COMMENT = re.compile(r'<!--.*?-->', re.S)
LITERAL = re.compile(r"""'((?:\\.|[^'\\])*)'|"((?:\\.|[^"\\])*)\"""", re.S)


def markup_files():
    paths = glob.glob('etc/cache.xml')
    paths += [p for p in glob.glob('etc/adminhtml/*.xml')
              if os.path.basename(p) != 'system.xml']
    for pattern in ('view/**/ui_component/*.xml',
                    'view/**/layout/*.xml',
                    'view/**/*.phtml'):
        paths += glob.glob(pattern, recursive=True)
    return sorted(paths)


def php_files():
    # Test/ and e2e/ assert the vanilla brand's own strings.
    skip = {'vendor', 'node_modules', '.git', '.worktrees', 'Test', 'e2e'}
    paths = []
    for root, dirs, files in os.walk('.'):
        dirs[:] = [d for d in dirs if d not in skip]
        paths += [os.path.join(root, f)[2:] for f in files if f.endswith('.php')]
    return sorted(paths)


def translated_literals(src):
    """Every string literal inside a `__( … )` call, paren-balanced so a
    concatenated or multi-line msgid is read whole."""
    for call in re.finditer(r'\b__\s*\(', src):
        i, depth = call.end(), 1
        while i < len(src) and depth:
            if src[i] in '\'"':
                m = LITERAL.match(src, i)
                i = m.end() if m else i + 1
                continue
            depth += (src[i] == '(') - (src[i] == ')')
            i += 1
        yield call.start(), src[call.end():i - 1]


def read(path):
    with open(path, encoding='utf-8', errors='replace') as fh:
        return fh.read()


found = []

for path in markup_files():
    src = XML_COMMENT.sub(lambda m: '\n' * m.group(0).count('\n'), read(path))
    for n, line in enumerate(src.splitlines(), start=1):
        if FORBIDDEN.search(line):
            found.append('%s:%d: %s' % (path, n, line.strip()[:160]))

for path in php_files():
    src = read(path)
    for offset, args in translated_literals(src):
        for m in LITERAL.finditer(args):
            literal = m.group(1) if m.group(1) is not None else m.group(2)
            if FORBIDDEN.search(literal):
                line = src[:offset].count('\n') + 1
                found.append('%s:%d: %s' % (path, line, literal.strip()[:160]))

print('\n'.join(found))
PYEOF
)

if [ -n "$hits" ]; then
    echo "::error::debrand-grep: hardcoded brand name on a surface an overlay cannot debrand:"
    printf '%s\n' "$hits" | sed 's/^/  /'
    echo "Fill a %N from BrandRegistryInterface instead — see Model/Ui/CheckoutTileCopy."
    exit 1
fi

echo "debrand-grep OK: no hardcoded brand name in gated surfaces."
