#!/usr/bin/env python3
"""One-off probe: does the Figma REST API expose dev status ('Ready for dev') on nodes?
Fetches the file and prints every node that carries a devStatus. Throwaway — delete after."""
import json
import os
import sys
import urllib.request

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from figma_fetch import refresh_access_token

API = "https://api.figma.com"
FILE = os.environ.get("PROBE_FILE", "cXkVxm0fI9G49nZtKPbYY7")


def get(url, token):
    req = urllib.request.Request(url, headers={"Authorization": f"Bearer {token}"})
    with urllib.request.urlopen(req, timeout=120) as r:
        return json.loads(r.read())


def walk(node, out):
    if isinstance(node, dict):
        if "devStatus" in node:
            out.append((node.get("type"), node.get("id"), node.get("name"), node.get("devStatus")))
        for c in (node.get("children") or []):
            walk(c, out)
    return out


token = refresh_access_token(os.environ["FIGMA_CLIENT_ID"],
                             os.environ["FIGMA_CLIENT_SECRET"],
                             os.environ["FIGMA_REFRESH_TOKEN"])
doc = get(f"{API}/v1/files/{FILE}", token).get("document", {})
hits = walk(doc, [])
print(f"NODES WITH devStatus: {len(hits)}")
for t, i, name, ds in hits[:80]:
    print(f"  {t} {i} {name!r} -> {ds}")
if not hits:
    print("=> REST API does NOT expose devStatus for this file. The 'Ready for dev' "
          "filter is not possible via polling.")
