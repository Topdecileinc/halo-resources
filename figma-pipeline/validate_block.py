#!/usr/bin/env python3
"""
figma-pipeline/validate_block.py

The builder's safety gate. Runs on a freshly-generated Figma block (a section_*.html or
component_*.html) BEFORE the pipeline is allowed to commit it. Every check is deterministic
and high-confidence — it must never fail a good block (false positives would block the
pipeline), and it must catch the obvious "this should not get committed / sent" cases:

  1. File is non-empty.
  2. Root class marker is present and matches the filename
     (section_hero_overlay.html -> class="section-hero-overlay").
  3. Sections carry the `<!-- section-desc: ... -->` marker (so the form/index pick them up).
  4. The `figma-source` comment is present with file:, node:, and a real generated-sha
     (64 hex chars, not the GENSHA placeholder) so the block is refreshable.
  5. Every image source is either a [BRACKETED] placeholder or an absolute https:// URL —
     never an empty src, a relative path, or a Figma-internal asset URL (which expires /
     renders broken in email).
  6. No `display:flex` / `display:grid` (not email-safe; emails are table-based).

Exit 0 = pass (safe to commit). Exit 1 = fail (block it). Zero dependencies (stdlib only).

Usage:
  python3 figma-pipeline/validate_block.py email-design-system/sections/section_hero_overlay.html
  python3 figma-pipeline/validate_block.py <one or more block files>
"""

import re
import sys
from pathlib import Path

HEX64 = re.compile(r"^[0-9a-f]{64}$")
FIGMA_SOURCE = re.compile(r"<!--\s*figma-source\b(.*?)-->", re.DOTALL)
# image-bearing values: src="...", background="...", and url(...) inside styles
SRC_ATTR = re.compile(r'\b(?:src|background)\s*=\s*"([^"]*)"', re.IGNORECASE)
CSS_URL = re.compile(r"url\(\s*['\"]?([^'\")]*)", re.IGNORECASE)
BRACKETED = re.compile(r"^\[[A-Z0-9_]+\]$")


def _field(block, name):
    m = re.search(rf"^\s*{re.escape(name)}:\s*(\S+)", block, re.MULTILINE)
    return m.group(1) if m else None


def _expected_class(path):
    """section_hero_overlay.html -> section-hero-overlay ; component_button.html -> component-button."""
    stem = path.stem
    m = re.match(r"(section|component)_(.+)$", stem)
    if not m:
        return None
    return f"{m.group(1)}-{m.group(2).replace('_', '-')}"


def _image_ok(value):
    """A source value is safe if it's a [BRACKETED] placeholder or an absolute https URL."""
    v = value.strip()
    if v == "" or BRACKETED.match(v):
        return v != ""  # placeholder ok; empty not ok
    if "figma.com" in v or "/api/mcp/asset" in v:
        return False
    return v.lower().startswith("https://")


def check(path: Path):
    errors = []
    if not path.is_file():
        return [f"{path}: file not found"]
    text = path.read_text(encoding="utf-8")
    if not text.strip():
        return [f"{path}: file is empty"]

    kind = "section" if path.name.startswith("section_") else \
           "component" if path.name.startswith("component_") else None
    if kind is None:
        return [f"{path}: not a section_*.html or component_*.html block"]

    # 2. root class marker
    expected = _expected_class(path)
    if expected and f'class="{expected}"' not in text:
        errors.append(f'missing root class marker class="{expected}" (derived from filename)')

    # 3. section-desc (sections only)
    if kind == "section" and "<!-- section-desc:" not in text:
        errors.append("section is missing its <!-- section-desc: ... --> marker")

    # 4. figma-source + real generated-sha
    m = FIGMA_SOURCE.search(text)
    if not m:
        errors.append("missing the <!-- figma-source ... --> comment")
    else:
        block = m.group(1)
        if not _field(block, "file") or not _field(block, "node"):
            errors.append("figma-source comment is missing file: or node:")
        sha = _field(block, "generated-sha")
        if not sha or sha == "GENSHA" or not HEX64.match(sha):
            errors.append(f"figma-source generated-sha is not a real sha256 (got: {sha})")

    # 5. image sources
    for value in SRC_ATTR.findall(text) + CSS_URL.findall(text):
        if not _image_ok(value):
            errors.append(f"unsafe image source (not a placeholder or absolute https URL): {value!r}")

    # 6. no flex/grid
    if re.search(r"display\s*:\s*(flex|grid)", text, re.IGNORECASE):
        errors.append("uses display:flex/grid (not email-safe; emails must be table-based)")

    return [f"{path}: {e}" for e in errors]


def main(argv):
    if not argv:
        print("usage: validate_block.py <block.html> [block.html ...]")
        return 2
    all_errors = []
    for arg in argv:
        errs = check(Path(arg))
        if errs:
            all_errors += errs
        else:
            print(f"[PASS] {arg}")
    if all_errors:
        print("\n[FAIL] block validation:")
        for e in all_errors:
            print(f"  - {e}")
        return 1
    print("\nAll blocks passed.")
    return 0


if __name__ == "__main__":
    sys.exit(main(sys.argv[1:]))
