---
description: 'Popup detection and what jsdom can and cannot verify about keyboard navigation.'
---

# Frontend testing traps

## A popup window is in no tab listing

`window.open` returns a window outside a browser extension's tab group, so a
tab list can never answer "did the popup open" — nor can a hang. The
authoritative check is the page's own retained handle and its `.closed`, which
means wrapping `window.open` before the action that should raise one. Judging
from a tab list yields a confident false "no window opened".

## jsdom cannot verify keyboard navigation

jsdom implements no sequential focus navigation: a dispatched `Tab` keydown
moves focus nowhere, so no jsdom suite can observe a focus trap, a wrong tab
order or a reverse-Tab dead end, however many cases it carries and however
green it is. Assert the observable proxy instead — that the handler leaves the
event undefaulted, that the control's parts are one contiguous run in document
order, that a closed panel carries `hidden` — and say in the suite that the
keyboard behaviour itself is verified in a real browser. A passing jsdom Tab
test is never evidence that a trap is absent. Focus a handler moves ITSELF, with
`element.focus()`, is the exception: jsdom performs that, so arrow-key traversal
inside a composite control is directly observable where Tab order is not.

Three traps in the same suites:

-   **A real chip click fires no `focusin`.** The chip's `mousedown` handler calls
    `preventDefault()`, which suppresses the native focus, so a rule written only
    against `focusin` never sees a pointer buyer at all.
-   **jsdom's `getElementById` answers with the first-REGISTERED node, not the
    tree-first one**, so a fixture carrying a duplicate id silently resolves to
    the wrong element.
-   **A mutation proves NEW coverage only when re-run against the base ref.** One
    the existing suite already catches proves the suite is sensitive, not that the
    case added covers anything.
