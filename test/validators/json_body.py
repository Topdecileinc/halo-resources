"""Validate send_test_body.json — schema, types, mask-leak detection."""

import json
import re

UUID_RE = re.compile(r"^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$", re.I)
EMAIL_RE = re.compile(r"^[^\s<>@]+@[^\s<>@]+\.[^\s<>@]+$")
FROM_RE = re.compile(r"^.+<([^<>]+@[^<>]+\.[^<>]+)>$")


def check(ctx):
    if not ctx.json_text:
        return

    try:
        body = json.loads(ctx.json_text)
    except json.JSONDecodeError as e:
        ctx.err("json.parse", f"invalid JSON: {e}")
        return
    ctx.ok("json.parse")

    # Top-level required
    for f in ("broadcast", "segment_id", "messages"):
        if f not in body:
            ctx.err("json.required", f"missing top-level field: {f}")
        else:
            ctx.ok(f"json.required.{f}")

    # broadcast must be True (segment send)
    if body.get("broadcast") is True:
        ctx.ok("json.broadcast_true")
    else:
        ctx.err("json.broadcast_true",
                f"broadcast must be boolean true for segment sends; got {body.get('broadcast')!r}")

    # segment_id must be UUID
    sid = body.get("segment_id", "")
    if isinstance(sid, str) and UUID_RE.match(sid):
        ctx.ok("json.segment_id_uuid")
    else:
        ctx.err("json.segment_id_uuid", f"segment_id is not a valid UUID: {sid!r}")

    # Forbidden top-level fields
    for f in ("audience", "campaign_id", "external_user_ids"):
        if f in body:
            ctx.err("json.forbidden", f"forbidden field present: {f}")
        else:
            ctx.ok(f"json.forbidden.absent.{f}")

    # messages.email
    email = body.get("messages", {}).get("email") or {}
    if not email:
        ctx.err("json.email_object", "messages.email is missing or empty")
        return

    # app_id is UUID
    app_id = email.get("app_id", "")
    if isinstance(app_id, str) and UUID_RE.match(app_id):
        ctx.ok("json.app_id_uuid")
    else:
        ctx.err("json.app_id_uuid", f"app_id is not a valid UUID: {app_id!r}")

    # from format + mask leak
    frm = email.get("from", "")
    fm = FROM_RE.match(frm) if isinstance(frm, str) else None
    if fm and EMAIL_RE.match(fm.group(1)):
        if "[email" in frm.lower() or "protected]" in frm.lower():
            ctx.err("json.from_no_mask",
                    f"from contains a masked placeholder (chat-rendered '[email protected]'): {frm!r}")
        else:
            ctx.ok("json.from_format")
    else:
        ctx.err("json.from_format",
                f"from is not 'Display Name <[email protected]>': {frm!r}")

    # subject + preheader
    subj = email.get("subject", "")
    if isinstance(subj, str) and subj.strip():
        if len(subj) > 100:
            ctx.warn("json.subject_length",
                     f"subject is {len(subj)} chars; consider keeping it under ~50")
        ctx.ok("json.subject_present")
    else:
        ctx.err("json.subject_present", "subject is missing or empty")

    preh = email.get("preheader", "")
    if isinstance(preh, str) and preh.strip():
        ctx.ok("json.preheader_present")
    else:
        ctx.warn("json.preheader_present", "preheader missing — recommended for inbox preview")

    # body present and looks like HTML
    bdy = email.get("body", "")
    if isinstance(bdy, str) and bdy.strip():
        stripped = bdy.lstrip().lower()
        if stripped.startswith(("<!doctype", "<html")):
            ctx.ok("json.body_is_html")
        else:
            ctx.warn("json.body_is_html", "body does not start with <!DOCTYPE or <html>")
        if len(bdy) < 500:
            ctx.warn("json.body_length", f"body is only {len(bdy)} chars — suspiciously short")
        ctx.ok("json.body_present")
    else:
        ctx.err("json.body_present", "body is missing or empty")
