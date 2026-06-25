# Deploying the receiver on a VM (Apache + a subdomain)

This is the always-on piece that makes the pipeline automatic: Figma publish → this
receiver → it triggers the GitHub builder workflow. Do it once.

Assumes a Debian/Ubuntu VM with Apache already serving HTTPS, and that you can add a
DNS subdomain (e.g. `hooks.example.com`). Adjust paths/package names for other distros.

Throughout, replace these placeholders:

| Placeholder | What it is |
|---|---|
| `hooks.example.com` | the subdomain you'll point at this VM |
| `<VM_PUBLIC_IP>` | the VM's public IP |
| `<A_LONG_RANDOM_PASSCODE>` | a secret you invent now (e.g. `openssl rand -hex 24`) — Figma echoes it back so the receiver can trust the call |
| `<GITHUB_PAT>` | a GitHub token that can trigger the build (created in step 5) |

---

## 1. DNS — point the subdomain at the VM

In your DNS provider, add an **A record**: `hooks.example.com` → `<VM_PUBLIC_IP>`.
Wait for it to resolve (`dig +short hooks.example.com` should print the IP).

## 2. Put the code on the VM and install dependencies

```bash
sudo mkdir -p /opt/figma-receiver
sudo chown "$USER" /opt/figma-receiver
# copy app.py onto the VM (scp, git clone of this repo's figma-pipeline/receiver/, etc.)
cp app.py /opt/figma-receiver/app.py

sudo apt update
sudo apt install -y python3-venv
python3 -m venv /opt/figma-receiver/venv
/opt/figma-receiver/venv/bin/pip install flask gunicorn
```

## 3. Store the secrets (root-only file, never in the repo)

```bash
sudo tee /etc/figma-receiver.env >/dev/null <<'EOF'
FIGMA_WEBHOOK_PASSCODE=<A_LONG_RANDOM_PASSCODE>
GITHUB_TOKEN=<GITHUB_PAT>
GITHUB_REPO=Topdecileinc/halo-resources
MANIFEST_URL=https://raw.githubusercontent.com/Topdecileinc/halo-resources/main/figma-pipeline/figma_manifest.json
PORT=8080
EOF
sudo chmod 600 /etc/figma-receiver.env
```

## 4. Run it as a service (systemd + gunicorn)

```bash
sudo tee /etc/systemd/system/figma-receiver.service >/dev/null <<'EOF'
[Unit]
Description=Figma webhook receiver
After=network.target

[Service]
EnvironmentFile=/etc/figma-receiver.env
WorkingDirectory=/opt/figma-receiver
ExecStart=/opt/figma-receiver/venv/bin/gunicorn --bind 127.0.0.1:8080 --workers 2 app:app
Restart=always
User=www-data

[Install]
WantedBy=multi-user.target
EOF

sudo systemctl daemon-reload
sudo systemctl enable --now figma-receiver
curl -s localhost:8080/healthz   # -> ok
```

It listens only on localhost:8080; Apache (next step) is the public front door.

## 5. Create the GitHub token (least privilege)

GitHub → Settings → Developer settings → **Fine-grained tokens** → Generate:
- Repository access: **only** `Topdecileinc/halo-resources`
- Permissions: **Contents: Read and write** + **Metadata: Read** (dispatch rides on Contents)
- Copy the token into `GITHUB_TOKEN` in `/etc/figma-receiver.env`, then
  `sudo systemctl restart figma-receiver`.

## 6. Apache — reverse proxy + HTTPS for the subdomain

```bash
sudo a2enmod proxy proxy_http ssl

sudo tee /etc/apache2/sites-available/hooks.conf >/dev/null <<'EOF'
<VirtualHost *:80>
    ServerName hooks.example.com
    ProxyPreserveHost On
    ProxyPass        /figma-webhook http://127.0.0.1:8080/figma-webhook
    ProxyPassReverse /figma-webhook http://127.0.0.1:8080/figma-webhook
    ProxyPass        /healthz       http://127.0.0.1:8080/healthz
    ProxyPassReverse /healthz       http://127.0.0.1:8080/healthz
</VirtualHost>
EOF

sudo a2ensite hooks
sudo systemctl reload apache2

# TLS cert for the subdomain (installs HTTPS + auto-redirect):
sudo apt install -y certbot python3-certbot-apache
sudo certbot --apache -d hooks.example.com
```

Verify from your laptop: `curl https://hooks.example.com/healthz` → `ok`.

## 7. Register the Figma webhook (once)

You need a current **Figma OAuth access token** with `webhooks:write`, and your **team id**
(in the Figma URL when viewing the team: `figma.com/files/team/<TEAM_ID>/...`).

```bash
curl -X POST 'https://api.figma.com/v2/webhooks' \
  -H 'Authorization: Bearer <FIGMA_ACCESS_TOKEN>' \
  -H 'Content-Type: application/json' \
  -d '{
    "event_type": "LIBRARY_PUBLISH",
    "team_id": "<TEAM_ID>",
    "endpoint": "https://hooks.example.com/figma-webhook",
    "passcode": "<A_LONG_RANDOM_PASSCODE>",
    "description": "Halo email design-system publish events"
  }'
```

Figma immediately sends a `PING`; the receiver acks it `200`. Confirm the webhook is
active: `curl -H 'Authorization: Bearer <FIGMA_ACCESS_TOKEN>' https://api.figma.com/v2/teams/<TEAM_ID>/webhooks`.

## 8. End-to-end test

In Figma, **publish** the library (Assets → publish). Within a few seconds you should see:
1. `sudo journalctl -u figma-receiver -n 20` — a logged publish event,
2. a new run in the repo's **Actions** tab,
3. an auto-commit on `main` if the design changed.

---

### Operating notes
- **Logs:** `sudo journalctl -u figma-receiver -f`
- **Restart after a code/secret change:** `sudo systemctl restart figma-receiver`
- **Coarse rebuilds:** a publish currently rebuilds *every* mapped block in that file, not
  only the changed one. Correct, just not minimal — see the "Known follow-up" in README.md.
- **Trigger is publish, not every edit:** editing in Figma does nothing until you publish.
