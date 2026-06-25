<?php
/**
 * figma-pipeline/receiver/figma-webhook.php
 *
 * The always-on webhook receiver (Stage 1), in PHP so it runs under your existing
 * Apache + PHP stack with no extra runtime (no Python/venv/gunicorn/systemd).
 *
 * Figma POSTs a publish event here; this validates it, looks up which blocks are
 * affected in figma_manifest.json, and triggers the GitHub Actions builder
 * (figma-build.yml) once per affected block via repository_dispatch.
 *
 * Secrets are NOT in this file or the repo. They live in a separate config file
 * outside the webroot (same pattern as brief/config.php). Default location:
 *   /etc/figma-receiver-config.php   (override with the FIGMA_RECEIVER_CONFIG env)
 * which must `return` an array:
 *   <?php return [
 *     'passcode'     => '...your long random passcode...',
 *     'github_token' => 'github_pat_...',          // Contents: R/W on the repo
 *     'github_repo'  => 'Topdecileinc/halo-resources',
 *     'manifest_url' => 'https://raw.githubusercontent.com/Topdecileinc/halo-resources/main/figma-pipeline/figma_manifest.json',
 *   ];
 *
 * Deploy: see DEPLOY.md. (app.py is the equivalent Python/Flask reference — use ONE.)
 */

// Content events worth rebuilding on. LIBRARY_PUBLISH is the intended trigger (an
// intentional publish); the others cover the case where the source is a plain file.
const CONTENT_EVENTS = ['LIBRARY_PUBLISH', 'FILE_UPDATE', 'FILE_VERSION_UPDATE'];

/** Always answer Figma fast with a status + tiny body, then stop. */
function respond(int $code, string $body = ''): void {
    http_response_code($code);
    echo $body;
    exit;
}

/**
 * Append a debug line to the log file (best-effort; NEVER logs the passcode).
 * Default path /var/log/figma-receiver.log; override with 'log_file' in the config.
 * Falls back to the Apache error log if the file isn't writable.
 */
function dbg(array $cfg, string $msg): void {
    $line = gmdate('c') . ' ' . $msg . "\n";
    $path = $cfg['log_file'] ?? '/var/log/figma-receiver.log';
    if (@file_put_contents($path, $line, FILE_APPEND | LOCK_EX) === false) {
        error_log('figma-webhook ' . $msg);
    }
}

/** Load the secrets file (outside the webroot, never in git). */
function load_config(): array {
    $path = getenv('FIGMA_RECEIVER_CONFIG') ?: '/etc/figma-receiver-config.php';
    if (!is_file($path)) {
        error_log("figma-webhook: config file not found at $path");
        respond(500, 'server misconfigured');
    }
    $cfg = require $path;
    foreach (['passcode', 'github_token', 'github_repo', 'manifest_url'] as $k) {
        if (empty($cfg[$k])) {
            error_log("figma-webhook: config missing '$k'");
            respond(500, 'server misconfigured');
        }
    }
    return $cfg;
}

/**
 * Best-effort dedupe across requests. Webhooks can fire duplicates; PHP is
 * stateless per request, so we keep recent event ids in a small temp file under a
 * lock. (More durable than the in-memory list in app.py.) For high volume, swap
 * this for Redis/a DB keyed on a stable event id.
 */
function already_seen(string $eid, int $maxlen = 512): bool {
    $path = sys_get_temp_dir() . '/figma-receiver-seen.json';
    $fp = @fopen($path, 'c+');
    if (!$fp) return false;                      // can't dedupe -> don't block the event
    flock($fp, LOCK_EX);
    $raw  = stream_get_contents($fp);
    $seen = $raw ? json_decode($raw, true) : [];
    if (!is_array($seen)) $seen = [];
    if (in_array($eid, $seen, true)) {
        flock($fp, LOCK_UN); fclose($fp);
        return true;
    }
    $seen[] = $eid;
    if (count($seen) > $maxlen) $seen = array_slice($seen, -$maxlen);
    ftruncate($fp, 0); rewind($fp); fwrite($fp, json_encode($seen));
    flock($fp, LOCK_UN); fclose($fp);
    return false;
}

/** GET the manifest (the figma-node -> output-file map). */
function load_manifest(string $url): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_USERAGENT      => 'halo-figma-receiver',
    ]);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    if ($body === false || $code >= 400) {
        error_log("figma-webhook: manifest fetch failed ($code) from $url");
        return [];
    }
    $data = json_decode($body, true);
    return is_array($data) ? $data : [];
}

