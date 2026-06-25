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

/** Trigger the figma-build workflow for one block via repository_dispatch. */
function dispatch_build(array $cfg, string $fileKey, string $nodeId, string $outputPath): void {
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
    if ($code !== 204) {
        error_log("figma-webhook: dispatch for $outputPath returned HTTP $code");
    }
}

// ---- request handling --------------------------------------------------------

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    respond(405, 'method not allowed');
}

$cfg     = load_config();
$payload = json_decode(file_get_contents('php://input'), true) ?: [];

// 1. validate the passcode Figma echoes back
if (($payload['passcode'] ?? null) !== $cfg['passcode']) {
    respond(403, 'invalid passcode');
}

$event = $payload['event_type'] ?? null;

// 2. Figma sends a PING when the webhook is created — ack it
if ($event === 'PING') {
    respond(200);
}

// 3. dedupe (best-effort)
$eid = $event . ':' . ($payload['file_key'] ?? '') . ':' . ($payload['timestamp'] ?? '');
if (already_seen($eid)) {
    respond(200);
}

// 4. on a content event for a file we build from, rebuild its targets
if (in_array($event, CONTENT_EVENTS, true)) {
    $fileKey  = $payload['file_key'] ?? '';
    $manifest = load_manifest($cfg['manifest_url']);
    foreach (($manifest['targets'] ?? []) as $t) {
        // NOTE: LIBRARY_PUBLISH reports *which components* changed (by component key),
        // but the manifest keys on node id, so we can't yet filter to just the changed
        // ones — this rebuilds every target in that file (correct, just coarse). To make
        // it precise, record the published component key in the figma-source comment /
        // manifest, then match payload['created_components'/'modified_components'] here.
        if (($t['figma_file_key'] ?? null) === $fileKey) {
            dispatch_build($cfg, $fileKey, $t['figma_node_id'], $t['output_path']);
        }
    }
}

// always 200 quickly so Figma doesn't retry/disable the webhook
respond(200);
