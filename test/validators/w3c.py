"""W3C HTML validation.

Three ways to validate, tried in order based on config:
  1. W3C's web service at validator.w3.org/nu (default; no install needed,
     uses stdlib urllib, requires internet).
  2. `vnu.jar` if configured or found locally (requires Java).
  3. `html5validator` on PATH (requires Java + pip install).

If [w3c] enabled = false in config, none of this runs.
If enabled but every path fails (no internet, no tool), warn and move on.

Output is post-filtered to drop expected email-HTML violations — VML, MSO
conditionals, deprecated table attributes — that email clients legitimately
require even though HTML5 strict mode doesn't.
"""

import json as _json
import shutil
import socket
import subprocess
import tempfile
import urllib.error
import urllib.request
from pathlib import Path


# Patterns we suppress because email HTML legitimately uses them.
EXPECTED_PATTERNS = [
    "v:roundrect", "v:textbox", "v:stroke", "v:fill", "vml",
    "mso-", "msoffice", "cellpadding", "cellspacing", "valign",
    "align attribute", "border attribute", "bgcolor",
    "Element \u201cstyle\u201d not allowed",
    "Stray start tag \u201cstyle\u201d",
    "xmlns:v", "xmlns:o", "xmlns:w",
]

W3C_NU_URL = "https://validator.w3.org/nu/"
TIMEOUT_SECS = 30


def _filter_messages(messages):
    """Drop email-expected violations from a list of message strings."""
    keep = []
    for msg in messages:
        low = msg.lower()
        if any(p.lower() in low for p in EXPECTED_PATTERNS):
            continue
        if msg.strip():
            keep.append(msg)
    return keep


# ---------- Path 1: W3C web service ----------
def _validate_via_web_service(ctx, html):
    """POST HTML to validator.w3.org/nu and return (success, messages, tool_name)."""
    try:
        req = urllib.request.Request(
            W3C_NU_URL + "?out=json",
            data=html.encode("utf-8"),
            method="POST",
            headers={
                "Content-Type": "text/html; charset=utf-8",
                "User-Agent": "halo-email-validator/1.0",
            },
        )
        with urllib.request.urlopen(req, timeout=TIMEOUT_SECS) as resp:
            payload = _json.loads(resp.read().decode("utf-8"))
    except (urllib.error.URLError, socket.timeout) as e:
        return (False, [f"network error reaching W3C: {e}"], "W3C web service")
    except Exception as e:
        return (False, [f"W3C web service error: {e!r}"], "W3C web service")

    # Per W3C's API, each entry has type (info/warning/error) and message.
    issues = payload.get("messages", [])
    formatted = []
    for item in issues:
        # Skip pure 'info' entries — they're not violations.
        if item.get("type") == "info":
            continue
        kind = item.get("type", "issue")
        line = item.get("lastLine", item.get("firstLine", "?"))
        msg = item.get("message", "")
        formatted.append(f"[{kind}] line {line}: {msg}")

    return (True, formatted, "W3C web service (validator.w3.org/nu)")


# ---------- Path 2 & 3: local tools ----------
def _resolve_vnu_jar(configured_path):
    if configured_path:
        p = Path(configured_path).expanduser()
        if p.exists():
            return p
    for candidate in (
        Path.cwd() / "vnu.jar",
        Path.home() / "vnu.jar",
        Path("/usr/local/share/vnu/vnu.jar"),
        Path("/opt/vnu/vnu.jar"),
    ):
        if candidate.exists():
            return candidate
    return None


def _validate_via_local_tool(ctx, html, vnu_jar_config):
    """Try vnu.jar then html5validator. Return (success, messages, tool_name)."""
    with tempfile.NamedTemporaryFile(
        suffix=".html", delete=False, mode="w", encoding="utf-8"
    ) as tmp:
        tmp.write(html)
        tmp_path = Path(tmp.name)

    try:
        vnu = _resolve_vnu_jar(vnu_jar_config)
        if vnu and shutil.which("java"):
            try:
                r = subprocess.run(
                    ["java", "-jar", str(vnu), str(tmp_path)],
                    capture_output=True, text=True, timeout=TIMEOUT_SECS,
                )
                output = r.stdout + r.stderr
                # vnu.jar emits one issue per line
                lines = [ln for ln in output.splitlines() if ln.strip()]
                return (True, lines, f"vnu.jar ({vnu})")
            except subprocess.TimeoutExpired:
                return (False, ["vnu.jar timed out"], f"vnu.jar ({vnu})")
            except Exception as e:
                return (False, [f"vnu.jar error: {e!r}"], f"vnu.jar ({vnu})")

        if shutil.which("html5validator"):
            try:
                r = subprocess.run(
                    ["html5validator", "--root", str(tmp_path.parent),
                     "--match", tmp_path.name],
                    capture_output=True, text=True, timeout=TIMEOUT_SECS,
                )
                output = r.stdout + r.stderr
                lines = [ln for ln in output.splitlines() if ln.strip()]
                return (True, lines, "html5validator")
            except subprocess.TimeoutExpired:
                return (False, ["html5validator timed out"], "html5validator")
            except Exception as e:
                return (False, [f"html5validator error: {e!r}"], "html5validator")

        return (False, [], None)  # no local tool found
    finally:
        try:
            tmp_path.unlink()
        except Exception:
            pass


# ---------- main check ----------
def check(ctx, vnu_jar_config="", use_web_service=True):
    """Run W3C validation. Called by validate.py when [w3c] enabled = true.

    If use_web_service is True (default), try the W3C web service first.
    Falls back to local tools if the web service fails or is disabled.
    """
    if not ctx.html:
        return

    success = False
    messages = []
    tool_used = None

    if use_web_service:
        success, messages, tool_used = _validate_via_web_service(ctx, ctx.html)
        if not success and "network error" in (messages[0] if messages else ""):
            # Network failure — try local fallback before giving up.
            ok, local_msgs, local_tool = _validate_via_local_tool(ctx, ctx.html, vnu_jar_config)
            if ok:
                success, messages, tool_used = ok, local_msgs, local_tool
            else:
                ctx.warn(
                    "w3c.unavailable",
                    f"W3C web service unreachable and no local tool available; W3C check skipped. "
                    f"Set [w3c] use_web_service = false and configure vnu_jar_path if working offline.",
                )
                return
    else:
        success, messages, tool_used = _validate_via_local_tool(ctx, ctx.html, vnu_jar_config)
        if not success and tool_used is None:
            ctx.warn(
                "w3c.tool_present",
                "no local W3C validator found — install html5validator OR set [w3c] vnu_jar_path "
                "OR set use_web_service = true in config.ini",
            )
            return

    if not success:
        ctx.warn("w3c.validate", f"{tool_used} failed: {messages[0] if messages else 'unknown'}")
        return

    filtered = _filter_messages(messages)
    if not filtered:
        ctx.ok(f"w3c.validate (via {tool_used}, email-relaxed)")
    else:
        shown = filtered[:10]
        extra = len(filtered) - len(shown)
        msg = f"{tool_used} reported {len(filtered)} non-email issues:\n" + "\n".join(
            f"      {line}" for line in shown
        )
        if extra > 0:
            msg += f"\n      ...and {extra} more"
        ctx.warn("w3c.validate", msg)
