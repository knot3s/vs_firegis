<?php
/**
 * EMERGENCY RESPONSE IN FIRE (GIS) — PHP backend for shared hosting (Hostinger, etc.)
 *
 * Drop-in replacement for server.js (Node/Express). No npm, no build step.
 * The frontend in public/ calls these exact same /api/* endpoints, so no
 * HTML changes are needed.
 *
 * Persistence: data/store.json (file-based). Make data/ writable (CHMOD 755).
 */

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PATCH, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// ---------------------------------------------------------------------------
// Configuration
// ---------------------------------------------------------------------------
const MAP_CENTER = ['lat' => 7.5333, 'lng' => 123.1667];
const STATION = [
    'name'    => 'VAS Fire Station (Kabatan)',
    'lat'     => 7.5164,
    'lng'     => 123.1697,
    'address' => 'Poblacion, Kabatan, Vincenzo A. Sagun, Zamboanga del Sur',
];
const EARTH_RADIUS_M      = 6371000;
const VERIFY_THRESHOLD_M  = 100;
const LIFECYCLE = ['Pending', 'Dispatched', 'Under Control', 'Fire Out', 'False Alarm'];

const BARANGAYS = [
    ['name' => 'Kabatan',       'isPoblacion' => true,  'population' => 5059, 'lat' => 7.5164, 'lng' => 123.1697],
    ['name' => 'Danan',         'isPoblacion' => false, 'population' => 2609, 'lat' => 7.5220, 'lng' => 123.1820],
    ['name' => 'Ambulon',       'isPoblacion' => false, 'population' => 1302, 'lat' => 7.5280, 'lng' => 123.1750],
    ['name' => 'Kapatagan',     'isPoblacion' => false, 'population' => 1716, 'lat' => 7.5100, 'lng' => 123.1850],
    ['name' => 'Limason',       'isPoblacion' => false, 'population' => 1683, 'lat' => 7.5200, 'lng' => 123.1550],
    ['name' => 'Lunib',         'isPoblacion' => false, 'population' => 1714, 'lat' => 7.5080, 'lng' => 123.1520],
    ['name' => 'Cogon',         'isPoblacion' => false, 'population' => 1574, 'lat' => 7.5000, 'lng' => 123.1600],
    ['name' => 'Bui-os',        'isPoblacion' => false, 'population' => 1479, 'lat' => 7.4980, 'lng' => 123.1720],
    ['name' => 'Maraya',        'isPoblacion' => false, 'population' => 1430, 'lat' => 7.4920, 'lng' => 123.1800],
    ['name' => 'Sagucan',       'isPoblacion' => false, 'population' => 1272, 'lat' => 7.5050, 'lng' => 123.1900],
    ['name' => 'Waling-waling', 'isPoblacion' => false, 'population' => 1818, 'lat' => 7.5300, 'lng' => 123.1600],
    ['name' => 'Lumbal',        'isPoblacion' => false, 'population' => 1282, 'lat' => 7.5350, 'lng' => 123.1680],
    ['name' => 'Linoguayan',    'isPoblacion' => false, 'population' => 930,  'lat' => 7.5250, 'lng' => 123.1480],
    ['name' => 'Maculay',       'isPoblacion' => false, 'population' => 984,  'lat' => 7.5150, 'lng' => 123.1400],
];

