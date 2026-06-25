#!/usr/bin/env python3
"""
figma-pipeline/poll.py

Change detection for the polling trigger (Professional-plan-friendly; no webhooks).

Figma only DELIVERS webhook events on Org/Enterprise plans, so on Professional we poll.
This is built to SCALE to hundreds of tracked blocks: it does NOT fetch one node at a time.

Per poll, for each Figma FILE that has tracked blocks:
  1. cheap version check — GET /v1/files/:key?depth=1 returns the file `version`. If it equals
     the last-seen version, NOTHING in that file changed → skip every block in it (0 node work).
  2. only if the version moved: ONE full-file fetch, build an id→node index, and hash each
     tracked node's design subtree locally. Rebuild only the nodes whose hash changed.

So the cost is ~O(number of files), not O(number of nodes) — 3 blocks or 300, it's about one
API call per file per cycle. The full-file fetch (only when the file changed) is also what a
future auto-discovery step will use to find brand-new components.

State (figma_manifest-adjacent, committed by the workflow):
  poll_state.json = { "file_versions": {<file_key>: <version>},
                      "node_hashes":   {"<file_key>:<node_id>": <sha256>} }
First sighting of a node = silent baseline (it was just built; it's already in sync).

Credentials from the environment (CI secrets): FIGMA_CLIENT_ID/SECRET/REFRESH_TOKEN.
Output: writes `changed` (JSON array) and `any` (true/false) to $GITHUB_OUTPUT.
"""

import hashlib
import json
import os
import sys
import urllib.error
import urllib.request

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from figma_fetch import refresh_access_token  # noqa: E402

API = "https://api.figma.com"
MANIFEST = "figma-pipeline/figma_manifest.json"
STATE = "figma-pipeline/poll_state.json"


def _get(url, token, timeout=120):
    req = urllib.request.Request(url, headers={"Authorization": f"Bearer {token}"})
    try:
        with urllib.request.urlopen(req, timeout=timeout) as r:
            return json.loads(r.read())
    except urllib.error.HTTPError as e:
        body = e.read().decode("utf-8", "replace")
        raise SystemExit(f"HTTP {e.code} from {url}\n  {body}") from None


def file_version(file_key, token):
    """Cheap: the file's current version string (changes on any edit). depth=1 keeps it small."""
    return _get(f"{API}/v1/files/{file_key}?depth=1", token, timeout=30).get("version")


def index_by_id(node, out):
    """Walk a node tree, mapping every node id -> its subtree (one pass over the file)."""
    nid = node.get("id")
    if nid is not None:
        out[nid] = node
    for child in (node.get("children") or []):
        index_by_id(child, out)
    return out


def design_hash(node):
    """sha256 of a node's full design subtree (stable; the file's volatile metadata isn't in it)."""
    canon = json.dumps(node, sort_keys=True, separators=(",", ":"))
    return hashlib.sha256(canon.encode("utf-8")).hexdigest()


def load_state():
    if not os.path.exists(STATE):
        return {}, {}
    with open(STATE, encoding="utf-8") as f:
        raw = json.load(f)
    if "node_hashes" in raw or "file_versions" in raw:
        return raw.get("file_versions", {}), raw.get("node_hashes", {})
    return {}, raw  # legacy flat format = node hashes only


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

    changed = []
    for fk, items in by_file.items():
        ver = file_version(fk, token)
        if ver is not None and file_versions.get(fk) == ver:
            print(f"file {fk}: unchanged (version {ver}) — skipped {len(items)} block(s)")
            continue
        print(f"file {fk}: changed (version {ver}) — scanning {len(items)} block(s)")
        document = _get(f"{API}/v1/files/{fk}", token).get("document", {})
        idx = index_by_id(document, {})
        for t in items:
            nid, out = t["figma_node_id"], t["output_path"]
            node = idx.get(nid)
            if node is None:
                print(f"  WARN: node {nid} not found in {fk} (deleted/renamed?)", file=sys.stderr)
                continue
            h = design_hash(node)
            key = f"{fk}:{nid}"
            prev = node_hashes.get(key)
            node_hashes[key] = h
            if prev is None:
                print(f"  baseline: {out}  (recorded, not built)")
            elif prev != h:
                print(f"  CHANGED: {out}")
                changed.append({"file_key": fk, "node_id": nid, "output_path": out})
            else:
                print(f"  unchanged: {out}")
        file_versions[fk] = ver

    with open(STATE, "w", encoding="utf-8") as f:
        json.dump({"file_versions": file_versions, "node_hashes": node_hashes},
                  f, indent=2, sort_keys=True)
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
