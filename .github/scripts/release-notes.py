#!/usr/bin/env python3
"""Build bucketed release notes from a git commit range.

Usage:   scripts/release-notes.py <range> <output-file>
Example: scripts/release-notes.py "abn-main..abn-develop" body.md

Writes the bucketed markdown to <output-file>; prints the semver
bump level (major | minor | patch) to stdout.

Used by:

- auto-pr.yml — populates the rolling sync PR's body with a
  preview of the next release.
- release.yml — renders the GitHub Release page's notes after
  the bump.

Bucketing rules — match the conventional-commit type, with an
optional Linear ticket prefix (e.g. ABN-123/feat:):

    Breaking  — <type>!: anywhere on subject, or 'BREAKING CHANGE:' trailer
    Features  — feat[(scope)]:
    Fixes     — fix[(scope)]:
    Internals — chore | refactor | ci | docs | test | build | perf | style
    Other     — anything that doesn't match

Bump level = highest-severity bucket that has at least one entry:

    any Breaking → major
    any Features → minor
    else         → patch
"""

from __future__ import annotations

import re
import subprocess
import sys
from pathlib import Path

# Optional `<TICKET>/` prefix where TICKET is uppercase letters + digits
# (ABN-123, INF-1159, etc.). Optional `(scope)`. Required type, then `:`.
TICKET = r"(?:[A-Z]+-[0-9]+/)?"
SCOPE = r"(?:\([^)]+\))?"

BREAKING = re.compile(rf"^{TICKET}[a-z]+{SCOPE}!:|^BREAKING CHANGE:")
FEATURE = re.compile(rf"^{TICKET}feat{SCOPE}:")
FIX = re.compile(rf"^{TICKET}fix{SCOPE}:")
INTERNAL = re.compile(
    rf"^{TICKET}(?:chore|refactor|ci|docs|test|build|perf|style){SCOPE}:"
)

# Order matters: Breaking is checked before Feature/Fix because
# `feat!:` should land in Breaking, not Features.
BUCKETS: list[tuple[str, str, re.Pattern[str]]] = [
    ("breaking", "## ⚠️ Breaking changes", BREAKING),
    ("features", "## 🚀 Features", FEATURE),
    ("fixes", "## 🐛 Fixes", FIX),
    ("internals", "## 🧰 Internals", INTERNAL),
    # The "other" bucket is the catch-all and has no regex.
]


def git_log(commit_range: str) -> list[tuple[str, str]]:
    """Return list of (short_sha, subject) for non-merge commits in the range."""
    out = subprocess.check_output(
        ["git", "log", commit_range, "--no-merges", "--format=%h%x09%s"],
        text=True,
    )
    rows: list[tuple[str, str]] = []
    for line in out.splitlines():
        if "\t" in line:
            sha, subject = line.split("\t", 1)
            rows.append((sha, subject))
    return rows


def bucket_commits(
    commits: list[tuple[str, str]],
) -> dict[str, list[str]]:
    """Group commits into the named buckets defined by BUCKETS + 'other'."""
    grouped: dict[str, list[str]] = {key: [] for key, _, _ in BUCKETS}
    grouped["other"] = []
    for sha, subject in commits:
        line = f"- {subject} ({sha})"
        for key, _, pattern in BUCKETS:
            if pattern.search(subject):
                grouped[key].append(line)
                break
        else:
            grouped["other"].append(line)
    return grouped


def pick_level(grouped: dict[str, list[str]]) -> str:
    if grouped["breaking"]:
        return "major"
    if grouped["features"]:
        return "minor"
    return "patch"


def render(grouped: dict[str, list[str]]) -> str:
    sections: list[str] = []
    for key, heading, _ in BUCKETS:
        if grouped[key]:
            sections.append(heading)
            sections.extend(grouped[key])
            sections.append("")
    if grouped["other"]:
        sections.append("## Other")
        sections.extend(grouped["other"])
        sections.append("")
    return "\n".join(sections)


def main(argv: list[str]) -> int:
    if len(argv) != 3:
        print(f"usage: {argv[0]} <range> <output-file>", file=sys.stderr)
        return 2
    commit_range, out_path = argv[1], argv[2]

    commits = git_log(commit_range)
    grouped = bucket_commits(commits)
    Path(out_path).write_text(render(grouped))
    print(pick_level(grouped))
    return 0


if __name__ == "__main__":
    sys.exit(main(sys.argv))
