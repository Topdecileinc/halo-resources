#!/usr/bin/env python3
"""
figma-pipeline/poll.py

The polling trigger (Professional-plan-friendly; Figma only DELIVERS webhook events on
Org/Enterprise). Built to SCALE to hundreds of blocks, and it does TWO things per cycle:

  A. CHANGE DETECTION for blocks already tracked in figma_manifest.json — rebuild the ones
     whose Figma design changed.
  B. DISCOVERY — find brand-new top-level frames in the file that aren't tracked yet and
     onboard them (the builder creates the block + adds it to the manifest). "Each top-level
     frame, whole file" is the rule (configurable below).

Scale: per Figma FILE it does a cheap version check (GET /v1/files/:key?depth=1). If the
version is unchanged, the whole file is skipped — no per-node work. Only a file whose version
moved gets ONE full fetch, and both A and B run off that single response. So cost is
~O(files), not O(nodes).

Guardrails on discovery (B):
  - skips frames that are hidden or whose name starts with "_" or "." (park WIP there)
  - onboards at most ONBOARD_CAP (default 10) new frames per cycle, so a big file doesn't
    fire dozens of Claude builds at once; the rest are picked up on later cycles
  - skips a frame whose derived filename already belongs to another block (no clobbering)

State (poll_state.json, committed by the workflow):
  { "file_versions": {<file_key>: <version>},
    "node_hashes":   {"<file_key>:<node_id>": <sha256 of the node's design subtree>} }
A node's first sighting is a silent baseline (already built / just onboarded -> in sync).

Env (CI secrets): FIGMA_CLIENT_ID/SECRET/REFRESH_TOKEN.  ONBOARD_CAP optional.
Output: writes `changed` (JSON array of {file_key,node_id,output_path}) + `any` to $GITHUB_OUTPUT.
"""

import hashlib
import json
import os
import re
import sys
import urllib.error
import urllib.request

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from figma_fetch import refresh_access_token  # noqa: E402

API = "https://api.figma.com"
MANIFEST = "figma-pipeline/figma_manifest.json"
STATE = "figma-pipeline/poll_state.json"
SECTIONS_DIR = "email-design-system/sections"
ONBOARD_CAP = int(os.environ.get("ONBOARD_CAP", "10"))


def _get(url, token, timeout=120):
    req = urllib.request.Request(url, headers={"Authorization": f"Bearer {token}"})
    try:
        with urllib.request.urlopen(req, timeout=timeout) as r:
            return json.loads(r.read())
    except urllib.error.HTTPError as e:
        body = e.read().decode("utf-8", "replace")
        raise SystemExit(f"HTTP {e.code} from {url}\n  {body}") from None


def file_version(file_key, token):
    return _get(f"{API}/v1/files/{file_key}?depth=1", token, timeout=30).get("version")


def index_by_id(node, out):
    nid = node.get("id")
    if nid is not None:
        out[nid] = node
    for child in (node.get("children") or []):
        index_by_id(child, out)
    return out


def design_hash(node):
    canon = json.dumps(node, sort_keys=True, separators=(",", ":"))
    return hashlib.sha256(canon.encode("utf-8")).hexdigest()


def top_level_frames(document):
    """The discovery rule: every FRAME directly on a page, or one level inside a page Section.
    Hidden frames and names starting with '_' or '.' are skipped (park WIP there)."""
    found = []
    for page in (document.get("children") or []):
        if page.get("type") != "CANVAS":
            continue
        for child in (page.get("children") or []):
            t = child.get("type")
            if t == "FRAME":
                found.append(child)
            elif t == "SECTION":
                found += [g for g in (child.get("children") or []) if g.get("type") == "FRAME"]
    out = []
    for f in found:
        if f.get("visible") is False:
            continue
        name = f.get("name", "")
        if name.startswith("_") or name.startswith("."):
            continue
        out.append(f)
    return out


def slugify(name):
    s = re.sub(r"[^a-z0-9]+", "_", (name or "").lower()).strip("_")
    return s or "block"


def output_path_for(name):
    return f"{SECTIONS_DIR}/section_{slugify(name)}.html"


def load_state():
    if not os.path.exists(STATE):
        return {}, {}
    with open(STATE, encoding="utf-8") as f:
        raw = json.load(f)
    if "node_hashes" in raw or "file_versions" in raw:
        return raw.get("file_versions", {}), raw.get("node_hashes", {})
    return {}, raw  # legacy flat format


