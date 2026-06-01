#!/usr/bin/env python3
"""
add_frontmatter.py — one-time use.

Adds empty Jekyll front matter (---\\n---) to the top of every markdown file
in the repo that doesn't already have it. This is what tells Jekyll to process
the file as a page (and apply the default layout).

Run from the repo root:

    python3 add_frontmatter.py

Safe to run multiple times — files that already have front matter are skipped.
"""

import sys
from pathlib import Path

EXCLUDED_DIRS = {"_site", "_layouts", "_includes", "test", ".git", "node_modules", "__pycache__"}

def has_frontmatter(text):
    """A file has Jekyll front matter if its very first line is '---'."""
    return text.startswith("---\n") or text.startswith("---\r\n")

def main():
    repo_root = Path(__file__).resolve().parent
    changed = []
    skipped = []

    for md in repo_root.rglob("*.md"):
        # Skip excluded dirs
        if any(part in EXCLUDED_DIRS for part in md.parts):
            continue

        try:
            text = md.read_text(encoding="utf-8")
        except Exception as e:
            print(f"  [error] {md.relative_to(repo_root)}: {e}")
            continue

        if has_frontmatter(text):
            skipped.append(md.relative_to(repo_root))
            continue

        new_text = "---\n---\n" + text
        md.write_text(new_text, encoding="utf-8")
        changed.append(md.relative_to(repo_root))

    print(f"Added front matter to {len(changed)} file(s):")
    for p in changed:
        print(f"  + {p}")

    if skipped:
        print(f"\nSkipped {len(skipped)} file(s) already with front matter:")
        for p in skipped:
            print(f"  = {p}")

    print(f"\nDone. Commit and push to update the live site.")

if __name__ == "__main__":
    main()
