#!/usr/bin/env python3
"""
figma-pipeline/poll.py

The polling trigger (Professional plan — Figma won't DELIVER webhook events, but the REST API
DOES expose dev status, so we read it on a schedule).

THE RULE: the repo mirrors the **Ready for dev** set of the Figma file. devStatus drives it all:
  - a Ready-for-dev node NOT yet tracked    -> ONBOARD it (the builder creates the block)
  - a Ready-for-dev node already tracked    -> rebuild it IF its design changed
  - a tracked node NO LONGER Ready for dev  -> DELETE its block + playground (status cleared, or
                                               the frame removed from Figma)
  - a COMPLETED node                        -> PROTECTED: kept as-is, never rebuilt, never deleted
Deletion is guarded: it refuses to remove more than DELETE_CAP blocks, or to empty the whole
library, in one cycle (a bad/partial Figma read shouldn't wipe everything).

WHICH FIGMA FILE: the single tracked file is the **FIGMA_FILE_KEY** repo variable — NOT hardcoded
and NOT read from the blocks. Point the pipeline at a different file by changing that one variable
in GitHub (Settings -> Secrets and variables -> Actions -> Variables); no file edits, no rebuild.
The manifest only supplies the node -> output-file mapping for already-tracked blocks.

Scale: one full-file fetch per cycle (NOT one call per node), then everything is read locally — so
3 blocks or 300, it's one API call. (We always fetch, rather than skip on the file `version`, so a
freshly-set dev status is always noticed.)

State (poll_state.json, committed by the workflow):
  { "node_hashes": {"<file_key>:<node_id>": <sha256 of the node's design subtree>} }
A Ready-for-dev node's first sighting is a silent baseline if it's already tracked (in sync); if
it's new, first sighting onboards it.

Guardrails on onboarding new blocks:
  - at most ONBOARD_CAP (default 10) per cycle, so a big batch doesn't fire dozens of builds
  - skip a new block whose derived filename collides with an existing one (no clobbering)

Env: FIGMA_FILE_KEY (repo variable), FIGMA_CLIENT_ID/SECRET/REFRESH_TOKEN (secrets), ONBOARD_CAP
(optional). Output: writes `changed` (JSON array of {file_key,node_id,output_path}) + `any` to
$GITHUB_OUTPUT.
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
DELETE_CAP = int(os.environ.get("DELETE_CAP", "10"))


def _get(url, token, timeout=120):
    req = urllib.request.Request(url, headers={"Authorization": f"Bearer {token}"})
    try:
        with urllib.request.urlopen(req, timeout=timeout) as r:
            return json.loads(r.read())
    except urllib.error.HTTPError as e:
        body = e.read().decode("utf-8", "replace")
        raise SystemExit(f"HTTP {e.code} from {url}\n  {body}") from None


def collect_statuses(node, ready, completed_ids):
    """Walk the tree once: READY_FOR_DEV nodes -> `ready` list; COMPLETED node ids ->
    `completed_ids` (those are protected from deletion)."""
    if isinstance(node, dict):
        ds = node.get("devStatus")
        if isinstance(ds, dict):
            t = ds.get("type")
            if t == "READY_FOR_DEV":
                ready.append(node)
            elif t == "COMPLETED":
                completed_ids.add(node.get("id"))
        for c in (node.get("children") or []):
            collect_statuses(c, ready, completed_ids)
    return ready, completed_ids


def playground_for(output_path):
    """email-design-system/sections/section_foo.html -> .../playground/foo.html"""
    name = re.sub(r"^(section|component)_", "", os.path.basename(output_path)[:-5])
    return f"email-design-system/playground/{name}.html"


def design_hash(node):
    return hashlib.sha256(
        json.dumps(node, sort_keys=True, separators=(",", ":")).encode("utf-8")
    ).hexdigest()


def slugify(name):
    s = re.sub(r"[^a-z0-9]+", "_", (name or "").lower()).strip("_")
    return s or "block"


def output_path_for(name):
    return f"{SECTIONS_DIR}/section_{slugify(name)}.html"


def load_node_hashes():
    if not os.path.exists(STATE):
        return {}
    with open(STATE, encoding="utf-8") as f:
        raw = json.load(f)
    if isinstance(raw, dict) and "node_hashes" in raw:
        return raw["node_hashes"]
    return raw if isinstance(raw, dict) else {}  # tolerate older/flat formats


def main():
    with open(MANIFEST, encoding="utf-8") as f:
        manifest = json.load(f)
    node_hashes = load_node_hashes()

    try:
        file_key = os.environ["FIGMA_FILE_KEY"]
        cid = os.environ["FIGMA_CLIENT_ID"]
        secret = os.environ["FIGMA_CLIENT_SECRET"]
        refresh = os.environ["FIGMA_REFRESH_TOKEN"]
    except KeyError as e:
        raise SystemExit(f"Missing required env var: {e} "
                         "(FIGMA_FILE_KEY is a repo variable; the rest are secrets).")
    token = refresh_access_token(cid, secret, refresh)

    # node -> output path for blocks we already track (the file key now comes from the variable,
    # so a stored manifest file key is ignored — change the variable to repoint everything).
    node_to_path = {t["figma_node_id"]: t["output_path"] for t in manifest.get("targets", [])}
    tracked_paths = set(node_to_path.values())

    document = _get(f"{API}/v1/files/{file_key}", token).get("document", {})
    ready, completed_ids = collect_statuses(document, [], set())
    print(f"file {file_key}: {len(ready)} node(s) marked Ready for dev, "
          f"{len(completed_ids)} Completed (protected)")

    changed = []
    onboarded = 0
    for node in ready:
        nid = node.get("id")
        h = design_hash(node)
        key = f"{file_key}:{nid}"
        prev = node_hashes.get(key)
        if nid in node_to_path:                          # already a tracked block
            out = node_to_path[nid]
            node_hashes[key] = h
            if prev is None:
                print(f"  baseline: {out}")
            elif prev != h:
                print(f"  CHANGED: {out}")
                changed.append({"file_key": file_key, "node_id": nid, "output_path": out})
            else:
                print(f"  unchanged: {out}")
        else:                                            # Ready-for-dev but not tracked yet
            if prev is not None:
                continue                                 # already onboarded; build pending
            out = output_path_for(node.get("name", ""))
            if out in tracked_paths:
                print(f"  SKIP new {nid} ({node.get('name')!r}): name collides with {out}",
                      file=sys.stderr)
                continue
            over = onboarded >= ONBOARD_CAP
            print(f"  NEW{' (queued for a later cycle)' if over else ''}: "
                  f"{node.get('name')!r} -> {out}  (node {nid})")
            if over:
                continue
            changed.append({"file_key": file_key, "node_id": nid, "output_path": out})
            node_hashes[key] = h
            tracked_paths.add(out)
            onboarded += 1

    # --- DELETION: tracked blocks whose node is no longer Ready for dev (status cleared, or the
    # frame was removed from Figma). COMPLETED is protected. Two safety stops guard against a
    # bad/partial Figma read wiping the library.
    deleted = []
    keep = {n.get("id") for n in ready} | completed_ids
    to_delete = [nid for nid in node_to_path if nid not in keep]
    if to_delete and DELETE_CAP == 0:
        print("  -- deletion PREVIEW (DELETE_CAP=0: nothing will be deleted) --")
        for nid in to_delete:
            print(f"  WOULD DELETE: {node_to_path[nid]}  (node {nid})")
    elif to_delete and len(to_delete) > DELETE_CAP:
        print(f"::error::{len(to_delete)} block(s) would be deleted — over DELETE_CAP ({DELETE_CAP}). "
              f"Skipping ALL deletions this cycle (safety stop). Raise DELETE_CAP or investigate.",
              file=sys.stderr)
    elif to_delete and len(to_delete) == len(node_to_path) and len(node_to_path) > 1:
        print(f"::error::deletion would remove ALL {len(node_to_path)} tracked block(s) — refusing "
              f"(looks like an empty/bad Figma read). Investigate or delete manually.", file=sys.stderr)
    else:
        for nid in to_delete:
            out = node_to_path[nid]
            for path in (out, playground_for(out)):
                try:
                    os.remove(path)
                    print(f"  DELETED: {path}")
                except FileNotFoundError:
                    pass
            node_hashes.pop(f"{file_key}:{nid}", None)
            deleted.append(out)

    with open(STATE, "w", encoding="utf-8") as f:
        json.dump({"node_hashes": node_hashes}, f, indent=2, sort_keys=True)
        f.write("\n")

    print(f"\n{len(changed)} block(s) to build ({onboarded} new, "
          f"{len(changed) - onboarded} changed); {len(deleted)} deleted.")
    gho = os.environ.get("GITHUB_OUTPUT")
    if gho:
        with open(gho, "a", encoding="utf-8") as f:
            f.write(f"changed={json.dumps(changed)}\n")
            f.write(f"any={'true' if changed else 'false'}\n")
    return 0


if __name__ == "__main__":
    sys.exit(main())
