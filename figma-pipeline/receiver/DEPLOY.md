# Deploying the receiver (PHP, on your Apache + subdomain)

This is the always-on piece that makes the pipeline automatic: Figma publish → this
receiver → it triggers the GitHub builder workflow. Do it once.

Because the receiver is plain PHP (`figma-webhook.php`), it runs under the Apache + PHP
you already have — **no Python, venv, gunicorn, or systemd service.** You drop one file
in a webroot and keep the secrets in a config file outside it (same idea as your
`brief/config.php`).

Assumes a Debian/Ubuntu VM already serving PHP over Apache, and that you can add a DNS
subdomain (e.g. `hooks.example.com`). Replace these placeholders as you go:

| Placeholder | What it is |
|---|---|
| `hooks.example.com` | the subdomain you'll point at this VM |
| `<VM_PUBLIC_IP>` | the VM's public IP |
| `<A_LONG_RANDOM_PASSCODE>` | a secret you invent now — run `openssl rand -hex 24` |
| `<GITHUB_PAT>` | a GitHub token created in step 4 |

---

## 1. DNS — point the subdomain at the VM

Add an **A record**: `hooks.example.com` → `<VM_PUBLIC_IP>`.
Confirm: `dig +short hooks.example.com` prints the IP.

## 2. Put the receiver file in a webroot

```bash
sudo mkdir -p /var/www/hooks
# copy figma-webhook.php here (scp, or git clone of this repo and copy the one file)
sudo cp figma-webhook.php /var/www/hooks/figma-webhook.php
sudo chown -R www-data:www-data /var/www/hooks
```

## 3. Create the secrets config (OUTSIDE the webroot, never in git)

```bash
sudo tee /etc/figma-receiver-config.php >/dev/null <<'EOF'
<?php
return [
    'passcode'     => '<A_LONG_RANDOM_PASSCODE>',
    'github_token' => '<GITHUB_PAT>',
    'github_repo'  => 'Topdecileinc/halo-resources',
    'manifest_url' => 'https://raw.githubusercontent.com/Topdecileinc/halo-resources/main/figma-pipeline/figma_manifest.json',
];
EOF
sudo chown root:www-data /etc/figma-receiver-config.php
sudo chmod 640 /etc/figma-receiver-config.php   # Apache can read it; the world can't
```

The receiver looks here by default. (To use a different path, set `SetEnv
FIGMA_RECEIVER_CONFIG /your/path` in the vhost below.)

## 4. Create the GitHub token (least privilege)

GitHub → Settings → Developer settings → **Fine-grained tokens** → Generate:
- **Resource owner: `Topdecileinc`** (the org — not your personal account, or the repo won't
  show up). The org must allow fine-grained tokens; as an org owner, enable it under
  Org → Settings → Personal access tokens.
- Repository access: **Only select repositories** → `halo-resources`
- **Repository permissions → Contents: Read and write** — that's the only one. Each permission
  has its own dropdown (defaults to "No access"); set Contents and leave the rest. **Metadata:
  Read-only** is added automatically. (The `repository_dispatch` call this token makes lives
  under the Contents permission.)
- Paste the token into `github_token` in `/etc/figma-receiver-config.php`.

(Classic-token alternative: a token with the `repo` scope also works if the fine-grained org
setup is a hassle.)

## 5. Apache — a vhost for the subdomain + HTTPS

Make sure PHP is enabled in Apache (it already is if your Halo site runs PHP):
`php -v` and `apache2ctl -M | grep php` should both show something.

```bash
sudo tee /etc/apache2/sites-available/hooks.conf >/dev/null <<'EOF'
<VirtualHost *:80>
    ServerName hooks.example.com
    DocumentRoot /var/www/hooks
    <Directory /var/www/hooks>
        Require all granted
    </Directory>
</VirtualHost>
EOF

sudo a2ensite hooks
sudo systemctl reload apache2

# TLS cert for the subdomain (adds HTTPS + auto-redirect):
sudo apt install -y certbot python3-certbot-apache
sudo certbot --apache -d hooks.example.com
```

Verify from your laptop — a GET should be rejected (the receiver only accepts POST),
which proves it's reachable and running:
```bash
curl -i https://hooks.example.com/figma-webhook.php   # -> HTTP/1.1 405 method not allowed
```

## 6. Register the Figma webhook (once)

You need a current **Figma OAuth access token** with `webhooks:write`, and your **team id**
(from the Figma URL when viewing the team: `figma.com/files/team/<TEAM_ID>/...`).

Mint a fresh access token from your existing refresh token (the same creds the CI uses —
substitute your client id/secret/refresh token; don't paste them anywhere persistent):
```bash
curl -s -X POST 'https://api.figma.com/v1/oauth/refresh' \
  -u '<FIGMA_CLIENT_ID>:<FIGMA_CLIENT_SECRET>' \
  -d 'refresh_token=<FIGMA_REFRESH_TOKEN>'
# -> {"access_token":"...","expires_in":...}   (copy the access_token)
```

Then register the webhook pointing at your subdomain:
```bash
curl -X POST 'https://api.figma.com/v2/webhooks' \
  -H 'Authorization: Bearer <FIGMA_ACCESS_TOKEN>' \
  -H 'Content-Type: application/json' \
  -d '{
    "event_type": "LIBRARY_PUBLISH",
    "team_id": "<TEAM_ID>",
    "endpoint": "https://hooks.example.com/figma-webhook.php",
    "passcode": "<A_LONG_RANDOM_PASSCODE>",
    "description": "Halo email design-system publish events"
  }'
```

Figma immediately sends a `PING`; the receiver acks it `200`. Confirm it's active:
```bash
curl -H 'Authorization: Bearer <FIGMA_ACCESS_TOKEN>' \
  https://api.figma.com/v2/teams/<TEAM_ID>/webhooks
```

## 7. End-to-end test

In Figma, **publish** the library (Assets → publish). Within a few seconds:
1. a new run appears in the repo's **Actions** tab,
2. an auto-commit lands on `main` if the design changed.

---

### Operating notes
- **Logs:** receiver errors go to Apache's error log — `sudo tail -f /var/log/apache2/error.log`.
- **Change a secret:** edit `/etc/figma-receiver-config.php` — no restart needed (PHP reads it per request).
- **Trigger is publish, not every edit:** editing in Figma does nothing until you publish.
- **Coarse rebuilds:** a publish rebuilds *every* mapped block in that file, not only the
  changed one. Correct, just not minimal — see the "Known follow-up" in README.md.
- `app.py` is the equivalent Python/Flask receiver, kept only as a reference. Deploy **one**
  of the two — for this stack, the PHP file is the simpler choice.
