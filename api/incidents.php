<?php
// Incident management — supports BOTH:
//   Path-based (via .htaccess): GET/PATCH/DELETE /api/incidents[/id[/status]]
//   Direct file access:         GET/POST api/incidents.php[?id=XXX&action=status|delete]
//
// Using POST with ?action= is the most reliable on shared hosting (some block PATCH/DELETE).
require_once __DIR__ . '/../lib.php';

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';
$id     = $_GET['id'] ?? '';

// --- GET: list incidents ---
if ($method === 'GET' && $action === '') {
    $incidents = load_incidents();
    usort($incidents, fn($a, $b) => strcmp($b['created_at'], $a['created_at']));
    $status = $_GET['lifecycle'] ?? null;
    if ($status) {
        $incidents = array_values(array_filter($incidents, fn($i) => ($i['lifecycle_status'] ?? '') === $status));
    }
    json_response(['count' => count($incidents), 'incidents' => $incidents]);
}

// --- PATCH or POST with action=status: update lifecycle status ---
if (($method === 'PATCH') || ($method === 'POST' && $action === 'status')) {
    // Try path-based ID first (/api/incidents/INC-XXX/status), fall back to ?id=
    if ($id === '') {
        $path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
        if (preg_match('#/api/incidents/([^/]+)/status#', $path, $m)) {
            $id = $m[1];
        }
    }
    if ($id === '') {
        json_response(['error' => 'Incident id required (use ?id=XXX).'], 400);
    }

    $body = read_body();
    $next = (string)($body['lifecycle_status'] ?? '');

    if (!in_array($next, LIFECYCLE, true)) {
        json_response(['error' => 'Invalid lifecycle status.', 'allowed' => LIFECYCLE], 400);
    }

    $incidents = load_incidents();
    $found = null;
    foreach ($incidents as &$inc) {
        if (($inc['id'] ?? '') === $id) { $found = &$inc; break; }
    }
    unset($inc);

    if (!$found) {
        json_response(['error' => 'Incident not found.'], 404);
    }

    $found['lifecycle_status'] = $next;
    $found['updated_at'] = gmdate('Y-m-d\TH:i:s\Z');
    $found['updated_by'] = $body['updated_by'] ?? 'fire_personnel';
    save_incidents($incidents);

    json_response(['ok' => true, 'incident' => $found]);
}

// --- DELETE or POST with action=delete: remove an incident ---
if (($method === 'DELETE') || ($method === 'POST' && $action === 'delete')) {
    if ($id === '') {
        $path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
        if (preg_match('#/api/incidents/([^/]+)$#', $path, $m)) {
            $id = $m[1];
        }
    }
    if ($id === '') {
        json_response(['error' => 'Incident id required (use ?id=XXX).'], 400);
    }

    $incidents = load_incidents();
    $before = count($incidents);
    $incidents = array_values(array_filter($incidents, fn($i) => ($i['id'] ?? '') !== $id));
    if (count($incidents) === $before) {
        json_response(['error' => 'Incident not found.'], 404);
    }
    save_incidents($incidents);
    json_response(['ok' => true, 'deleted' => $id]);
}

json_response(['error' => 'Method not allowed'], 405);
