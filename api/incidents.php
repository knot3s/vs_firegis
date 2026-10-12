<?php
// GET   /api/incidents              — list all incidents (newest first)
// PATCH /api/incidents/{id}/status   — update lifecycle status
require_once __DIR__ . '/../lib.php';

$method = $_SERVER['REQUEST_METHOD'];

// --- GET: list incidents ---
if ($method === 'GET') {
    $incidents = load_incidents();
    usort($incidents, fn($a, $b) => strcmp($b['created_at'], $a['created_at']));
    $status = $_GET['lifecycle'] ?? null;
    if ($status) {
        $incidents = array_values(array_filter($incidents, fn($i) => ($i['lifecycle_status'] ?? '') === $status));
    }
    json_response(['count' => count($incidents), 'incidents' => $incidents]);
}

// --- PATCH: update status ---
if ($method === 'PATCH') {
    // Path is like /api/incidents/INC-XXX/status
    $path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    if (!preg_match('#/api/incidents/([^/]+)/status#', $path, $m)) {
        json_response(['error' => 'Not found'], 404);
    }
    $id = $m[1];
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

json_response(['error' => 'Method not allowed'], 405);
