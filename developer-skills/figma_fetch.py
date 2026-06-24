#!/usr/bin/env python3
"""
developer-skills/figma_fetch.py

Headless Figma read for the CI builder. The remote Figma MCP server is OAuth-only and
restricted to whitelisted "catalog" clients with an *interactive* OAuth flow, so it can't
run in an unattended container (see rules_figma_download.md -> "Interactive vs headless").
This pulls the same design *spec* via the Figma REST API using the OAuth app's refresh
token — fully unattended.

Given a file key + node id, it:
  1. refreshes the OAuth access token (refresh token never expires; access token ~90d),
  2. fetches the node's structure JSON  (GET /v1/files/:key/nodes),
  3. fetches a rendered PNG of the node  (GET /v1/images/:key),
and writes both to an output dir for the builder (claude -p) to translate per
rules_figma_download.md.

Credentials come from the environment (set these as CI secrets — never in the repo):
  FIGMA_CLIENT_ID, FIGMA_CLIENT_SECRET, FIGMA_REFRESH_TOKEN

Usage:
  python3 developer-skills/figma_fetch.py --file <FILE_KEY> --node 4618:108 --out ./_figma_in

Zero dependencies (stdlib only), to match test/ and generate_manifest.py.
NOTE: not runnable without live credentials, so it has not been executed here — your dev
should smoke-test it once with the real token before wiring it into the workflow.
"""

import argparse
import base64
import json
import os
import sys
import urllib.parse
import urllib.request

API = "https://api.figma.com"


def _req(url, *, headers=None, data=None, method=None):
    r = urllib.request.Request(url, data=data, headers=headers or {}, method=method)
    with urllib.request.urlopen(r, timeout=30) as resp:
        return resp.read()


def refresh_access_token(client_id, client_secret, refresh_token):
    """POST /v1/oauth/refresh with Basic auth -> a fresh bearer access token."""
    basic = base64.b64encode(f"{client_id}:{client_secret}".encode()).decode()
    body = urllib.parse.urlencode({"refresh_token": refresh_token}).encode()
    raw = _req(
        f"{API}/v1/oauth/refresh",
        headers={"Authorization": f"Basic {basic}",
                 "Content-Type": "application/x-www-form-urlencoded"},
        data=body,
        method="POST",
    )
    return json.loads(raw)["access_token"]


def fetch_node_json(file_key, node_id, access_token):
    """GET /v1/files/:key/nodes?ids=:node -> the node's document structure."""
    q = urllib.parse.urlencode({"ids": node_id})
    raw = _req(f"{API}/v1/files/{file_key}/nodes?{q}",
               headers={"Authorization": f"Bearer {access_token}"})
    return json.loads(raw)


def fetch_node_png(file_key, node_id, access_token, scale=2):
    """GET /v1/images/:key -> a temporary URL to a rendered PNG, then download it."""
    q = urllib.parse.urlencode({"ids": node_id, "format": "png", "scale": scale})
    meta = json.loads(_req(f"{API}/v1/images/{file_key}?{q}",
                           headers={"Authorization": f"Bearer {access_token}"}))
    img_url = (meta.get("images") or {}).get(node_id)
    if not img_url:
        raise SystemExit(f"No rendered image returned for node {node_id}: {meta}")
    return _req(img_url)  # the URL is pre-signed; no auth header needed


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--file", required=True, help="Figma file key")
    ap.add_argument("--node", required=True, help="node id, e.g. 4618:108")
    ap.add_argument("--out", default="./_figma_in", help="output directory")
    args = ap.parse_args()

    try:
        cid = os.environ["FIGMA_CLIENT_ID"]
        secret = os.environ["FIGMA_CLIENT_SECRET"]
        refresh = os.environ["FIGMA_REFRESH_TOKEN"]
    except KeyError as e:
        raise SystemExit(f"Missing required env var: {e}. Set FIGMA_CLIENT_ID, "
                         f"FIGMA_CLIENT_SECRET, FIGMA_REFRESH_TOKEN as CI secrets.")

    os.makedirs(args.out, exist_ok=True)
    token = refresh_access_token(cid, secret, refresh)

    node_json = fetch_node_json(args.file, args.node, token)
    json_path = os.path.join(args.out, "node.json")
    with open(json_path, "w", encoding="utf-8") as f:
        json.dump(node_json, f, indent=2)

    png = fetch_node_png(args.file, args.node, token)
    png_path = os.path.join(args.out, "node.png")
    with open(png_path, "wb") as f:
        f.write(png)

    print(f"Wrote {json_path} and {png_path} for node {args.node} (file {args.file}).")
    return 0


if __name__ == "__main__":
    sys.exit(main())
