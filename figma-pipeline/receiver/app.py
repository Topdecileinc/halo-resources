#!/usr/bin/env python3
"""
figma-pipeline/receiver/app.py

The always-on webhook receiver (Stage 1). Figma POSTs a publish event here; this validates
it, looks up which blocks are affected in figma_manifest.json, and triggers the GitHub
Actions builder (figma-build.yml) once per affected block via repository_dispatch.

This is a REFERENCE implementation (Flask). It's the only continuously-running piece; host it
on whatever you use (a small service, Cloud Run, a Lambda behind an HTTPS URL, etc.) and adapt
the framework as needed. It is UNTESTED here.

Environment (set as the host's secrets — never in the repo):
  FIGMA_WEBHOOK_PASSCODE   the passcode you set when registering the webhook
  GITHUB_TOKEN             a PAT / app token with `repo` (or fine-grained: contents + dispatch)
  GITHUB_REPO              "Topdecileinc/halo-resources"
  MANIFEST_URL             raw URL of figma_manifest.json, e.g.
                           https://raw.githubusercontent.com/Topdecileinc/halo-resources/main/figma-pipeline/figma_manifest.json

Run (dev): pip install flask ; FIGMA_WEBHOOK_PASSCODE=... GITHUB_TOKEN=... GITHUB_REPO=... \
           MANIFEST_URL=... python3 app.py
"""

import json
import os
import urllib.request
from collections import deque

from flask import Flask, request  # pip install flask

app = Flask(__name__)

# Naive in-memory dedupe. Webhooks can fire duplicates; in production use a durable store
# (Redis/db) keyed on a stable event id so restarts don't reprocess.
_seen = deque(maxlen=512)
_seen_set = set()

# Content events worth rebuilding on. LIBRARY_PUBLISH is the intended trigger (intentional
# publish); the others are here in case the source is a plain design file.
CONTENT_EVENTS = {"LIBRARY_PUBLISH", "FILE_UPDATE", "FILE_VERSION_UPDATE"}


def _load_manifest():
    with urllib.request.urlopen(os.environ["MANIFEST_URL"], timeout=15) as r:
        return json.loads(r.read())


def _dispatch(file_key, node_id, output_path):
    """Trigger the figma-build workflow for one block via repository_dispatch."""
    repo = os.environ["GITHUB_REPO"]
    body = json.dumps({
        "event_type": "figma-publish",
        "client_payload": {"file_key": file_key, "node_id": node_id, "output_path": output_path},
    }).encode()
    req = urllib.request.Request(
        f"https://api.github.com/repos/{repo}/dispatches",
        data=body, method="POST",
        headers={
            "Authorization": f"Bearer {os.environ['GITHUB_TOKEN']}",
            "Accept": "application/vnd.github+json",
            "X-GitHub-Api-Version": "2022-11-28",
            "Content-Type": "application/json",
        },
    )
    urllib.request.urlopen(req, timeout=15)  # 204 on success


@app.post("/figma-webhook")
def figma_webhook():
    payload = request.get_json(silent=True) or {}

    # 1. validate the passcode Figma echoes back
    if payload.get("passcode") != os.environ.get("FIGMA_WEBHOOK_PASSCODE"):
        return ("invalid passcode", 403)

    event = payload.get("event_type")

    # 2. Figma sends a PING when the webhook is created — ack it
    if event == "PING":
        return ("", 200)

    # 3. dedupe (best-effort)
    eid = f"{event}:{payload.get('file_key')}:{payload.get('timestamp')}"
    if eid in _seen_set:
        return ("", 200)
    _seen_set.add(eid)
    _seen.append(eid)
    if len(_seen_set) > _seen.maxlen:
        _seen_set.intersection_update(_seen)

    # 4. on a content event for a file we build from, rebuild its targets
    if event in CONTENT_EVENTS:
        file_key = payload.get("file_key")
        manifest = _load_manifest()
        targets = [t for t in manifest.get("targets", []) if t.get("figma_file_key") == file_key]
        # NOTE: LIBRARY_PUBLISH reports *which components* changed (by component key), but the
        # manifest currently keys on node id, so we can't yet filter to just the changed ones —
        # this rebuilds every target in that file (correct, just coarse). To make it precise,
        # record the published component key in the figma-source comment / manifest, then match
        # payload.get("created_components"/"modified_components") here. (Follow-up.)
        for t in targets:
            _dispatch(file_key, t["figma_node_id"], t["output_path"])

    # always 200 quickly so Figma doesn't retry/disable the webhook
    return ("", 200)


@app.get("/healthz")
def healthz():
    return ("ok", 200)


if __name__ == "__main__":
    app.run(host="0.0.0.0", port=int(os.environ.get("PORT", "8080")))
