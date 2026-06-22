#!/usr/bin/env python3
"""
developer-skills/generate_manifest.py

Builds developer-skills/figma_manifest.json from the `figma-source` comments that
the Figma download flow (see rules_figma_download.md) stamps into every downloaded
block. The comments are the single source of truth for the Figma-node -> output-file
mapping; this script just collects them so the webhook receiver / CI can look up
"which file(s) does this published node rebuild" without a human pasting links.

Only blocks that carry a `figma-source` comment appear in the manifest — i.e. only
the ones actually downloaded from Figma (hand-built blocks like the header/footer
are not Figma-sourced and are not rebuilt by the pipeline).

Run from anywhere; paths resolve relative to the repo root (this file's parent's parent):

    python3 developer-skills/generate_manifest.py            # write the manifest
    python3 developer-skills/generate_manifest.py --check    # exit 1 if out of date

Zero dependencies (stdlib only), to match test/.
"""

import argparse
import json
import re
import sys
from pathlib import Path

REPO_ROOT = Path(__file__).resolve().parent.parent
SCAN_DIRS = [
    REPO_ROOT / "email-design-system" / "sections",
    REPO_ROOT / "email-design-system" / "components",
]
PLAYGROUND_DIR = REPO_ROOT / "email-design-system" / "playground"
COMPONENTS_DIR = REPO_ROOT / "email-design-system" / "components"
OUTPUT = REPO_ROOT / "developer-skills" / "figma_manifest.json"

FIGMA_SOURCE_BLOCK = re.compile(r"<!--\s*figma-source\b(.*?)-->", re.DOTALL)


def _field(block: str, name: str):
    m = re.search(rf"^\s*{re.escape(name)}:\s*(\S+)", block, re.MULTILINE)
    return m.group(1) if m else None


def _playground_for(path: Path):
    """section_hero_overlay.html -> playground/hero_overlay.html (if it exists)."""
    stem = re.sub(r"^(section|component)_", "", path.stem)
    pg = PLAYGROUND_DIR / f"{stem}.html"
    return str(pg.relative_to(REPO_ROOT)) if pg.is_file() else None


def _components_used(text: str):
    """Find class="component-<name>" usages -> components/component_<name>.html (if present)."""
    out = []
    for cls in sorted(set(re.findall(r'class="component-([a-z0-9-]+)"', text))):
        comp = COMPONENTS_DIR / f"component_{cls.replace('-', '_')}.html"
        if comp.is_file():
            rel = str(comp.relative_to(REPO_ROOT))
            if rel not in out:
                out.append(rel)
    return out


def build():
    targets = []
    for d in SCAN_DIRS:
        for path in sorted(d.glob("*.html")):
            text = path.read_text(encoding="utf-8")
            m = FIGMA_SOURCE_BLOCK.search(text)
            if not m:
                continue  # not downloaded from Figma; pipeline doesn't rebuild it
            block = m.group(1)
            rel = str(path.relative_to(REPO_ROOT))
            targets.append({
                "figma_file_key": _field(block, "file"),
                "figma_node_id": _field(block, "node"),
                "kind": _field(block, "kind"),
                "output_path": rel,
                "playground_path": _playground_for(path),
                "uses_components": _components_used(text),
                "figma_url": _field(block, "url"),
            })
    targets.sort(key=lambda t: (t["figma_file_key"] or "", t["figma_node_id"] or ""))
    return {
        "_generated_by": "developer-skills/generate_manifest.py",
        "_source_of_truth": "figma-source comments in email-design-system/{sections,components}/*.html",
        "_note": "Run after any Figma download/refresh to keep this in sync. Only Figma-sourced blocks appear here.",
        "targets": targets,
    }


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--check", action="store_true",
                    help="exit 1 if the on-disk manifest differs from a fresh build")
    args = ap.parse_args()

    fresh = json.dumps(build(), indent=2) + "\n"

    if args.check:
        current = OUTPUT.read_text(encoding="utf-8") if OUTPUT.is_file() else ""
        if current != fresh:
            print("figma_manifest.json is OUT OF DATE — run: python3 developer-skills/generate_manifest.py")
            return 1
        print("figma_manifest.json is up to date.")
        return 0

    OUTPUT.write_text(fresh, encoding="utf-8")
    n = fresh.count('"figma_node_id"')
    print(f"Wrote {OUTPUT.relative_to(REPO_ROOT)} — {n} Figma-sourced target(s).")
    return 0


if __name__ == "__main__":
    sys.exit(main())