/** Trigger the figma-build workflow for one block via repository_dispatch. Returns the HTTP code (204 = ok). */
function dispatch_build(array $cfg, string $fileKey, string $nodeId, string $outputPath): int {
    $payload = json_encode([
        'event_type'     => 'figma-publish',
        'client_payload' => [
            'file_key'    => $fileKey,
            'node_id'     => $nodeId,
            'output_path' => $outputPath,
        ],
    ]);
    $ch = curl_init("https://api.github.com/repos/{$cfg['github_repo']}/dispatches");
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_USERAGENT      => 'halo-figma-receiver',  // GitHub requires a UA
        CURLOPT_HTTPHEADER     => [
            "Authorization: Bearer {$cfg['github_token']}",
            'Accept: application/vnd.github+json',
            'X-GitHub-Api-Version: 2022-11-28',
            'Content-Type: application/json',
        ],
    ]);
    curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);   // 204 on success
    curl_close($ch);
    return $code;
}

// ---- request handling --------------------------------------------------------

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    respond(405, 'method not allowed');
}

$cfg     = load_config();
$payload = json_decode(file_get_contents('php://input'), true) ?: [];

dbg($cfg, sprintf('POST event=%s status=%s file=%s node=%s',
    $payload['event_type'] ?? '-', $payload['status'] ?? '-',
    $payload['file_key'] ?? '-', $payload['node_id'] ?? '-'));

// 1. validate the passcode Figma echoes back
if (($payload['passcode'] ?? null) !== $cfg['passcode']) {
    dbg($cfg, '  -> 403 passcode mismatch');
    respond(403, 'invalid passcode');
}

$event = $payload['event_type'] ?? null;

// 2. Figma sends a PING when the webhook is created — ack it
if ($event === 'PING') {
    dbg($cfg, '  -> PING ack');
    respond(200);
}

// 3. dedupe (best-effort) — include node id so two layers flagged together don't collide
$eid = $event . ':' . ($payload['file_key'] ?? '') . ':' . ($payload['node_id'] ?? '')
     . ':' . ($payload['timestamp'] ?? '');
if (already_seen($eid)) {
    dbg($cfg, '  -> duplicate, skipped');
    respond(200);
}

// 4. DEV_MODE_STATUS_UPDATE — the "Ready for dev" trigger. Precise: the event names the
//    exact node flagged, so we rebuild ONLY that block (not the whole file). We act only
//    when a layer is marked READY_FOR_DEV; COMPLETED / NONE (cleared) are ignored.
if ($event === 'DEV_MODE_STATUS_UPDATE') {
    if (($payload['status'] ?? null) === 'READY_FOR_DEV') {
        $fileKey  = $payload['file_key'] ?? '';
        $nodeId   = $payload['node_id'] ?? '';
        $manifest = load_manifest($cfg['manifest_url']);
        $matched  = 0;
        foreach (($manifest['targets'] ?? []) as $t) {
            if (($t['figma_file_key'] ?? null) === $fileKey
                && ($t['figma_node_id'] ?? null) === $nodeId) {
                $code = dispatch_build($cfg, $fileKey, $nodeId, $t['output_path']);
                dbg($cfg, "  -> dispatched {$t['output_path']} (GitHub HTTP $code)");
                $matched++;
            }
        }
        if ($matched === 0) {
            dbg($cfg, "  -> no manifest target matched file=$fileKey node=$nodeId");
        }
    } else {
        dbg($cfg, '  -> ignored (status not READY_FOR_DEV)');
    }
    respond(200);
}

// 5. on a library/file content event, rebuild every target in that file
if (in_array($event, CONTENT_EVENTS, true)) {
    $fileKey  = $payload['file_key'] ?? '';
    $manifest = load_manifest($cfg['manifest_url']);
    foreach (($manifest['targets'] ?? []) as $t) {
        // NOTE: LIBRARY_PUBLISH reports *which components* changed (by component key),
        // but the manifest keys on node id, so we can't yet filter to just the changed
        // ones — this rebuilds every target in that file (correct, just coarse). The
        // DEV_MODE_STATUS_UPDATE path above IS precise — prefer it as the trigger.
        if (($t['figma_file_key'] ?? null) === $fileKey) {
            dispatch_build($cfg, $fileKey, $t['figma_node_id'], $t['output_path']);
        }
    }
}

// always 200 quickly so Figma doesn't retry/disable the webhook
respond(200);
