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


def remove_orphans(page):
    """Drop any <li> entry whose playground/<name>.html no longer exists (block was deleted).
    Only touches playground-linked entries; curated rule/asset links (no playground/) are safe,
    and curated blocks whose playground still exists are kept."""
    removed = []

    def repl(m):
        block = m.group(0)
        pm = re.search(r"playground/([a-z0-9_-]+)\.html", block)
        if pm and not os.path.exists(f"{PLAYGROUND_DIR}/{pm.group(1)}.html"):
            removed.append(pm.group(1))
            return ""
        return block

    page = re.sub(r"[ \t]*<li>[\s\S]*?</li>\n", repl, page)
    for r in removed:
        print(f"  - index entry removed (block deleted): {r}")
    return page, removed


def main():
    page = open(INDEX, encoding="utf-8").read()

    # 1. remove entries for blocks that were deleted
    page, removed = remove_orphans(page)

    # 2. add entries for blocks not yet listed
    linked = set(re.findall(r"playground/([a-z0-9_-]+)\.html", page))
    playgrounds = sorted(
        os.path.basename(p)[:-5] for p in glob.glob(f"{PLAYGROUND_DIR}/*.html")
    )
    missing = [s for s in playgrounds if s not in linked]
    new_entries = ""
    if missing:
        if MARKER not in page:
            sys.exit("::error::index.html is missing the auto-blocks marker comment; cannot insert.")
        for slug in missing:
            kind, desc = kind_and_desc(slug)
            new_entries += entry_html(slug, kind, desc)
            print(f"  + index entry added: {slug} ({kind})")
        idx = page.index(MARKER)
        line_start = page.rfind("\n", 0, idx) + 1  # insert on whole lines, before the marker
        page = page[:line_start] + new_entries + page[line_start:]

    if not missing and not removed:
        print("index.html is up to date — every block is listed, no orphans.")
        return 0
    with open(INDEX, "w", encoding="utf-8") as f:
        f.write(page)
    print(f"index.html updated (+{len(missing)} added, -{len(removed)} removed).")
    return 0


if __name__ == "__main__":
    sys.exit(main())
