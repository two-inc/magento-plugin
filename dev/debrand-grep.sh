#!/usr/bin/env bash
# CI guard: no hardcoded brand name on a surface a brand overlay cannot debrand.
# Rationale and the gated/ungated surface list: docs/brand-overlay-guide.md.

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
MARKUP_COMMENT = re.compile(r'<!--.*?-->', re.S)

# One left-to-right pass over strings AND comments together: a `//` inside a
# string is consumed by the string alternative, and an apostrophe inside a
# comment by the comment alternative, so neither can open the other.
PHP_TOKEN = re.compile(r"""'(?:\\.|[^'\\])*'|"(?:\\.|[^"\\])*"|(/\*.*?\*/|//[^\n]*|\#(?!\[)[^\n]*)""", re.S)
JS_TOKEN = re.compile(r"""'(?:\\.|[^'\\])*'|"(?:\\.|[^"\\])*"|`(?:\\.|[^`\\])*`|(/\*.*?\*/|//[^\n]*)""", re.S)
PHP_LITERAL = re.compile(r"""'((?:\\.|[^'\\])*)'|"((?:\\.|[^"\\])*)\"""", re.S)
JS_LITERAL = re.compile(r"""'((?:\\.|[^'\\])*)'|"((?:\\.|[^"\\])*)"|`((?:\\.|[^`\\])*)`""", re.S)


def markup_files():
    paths = glob.glob('etc/cache.xml')
    paths += [p for p in glob.glob('etc/adminhtml/*.xml')
              if os.path.basename(p) != 'system.xml']
    for pattern in ('view/**/ui_component/*.xml',
                    'view/**/layout/*.xml',
                    'view/**/*.phtml',
                    'view/**/web/template/**/*.html'):
        paths += glob.glob(pattern, recursive=True)
    return sorted(set(paths))


def source_files(suffix, roots=('.',)):
    # Test/ and e2e/ assert the vanilla brand's own strings.
    skip = {'vendor', 'node_modules', '.git', '.worktrees', 'Test', 'e2e'}
    paths = []
    for root in roots:
        for parent, dirs, files in os.walk(root):
            dirs[:] = [d for d in dirs if d not in skip]
            paths += [os.path.relpath(os.path.join(parent, f))
                      for f in files if f.endswith(suffix)]
    return sorted(paths)


def blank(match):
    """Same length, same newlines, so reported line numbers stay exact."""
    return re.sub(r'[^\n]', ' ', match.group(0)) if match.group(1) else match.group(0)


def translated_literals(src, call, literal):
    """Every string literal inside a `call( … )`, paren-balanced so a
    concatenated or multi-line msgid is read whole. Yields absolute offsets."""
    for opener in re.finditer(call, src):
        i, depth = opener.end(), 1
        while i < len(src) and depth:
            if src[i] in '\'"`':
                m = literal.match(src, i)
                i = m.end() if m else i + 1
                continue
            depth += (src[i] == '(') - (src[i] == ')')
            i += 1
        for m in literal.finditer(src, opener.end(), i - 1):
            yield m.start(), next(g for g in m.groups() if g is not None)


def read(path):
    with open(path, encoding='utf-8', errors='replace') as fh:
        return fh.read()


found = []


def scan(path, token, call, literal):
    src = token.sub(blank, read(path))
    for offset, text in translated_literals(src, call, literal):
        if FORBIDDEN.search(text):
            found.append('%s:%d: %s' % (path, src[:offset].count('\n') + 1, text.strip()[:160]))


for path in markup_files():
    src = MARKUP_COMMENT.sub(lambda m: '\n' * m.group(0).count('\n'), read(path))
    for n, line in enumerate(src.splitlines(), start=1):
        if FORBIDDEN.search(line):
            found.append('%s:%d: %s' % (path, n, line.strip()[:160]))

for path in source_files('.php'):
    scan(path, PHP_TOKEN, r'\b__\s*\(', PHP_LITERAL)

for path in source_files('.js', glob.glob('view/*/web/js')):
    scan(path, JS_TOKEN, r'\$t\s*\(', JS_LITERAL)

print('\n'.join(sorted(found)))
PYEOF
)

if [ -n "$hits" ]; then
    echo "::error::debrand-grep: hardcoded brand name on a surface an overlay cannot debrand:"
    printf '%s\n' "$hits" | sed 's/^/  /'
    echo "Fill a %N from BrandRegistryInterface instead — see Model/Ui/CheckoutTileCopy."
    exit 1
fi

echo "debrand-grep OK: no hardcoded brand name in gated surfaces."