$dataDir  = __DIR__ . '/data';
$storePath = $dataDir . '/store.json';

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------
function json_response($data, $code = 200) {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function read_body() {
    $raw = file_get_contents('php://input');
    if ($raw === '' || $raw === false) return [];
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
}

function default_store() {
    return [
        'users' => [
            [
                'id'         => 'usr-command-001',
                'full_name'  => 'VAS Fire Station Command',
                'phone'      => '0917-000-0000',
                'username'   => 'firestaff',
                'password'   => 'vas-bfp-2026',
                'role'       => 'fire_personnel',
            ],
        ],
        'incidents' => [],
    ];
}

function read_store() {
    global $storePath;
    if (!is_file($storePath)) return default_store();
    $fp = fopen($storePath, 'r');
    if (!$fp) return default_store();
    flock($fp, LOCK_SH);
    $raw = stream_get_contents($fp);
    flock($fp, LOCK_UN);
    fclose($fp);
    $data = json_decode($raw, true);
    return is_array($data) ? $data : default_store();
}

function write_store($store) {
    global $dataDir, $storePath;
    if (!is_dir($dataDir)) {
        @mkdir($dataDir, 0755, true);
    }
    $fp = fopen($storePath, 'c');
    if (!$fp) {
        error_log('[store] cannot open store for writing: ' . $storePath);
        return false;
    }
    flock($fp, LOCK_EX);
    ftruncate($fp, 0);
    rewind($fp);
    fwrite($fp, json_encode($store, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    fflush($fp);
    flock($fp, LOCK_UN);
    fclose($fp);
    return true;
}

function haversine_meters($lat1, $lng1, $lat2, $lng2) {
    foreach ([$lat1, $lng1, $lat2, $lng2] as $n) {
        if (!is_finite_number($n)) return null;
    }
    $lat1 = (float)$lat1; $lng1 = (float)$lng1; $lat2 = (float)$lat2; $lng2 = (float)$lng2;
    $phi1 = deg2rad($lat1);
    $phi2 = deg2rad($lat2);
    $dPhi = deg2rad($lat2 - $lat1);
    $dLam = deg2rad($lng2 - $lng1);
    $sinDPhi = sin($dPhi / 2);
    $sinDLam = sin($dLam / 2);
    $a = $sinDPhi * $sinDPhi + cos($phi1) * cos($phi2) * $sinDLam * $sinDLam;
    $c = 2 * atan2(sqrt($a), sqrt(1 - $a));
    return EARTH_RADIUS_M * $c;
}

function is_finite_number($n) {
    return is_numeric($n) && is_finite((float)$n);
}

function classify_spatial($deviceLat, $deviceLng, $pinnedLat, $pinnedLng) {
    $d = haversine_meters($deviceLat, $deviceLng, $pinnedLat, $pinnedLng);
    if ($d === null) {
        return [
            'discrepancy_meters' => null,
            'spatial_status'     => 'Unverified / Spatial Discrepancy',
            'reason'             => 'Device GPS unavailable — cannot corroborate pinned location.',
        ];
    }
    $verified = $d <= VERIFY_THRESHOLD_M;
    return [
        'discrepancy_meters' => round($d * 10) / 10,
        'spatial_status'     => $verified ? 'Verified Spatial Alert' : 'Unverified / Spatial Discrepancy',
        'reason'             => $verified
            ? 'Pinned marker is ' . round($d) . ' m from device GPS (<= ' . VERIFY_THRESHOLD_M . ' m).'
            : 'Pinned marker is ' . round($d) . ' m from device GPS (> ' . VERIFY_THRESHOLD_M . ' m).',
    ];
}

function square_polygon($lat, $lng, $halfKm = 0.7) {
    $dLat = $halfKm / 111.32;
    $dLng = $halfKm / (111.32 * cos(deg2rad($lat)));
    return [[
        [$lng - $dLng, $lat - $dLat],
        [$lng + $dLng, $lat - $dLat],
        [$lng + $dLng, $lat + $dLat],
        [$lng - $dLng, $lat + $dLat],
        [$lng - $dLng, $lat - $dLat],
    ]];
}

function barangays_as_geojson() {
    $features = [];
    foreach (BARANGAYS as $i => $b) {
        $features[] = [
            'type'       => 'Feature',
            'id'         => $i + 1,
            'properties' => [
                'id'              => $i + 1,
                'name'            => $b['name'],
                'is_poblacion'    => $b['isPoblacion'],
                'population_2020' => $b['population'],
            ],
            'geometry' => [
                'type'        => 'Polygon',
                'coordinates' => square_polygon($b['lat'], $b['lng']),
            ],
        ];
    }
    return [
        'type' => 'FeatureCollection',
        'name' => 'VAS_Barangay_Zones_Schematic',
        'crs'  => ['type' => 'name', 'properties' => ['name' => 'EPSG:4326']],
        'features' => $features,
    ];
}

function gen_incident_id() {
    return 'INC-' . strtoupper(base_convert((string)time(), 10, 36)) . '-' . random_int(100, 999);
}

// ---------------------------------------------------------------------------
// Routing
// ---------------------------------------------------------------------------
$method = $_SERVER['REQUEST_METHOD'];
$path   = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
// Normalize: strip a possible subdirectory prefix if the app is hosted in a folder
$scriptName = dirname($_SERVER['SCRIPT_NAME'] ?? '');
if ($scriptName !== '/' && $scriptName !== '\\' && $scriptName !== '.') {
    if (strpos($path, $scriptName) === 0) {
        $path = substr($path, strlen($scriptName));
    }
}
$path = '/' . trim($path, '/');

// GET /health
if ($method === 'GET' && $path === '/health') {
    json_response(['ok' => true, 'persistence' => 'json', 'uptime' => 0]);
}

// GET /api/meta
if ($method === 'GET' && $path === '/api/meta') {
    json_response([
        'title'               => 'EMERGENCY RESPONSE IN FIRE (GIS)',
        'municipality'        => 'Vincenzo A. Sagun, Zamboanga del Sur',
        'mapCenter'           => MAP_CENTER,
        'station'             => STATION,
        'verifyThresholdMeters' => VERIFY_THRESHOLD_M,
        'earthRadiusMeters'   => EARTH_RADIUS_M,
        'lifecycle'           => LIFECYCLE,
        'persistence'         => 'json',
    ]);
}

// GET /api/barangays
if ($method === 'GET' && $path === '/api/barangays') {
    json_response(barangays_as_geojson());
}

// GET /api/station
if ($method === 'GET' && $path === '/api/station') {
    json_response(STATION);
}

// POST /api/auth/login
if ($method === 'POST' && $path === '/api/auth/login') {
    $body = read_body();
    $role = $body['role'] ?? '';
    if ($role === 'resident') {
        json_response([
            'ok'    => true,
            'role'  => 'resident',
            'token' => 'resident-public',
            'name'  => 'Public Reporter',
        ]);
    }
    $store = read_store();
    $user = null;
    foreach ($store['users'] as $u) {
        if (($u['username'] ?? '') === ($body['username'] ?? '')
            && ($u['password'] ?? '') === ($body['password'] ?? '')
            && ($u['role'] ?? '') === 'fire_personnel') {
            $user = $u;
            break;
        }
    }
    if (!$user) {
        json_response(['ok' => false, 'error' => 'Invalid fire personnel credentials.'], 401);
    }
    json_response([
        'ok'       => true,
        'role'     => 'fire_personnel',
        'token'    => 'personnel-' . $user['id'],
        'name'     => $user['full_name'],
        'username' => $user['username'],
    ]);
}

// POST /api/reports
if ($method === 'POST' && $path === '/api/reports') {
    $body = read_body();
    $reporter_name  = trim((string)($body['reporter_name'] ?? ''));
    $reporter_phone = trim((string)($body['reporter_phone'] ?? ''));
    $barangay_name  = trim((string)($body['barangay_name'] ?? ''));
    $notes          = trim((string)($body['notes'] ?? ''));
    $pinned_lat     = isset($body['pinned_lat']) ? (float)$body['pinned_lat'] : NAN;
    $pinned_lng     = isset($body['pinned_lng']) ? (float)$body['pinned_lng'] : NAN;

    $rawDevLat = $body['device_lat'] ?? null;
    $rawDevLng = $body['device_lng'] ?? null;
    $device_lat = ($rawDevLat === null || $rawDevLat === '') ? null : (float)$rawDevLat;
    $device_lng = ($rawDevLng === null || $rawDevLng === '') ? null : (float)$rawDevLng;

    if ($reporter_name === '' || $reporter_phone === '' || $barangay_name === '') {
        json_response(['error' => 'Name, phone, and barangay are required.'], 400);
    }
    if (!is_finite_number($pinned_lat) || !is_finite_number($pinned_lng)) {
        json_response(['error' => 'Pinned coordinates are required.'], 400);
    }
    $known = false;
    foreach (BARANGAYS as $b) {
        if ($b['name'] === $barangay_name) { $known = true; break; }
    }
    if (!$known) {
        json_response(['error' => 'Unknown barangay.'], 400);
    }

    $spatial = classify_spatial($device_lat, $device_lng, $pinned_lat, $pinned_lng);
    $now = gmdate('Y-m-d\TH:i:s\Z');
    $incident = [
        'id'                 => gen_incident_id(),
        'reporter_name'      => $reporter_name,
        'reporter_phone'     => $reporter_phone,
        'barangay_name'      => $barangay_name,
        'notes'              => $notes,
        'device_lat'         => ($device_lat !== null && is_finite($device_lat)) ? $device_lat : null,
        'device_lng'         => ($device_lng !== null && is_finite($device_lng)) ? $device_lng : null,
        'pinned_lat'         => $pinned_lat,
        'pinned_lng'         => $pinned_lng,
        'discrepancy_meters' => $spatial['discrepancy_meters'],
        'spatial_status'     => $spatial['spatial_status'],
        'spatial_reason'     => $spatial['reason'],
        'lifecycle_status'   => 'Pending',
        'created_at'         => $now,
        'updated_at'         => $now,
        'updated_by'         => null,
    ];

    $store = read_store();
    $store['incidents'][] = $incident;
    write_store($store);
    json_response(['ok' => true, 'incident' => $incident], 201);
}

// GET /api/incidents
if ($method === 'GET' && $path === '/api/incidents') {
    $store = read_store();
    $rows = $store['incidents'];
    usort($rows, fn($a, $b) => strcmp($b['created_at'], $a['created_at']));
    $status = $_GET['lifecycle'] ?? null;
    if ($status) {
        $rows = array_values(array_filter($rows, fn($i) => ($i['lifecycle_status'] ?? '') === $status));
    }
    json_response(['count' => count($rows), 'incidents' => $rows]);
}

// PATCH /api/incidents/{id}/status
if ($method === 'PATCH' && preg_match('#^/api/incidents/([^/]+)/status$#', $path, $m)) {
    $id = $m[1];
    $body = read_body();
    $next = (string)($body['lifecycle_status'] ?? '');
    if (!in_array($next, LIFECYCLE, true)) {
        json_response(['error' => 'Invalid lifecycle status.', 'allowed' => LIFECYCLE], 400);
    }
    $store = read_store();
    $found = null;
    foreach ($store['incidents'] as &$inc) {
        if (($inc['id'] ?? '') === $id) { $found = &$inc; break; }
    }
    unset($inc);
    if (!$found) {
        json_response(['error' => 'Incident not found.'], 404);
    }
    $found['lifecycle_status'] = $next;
    $found['updated_at'] = gmdate('Y-m-d\TH:i:s\Z');
    $found['updated_by'] = $body['updated_by'] ?? 'fire_personnel';
    write_store($store);
    json_response(['ok' => true, 'incident' => $found]);
}

// GET /api/stream (Server-Sent Events)
if ($method === 'GET' && $path === '/api/stream') {
    header('Content-Type: text/event-stream; charset=utf-8');
    header('Cache-Control: no-cache, no-transform');
    header('Connection: keep-alive');
    header('X-Accel-Buffering: no');
    @ini_set('zlib.output_compression', '0');
    @ini_set('output_buffering', '0');
    while (ob_get_level()) ob_end_flush();

    echo "event: hello\ndata: " . json_encode(['ok' => true, 't' => time()]) . "\n\n";
    flush();

    // Watch store.json for changes; broadcast a 'status' event to trigger a reload.
    global $storePath;
    $lastMtime = is_file($storePath) ? filemtime($storePath) : 0;
    $end = time() + 55; // keep connection alive ~55s, browser auto-reconnects
    while (time() < $end) {
        if (connection_aborted()) break;
        clearstatcache(true, $storePath);
        $mtime = is_file($storePath) ? filemtime($storePath) : 0;
        if ($mtime > $lastMtime) {
            $lastMtime = $mtime;
            echo "event: status\ndata: " . json_encode(['ok' => true, 't' => time()]) . "\n\n";
            flush();
        }
        sleep(2);
    }
    exit;
}

// GET /api/export/csv
if ($method === 'GET' && $path === '/api/export/csv') {
    $store = read_store();
    $rows = $store['incidents'];
    usort($rows, fn($a, $b) => strcmp($b['created_at'], $a['created_at']));
    $headers = ['id','created_at','barangay_name','reporter_name','reporter_phone','notes',
        'device_lat','device_lng','pinned_lat','pinned_lng','discrepancy_meters','spatial_status','lifecycle_status'];
    $csv = fopen('php://temp', 'r+');
    fputcsv($csv, $headers);
    foreach ($rows as $r) {
        $line = [];
        foreach ($headers as $h) $line[] = $r[$h] ?? '';
        fputcsv($csv, $line);
    }
    rewind($csv);
    $out = stream_get_contents($csv);
    fclose($csv);
    $stamp = gmdate('Y-m-d');
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="vas-firegis-incidents-' . $stamp . '.csv"');
    echo "\xEF\xBB\xBF" . $out;
    exit;
}

// GET /api/export/pdf
if ($method === 'GET' && $path === '/api/export/pdf') {
    $store = read_store();
    $rows = $store['incidents'];
    usort($rows, fn($a, $b) => strcmp($b['created_at'], $a['created_at']));
    $title = "VAS FireGIS Incident Log";
    $lines = [
        $title,
        "Municipality of Vincenzo A. Sagun, Zamboanga del Sur",
        "Exported: " . gmdate('Y-m-d\TH:i:s\Z'),
        "Records: " . count($rows),
        "",
    ];
    foreach ($rows as $i => $r) {
        $lines[] = ($i + 1) . ". " . ($r['id'] ?? '') . "  [" . ($r['lifecycle_status'] ?? '') . "]  " . ($r['spatial_status'] ?? '');
        $lines[] = "   " . ($r['created_at'] ?? '') . "  Brgy. " . ($r['barangay_name'] ?? '');
        $lines[] = "   Reporter: " . ($r['reporter_name'] ?? '') . "  " . ($r['reporter_phone'] ?? '');
        $lines[] = "   Device: " . (($r['device_lat'] ?? null) ?? 'n/a') . ", " . (($r['device_lng'] ?? null) ?? 'n/a');
        $lines[] = "   Pinned: " . ($r['pinned_lat'] ?? '') . ", " . ($r['pinned_lng'] ?? '') . "  d=" . (($r['discrepancy_meters'] ?? null) ?? 'n/a') . " m";
        $lines[] = "   Notes: " . (($r['notes'] ?? '') !== '' ? $r['notes'] : '-');
        $lines[] = "";
    }
    $body = str_replace(['(', ')'], ' ', implode("\n", $lines));
    $wrapped = array_slice(explode("\n", $body), 0, 60);
    $y = 800;
    $cmds = [];
    foreach ($wrapped as $line) {
        $safe = substr(str_replace("\\", "\\\\", $line), 0, 110);
        $cmds[] = "BT /F1 9 Tf 40 $y Td ($safe) Tj ET";
        $y -= 12;
    }
    $stream = implode("\n", $cmds) . "\n";
    $objects = [
        "1 0 obj << /Type /Catalog /Pages 2 0 R >> endobj",
        "2 0 obj << /Type /Pages /Kids [3 0 R] /Count 1 >> endobj",
        "3 0 obj << /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Contents 4 0 R /Resources << /Font << /F1 5 0 R >> >> >> endobj",
        "4 0 obj << /Length " . strlen($stream) . " >> stream\n" . $stream . "endstream endobj",
        "5 0 obj << /Type /Font /Subtype /Type1 /BaseFont /Helvetica >> endobj",
    ];
    $pdf = "%PDF-1.4\n";
    $offsets = [0];
    foreach ($objects as $obj) {
        $offsets[] = strlen($pdf);
        $pdf .= $obj . "\n";
    }
    $xrefPos = strlen($pdf);
    $pdf .= "xref\n0 " . (count($objects) + 1) . "\n0000000000 65535 f \n";
    for ($i = 1; $i < count($offsets); $i++) {
        $pdf .= str_pad((string)$offsets[$i], 10, '0', STR_PAD_LEFT) . " 00000 n \n";
    }
    $pdf .= "trailer << /Size " . (count($objects) + 1) . " /Root 1 0 R >>\nstartxref\n$xrefPos\n%%EOF";
    $stamp = gmdate('Y-m-d');
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="vas-firegis-incidents-' . $stamp . '.pdf"');
    echo $pdf;
    exit;
}

// Fallback: 404
http_response_code(404);
header('Content-Type: application/json; charset=utf-8');
echo json_encode(['error' => 'Not found', 'path' => $path]);
