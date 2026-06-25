#!/usr/bin/env python3
"""
figma-pipeline/generate_index.py

Keeps the top-level index.html in sync with the blocks — APPEND-ONLY. For every block that has
a playground but no link in index.html yet, it inserts one entry (matching the existing markup)
at the auto-blocks marker. It NEVER edits or removes existing entries, so the hand-curated
titles/descriptions in index.html are preserved; new blocks just get a serviceable auto-entry
you can polish later.

Run after a block is built (the figma-build workflow does this). Idempotent: a block already
linked in the index is skipped, so refreshes don't duplicate anything.

The description for a new entry comes from the block's own `<!-- section-desc: ... -->` comment;
the title is derived from the filename. Zero dependencies (stdlib only).
"""

import glob
import html as html_lib
import os
import re
import sys

INDEX = "index.html"
PLAYGROUND_DIR = "email-design-system/playground"
SECTIONS_DIR = "email-design-system/sections"
COMPONENTS_DIR = "email-design-system/components"
MARKER = "<!-- figma-auto-blocks:"


def kind_and_desc(slug):
    """Find the block file for this playground slug; return (kind, description-from-comment)."""
    for directory, prefix, kind in (
        (SECTIONS_DIR, "section_", "section"),
        (COMPONENTS_DIR, "component_", "component"),
    ):
        path = os.path.join(directory, f"{prefix}{slug}.html")
        if os.path.isfile(path):
            text = open(path, encoding="utf-8").read()
            m = re.search(r"<!--\s*section-desc:\s*(.+?)-->", text, re.S)
            desc = " ".join(m.group(1).split()) if m else ""
            return kind, desc
    return "section", ""  # playground with no matching block file: default


def title_for(slug, kind):
    words = slug.replace("_", " ").replace("-", " ").strip()
    title = words[:1].upper() + words[1:]
    return f"{title} — {kind} · interactive preview"


def entry_html(slug, kind, desc):
    href = f"/halo-resources/{PLAYGROUND_DIR}/{slug}.html"
    return (
        "        <li>\n"
        f'          <a href="{href}">\n'
        f"            {html_lib.escape(title_for(slug, kind))}\n"
        f'            <span class="desc">{html_lib.escape(desc)}</span>\n'
        "          </a>\n"
        "        </li>\n"
    )


def main():
    page = open(INDEX, encoding="utf-8").read()
    linked = set(re.findall(r"playground/([a-z0-9_-]+)\.html", page))
    playgrounds = sorted(
        os.path.basename(p)[:-5] for p in glob.glob(f"{PLAYGROUND_DIR}/*.html")
    )
    missing = [s for s in playgrounds if s not in linked]
    if not missing:
        print("index.html is up to date — every block is already listed.")
        return 0
    if MARKER not in page:
        sys.exit("::error::index.html is missing the auto-blocks marker comment; cannot insert.")

    new_entries = ""
    for slug in missing:
        kind, desc = kind_and_desc(slug)
        new_entries += entry_html(slug, kind, desc)
        print(f"  + index entry added: {slug} ({kind})")

    idx = page.index(MARKER)
    line_start = page.rfind("\n", 0, idx) + 1  # insert on whole lines, before the marker line
    page = page[:line_start] + new_entries + page[line_start:]
    with open(INDEX, "w", encoding="utf-8") as f:
        f.write(page)
    print(f"index.html updated (+{len(missing)} entr{'y' if len(missing) == 1 else 'ies'}).")
    return 0


if __name__ == "__main__":
    sys.exit(main())
