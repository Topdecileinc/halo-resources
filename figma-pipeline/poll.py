#!/usr/bin/env python3
"""
figma-pipeline/poll.py

Change detection for the polling trigger (Professional-plan-friendly; no webhooks).

Figma webhooks only DELIVER events on Organization/Enterprise plans, so instead of
waiting to be told a design changed, we ask Figma on a schedule. For each tracked block
in figma_manifest.json this:
  1. fetches the node's current design from the Figma REST API,
  2. hashes the *design subtree* (nodes[id].document — the stable part; the file-level
     thumbnailUrl/lastModified are volatile and are deliberately excluded),
  3. compares it to the last-seen hash in poll_state.json.
A block whose hash changed is emitted as "changed" and its new hash is recorded, so the
next poll won't re-trigger it. Unchanged blocks are skipped — no rebuild, no Claude run,
no drift churn.

Output: writes `changed` (JSON array of {file_key,node_id,output_path}) and `any`
(true/false) to $GITHUB_OUTPUT so the workflow can fan out a build per changed block.

Credentials come from the environment (CI secrets), same as figma_fetch.py:
  FIGMA_CLIENT_ID, FIGMA_CLIENT_SECRET, FIGMA_REFRESH_TOKEN

Note: state is advanced as soon as a change is detected (before the build runs). If a
build fails, re-run it via figma-build's manual trigger — the next poll won't catch it
again until the design changes once more.
"""

import hashlib
import json
import os
import sys

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from figma_fetch import refresh_access_token, fetch_node_json  # noqa: E402

MANIFEST = "figma-pipeline/figma_manifest.json"
STATE = "figma-pipeline/poll_state.json"


def design_hash(node_json, node_id):
    """sha256 of the node's design subtree (stable; excludes volatile file metadata)."""
    doc = (node_json.get("nodes", {}).get(node_id, {}) or {}).get("document")
    if doc is None:
        return None
    canon = json.dumps(doc, sort_keys=True, separators=(",", ":"))
    return hashlib.sha256(canon.encode("utf-8")).hexdigest()


def main():
    with open(MANIFEST, encoding="utf-8") as f:
        manifest = json.load(f)
    state = {}
    if os.path.exists(STATE):
        with open(STATE, encoding="utf-8") as f:
            state = json.load(f)

    try:
        cid = os.environ["FIGMA_CLIENT_ID"]
        secret = os.environ["FIGMA_CLIENT_SECRET"]
        refresh = os.environ["FIGMA_REFRESH_TOKEN"]
    except KeyError as e:
        raise SystemExit(f"Missing required env var: {e}")

    token = refresh_access_token(cid, secret, refresh)

    changed = []
    for t in manifest.get("targets", []):
        fk, nid, out = t["figma_file_key"], t["figma_node_id"], t["output_path"]
        key = f"{fk}:{nid}"
        try:
            node_json = fetch_node_json(fk, nid, token)
        except SystemExit as e:
            print(f"WARN: fetch failed for {key}: {e}", file=sys.stderr)
            continue
        h = design_hash(node_json, nid)
        if h is None:
            print(f"WARN: node {nid} not found in file {fk} (deleted/renamed?)", file=sys.stderr)
            continue
        prev = state.get(key)
        state[key] = h
        if prev is None:
            # First time we've seen this block: it was just built, so it's already in sync.
            # Record the baseline and DON'T rebuild — only future changes should trigger.
            print(f"baseline: {out}  (recorded, not built)")
        elif prev != h:
            print(f"CHANGED: {out}  ({key})")
            changed.append({"file_key": fk, "node_id": nid, "output_path": out})
        else:
            print(f"unchanged: {out}")

    with open(STATE, "w", encoding="utf-8") as f:
        json.dump(state, f, indent=2, sort_keys=True)
        f.write("\n")

    print(f"\n{len(changed)} changed block(s).")
    gho = os.environ.get("GITHUB_OUTPUT")
    if gho:
        with open(gho, "a", encoding="utf-8") as f:
            f.write(f"changed={json.dumps(changed)}\n")
            f.write(f"any={'true' if changed else 'false'}\n")
    return 0


if __name__ == "__main__":
    sys.exit(main())