def main():
    with open(MANIFEST, encoding="utf-8") as f:
        manifest = json.load(f)
    file_versions, node_hashes = load_state()

    try:
        cid = os.environ["FIGMA_CLIENT_ID"]
        secret = os.environ["FIGMA_CLIENT_SECRET"]
        refresh = os.environ["FIGMA_REFRESH_TOKEN"]
    except KeyError as e:
        raise SystemExit(f"Missing required env var: {e}")
    token = refresh_access_token(cid, secret, refresh)

    by_file = {}
    for t in manifest.get("targets", []):
        by_file.setdefault(t["figma_file_key"], []).append(t)
    # Optional: scan extra files that have no tracked blocks yet (bootstrap a fresh file).
    for fk in filter(None, os.environ.get("DISCOVERY_FILE_KEYS", "").split(",")):
        by_file.setdefault(fk.strip(), [])
    tracked_paths = {t["output_path"] for t in manifest.get("targets", [])}

    changed = []
    onboarded_total = 0
    for fk, items in by_file.items():
        ver = file_version(fk, token)
        if ver is not None and file_versions.get(fk) == ver:
            print(f"file {fk}: unchanged (version {ver}) — skipped {len(items)} block(s)")
            continue
        print(f"file {fk}: changed (version {ver})")
        document = _get(f"{API}/v1/files/{fk}", token).get("document", {})
        idx = index_by_id(document, {})

        # node ids we already know about in this file (manifest + anything baselined in state)
        known = {t["figma_node_id"] for t in items}
        known |= {k.split(":", 1)[1] for k in node_hashes if k.split(":", 1)[0] == fk}

        # --- A. change detection for tracked blocks ---
        for t in items:
            nid, out = t["figma_node_id"], t["output_path"]
            node = idx.get(nid)
            if node is None:
                print(f"  WARN: tracked node {nid} not found (deleted/renamed?)", file=sys.stderr)
                continue
            h = design_hash(node)
            key = f"{fk}:{nid}"
            prev = node_hashes.get(key)
            node_hashes[key] = h
            if prev is None:
                print(f"  baseline: {out}")
            elif prev != h:
                print(f"  CHANGED: {out}")
                changed.append({"file_key": fk, "node_id": nid, "output_path": out})

        # --- B. discovery: new top-level frames not tracked yet ---
        pending = 0          # new frames found in this file
        onboarded_here = 0   # of those, how many we onboarded this cycle
        for frame in top_level_frames(document):
            nid = frame.get("id")
            if nid in known:
                continue
            # Skip if this frame already CONTAINS a tracked node — it's represented already
            # (e.g. a section/frame wrapping an existing component). Prevents duplicates.
            if set(index_by_id(frame, {}).keys()) & known:
                continue
            out = output_path_for(frame.get("name", ""))
            if out in tracked_paths:
                print(f"  SKIP new {nid}: name collides with existing {out}", file=sys.stderr)
                continue
            pending += 1
            over = onboarded_total >= ONBOARD_CAP
            print(f"  NEW{' (queued for a later cycle)' if over else ''}: "
                  f"{frame.get('name')!r} -> {out}  (node {nid})")
            if over:
                continue  # over the per-cycle cap (or a DRY-RUN with cap 0)
            changed.append({"file_key": fk, "node_id": nid, "output_path": out})
            node_hashes[f"{fk}:{nid}"] = design_hash(frame)  # baseline so we don't re-onboard
            tracked_paths.add(out)
            known.add(nid)
            onboarded_total += 1
            onboarded_here += 1

        leftover = pending - onboarded_here
        if leftover > 0:
            # Capped with frames still to onboard — leave the version stale so we re-scan
            # next cycle and drain the rest.
            print(f"  {leftover} more new frame(s) pending (cap {ONBOARD_CAP}); next cycle continues.")
        else:
            file_versions[fk] = ver

    with open(STATE, "w", encoding="utf-8") as f:
        json.dump({"file_versions": file_versions, "node_hashes": node_hashes},
                  f, indent=2, sort_keys=True)
        f.write("\n")

    print(f"\n{len(changed)} block(s) to build ({onboarded_total} new, "
          f"{len(changed) - onboarded_total} changed).")
    gho = os.environ.get("GITHUB_OUTPUT")
    if gho:
        with open(gho, "a", encoding="utf-8") as f:
            f.write(f"changed={json.dumps(changed)}\n")
            f.write(f"any={'true' if changed else 'false'}\n")
    return 0


if __name__ == "__main__":
    sys.exit(main())
