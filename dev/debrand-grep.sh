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

# `Two\…` and `…\Two` are FQCN segments naming real classes.
FORBIDDEN = re.compile(r'(?<!\\)\bTwo\b(?!\\)')
# `Two.inc` is the legal entity in a copyright header, the one comment shape
# the markup line-grep cannot blank away.
MARKUP_FORBIDDEN = re.compile(r'(?<!\\)\bTwo\b(?!\.inc\b|\\)')

MARKUP_COMMENT = re.compile(r'<!--.*?-->', re.S)
# Knockout virtual elements are comments that render.
KO_VIRTUAL = re.compile(r'<!--\s*/?ko[\s:>-]')
PHP_BLOCK = re.compile(r'<\?(?:php\b|=).*?(?:\?>|\Z)', re.S)

# One left-to-right pass over every construct an apostrophe can hide in, so
# none can open another. `skip` is blanked on the msgid pass too — a construct
# that can never be a msgid.
HEREDOC = r"""<<<(['"]?)(\w+)\1\r?\n.*?^[ \t]*\2\b"""
# `i++ /` is a division; a bare `+` before one is `'a' + /re/.source`.
JS_REGEX = r"""(?<!\+\+)(?<=[=(,:\[!&|?{};+~^<>])\s*/(?![/*])(?:\\.|\[(?:\\.|[^\]\n\\])*\]|[^/\n\\])+/[a-z]*"""
PHP_TOKEN = re.compile(
    HEREDOC + r"""|'(?:\\.|[^'\\])*'|"(?:\\.|[^"\\])*\""""
    r"""|(?P<skip>/\*.*?\*/|//[^\n]*|\#(?!\[)[^\n]*)""", re.S | re.M)
JS_TOKEN = re.compile(
    r"""'(?:\\.|[^'\\])*'|"(?:\\.|[^"\\])*"|`(?:\\.|[^`\\])*`"""
    r"""|(?P<skip>""" + JS_REGEX + r"""|/\*.*?\*/|//[^\n]*)""", re.S)
PHP_LITERAL = re.compile(
    HEREDOC.replace(r'.*?^', r'(.*?)^') + r"""|'((?:\\.|[^'\\])*)'|"((?:\\.|[^"\\])*)\"""",
    re.S | re.M)
JS_LITERAL = re.compile(r"""'((?:\\.|[^'\\])*)'|"((?:\\.|[^"\\])*)"|`((?:\\.|[^`\\])*)`""", re.S)

# Luma's `$t`, jQuery's `$.mage.__`, and the translator the vendored
# company-search modules take by injection.
JS_CALL = r'(?:\$t|\$\.mage\.__|\.translate)\s*\('


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


def blanked(src, token, comments_only):
    """Same length, same newlines, so reported line numbers stay exact."""
    def repl(match):
        if comments_only and not match.group('skip'):
            return match.group(0)
        return re.sub(r'[^\n]', ' ', match.group(0))
    return token.sub(repl, src)


def markup_source(path):
    raw = read(path)
    if path.endswith('.phtml'):
        raw = PHP_BLOCK.sub(
            lambda m: blanked(m.group(0), PHP_TOKEN, comments_only=True), raw)

    def strip(match):
        if KO_VIRTUAL.match(match.group(0)):
            return match.group(0)
        return '\n' * match.group(0).count('\n')
    return MARKUP_COMMENT.sub(strip, raw)


def translated_literals(src, code, call, literal):
    """Every string literal inside a `call( … )`, paren-balanced so a
    concatenated or multi-line msgid is read whole. Call sites and parens are
    located in `code`, where strings are blank, so neither a `__(` nor a
    bracket inside a literal can be mistaken for the real thing."""
    for opener in re.finditer(call, code):
        i, depth = opener.end(), 1
        while i < len(code) and depth:
            depth += (code[i] == '(') - (code[i] == ')')
            i += 1
        for m in literal.finditer(src, opener.end(), i - 1):
            yield m.start(), m.group(m.lastindex)


def every_literal(src, literal):
    for m in literal.finditer(src):
        yield m.start(), m.group(m.lastindex)


def read(path):
    with open(path, encoding='utf-8', errors='replace') as fh:
        return fh.read()


found = []


def scan(path, token, literals):
    raw = read(path)
    src = blanked(raw, token, comments_only=True)
    code = blanked(raw, token, comments_only=False)
    for offset, text in literals(src, code):
        if FORBIDDEN.search(text):
            found.append('%s:%d: %s' % (path, src[:offset].count('\n') + 1, text.strip()[:160]))


for path in markup_files():
    for n, line in enumerate(markup_source(path).splitlines(), start=1):
        if MARKUP_FORBIDDEN.search(line):
            found.append('%s:%d: %s' % (path, n, line.strip()[:160]))

# A plain PHP literal reaches a logger, an exception or a status-history
# comment without ever passing through `__()`.
for path in source_files('.php'):
    scan(path, PHP_TOKEN, lambda src, code: every_literal(src, PHP_LITERAL))

for path in source_files('.js', glob.glob('view/*/web/js')):
    scan(path, JS_TOKEN,
         lambda src, code: translated_literals(src, code, JS_CALL, JS_LITERAL))

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
