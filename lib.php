<?php
/**
 * VAS FireGIS — shared configuration and helpers.
 *
 * This file is the "brain" of the system. It holds:
 *   - All static data (barangays, fire station, lifecycle stages)
 *   - The Haversine distance formula for spatial verification
 *   - Save/load functions for the incident list (data/incidents.json)
 *
 * Every file in api/ starts with:  require_once __DIR__ . '/../lib.php';
 */

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

const EARTH_RADIUS_M     = 6371000;
const VERIFY_THRESHOLD_M = 100; // pin must be within 100m of device GPS

const LIFECYCLE = ['Pending', 'Dispatched', 'Under Control', 'Fire Out', 'False Alarm'];

const EMERGENCY_CATEGORIES = [
    'Structural Fire',
    'Grass / Forest Fire',
    'Vehicular Fire',
    'Electrical Fire',
    'LPG / Gas Fire',
    'Rescue / Medical',
    'Fire Out / Post-Fire',
    'Other',
];

// Demo login for the command dashboard.
const STAFF_USERNAME = 'firestaff';
const STAFF_PASSWORD = 'vas-bfp-2026';
const STAFF_NAME     = 'VAS Fire Station Command';

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
$incidentsFile = $dataDir . '/incidents.json';

// ---------------------------------------------------------------------------
// HTTP helpers
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

// ---------------------------------------------------------------------------
// Incident storage (data/incidents.json)
// ---------------------------------------------------------------------------

function load_incidents() {
    global $incidentsFile;
    if (!is_file($incidentsFile)) return [];
    $raw = @file_get_contents($incidentsFile);
    if ($raw === false) return [];
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

function save_incidents($incidents) {
    global $dataDir, $incidentsFile;
    if (!is_dir($dataDir)) @mkdir($dataDir, 0755, true);
    $json = json_encode($incidents, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    return @file_put_contents($incidentsFile, $json) !== false;
}

function new_incident_id() {
    return 'INC-' . strtoupper(base_convert((string)time(), 10, 36)) . '-' . random_int(100, 999);
}

// ---------------------------------------------------------------------------
// Spatial verification (Haversine)
// ---------------------------------------------------------------------------

function haversine_meters($lat1, $lng1, $lat2, $lng2) {
    foreach ([$lat1, $lng1, $lat2, $lng2] as $n) {
        if (!is_numeric($n) || !is_finite((float)$n)) return null;
    }
    $phi1 = deg2rad((float)$lat1);
    $phi2 = deg2rad((float)$lat2);
    $dPhi = deg2rad((float)$lat2 - (float)$lat1);
    $dLam = deg2rad((float)$lng2 - (float)$lng1);
    $sinDPhi = sin($dPhi / 2);
    $sinDLam = sin($dLam / 2);
    $a = $sinDPhi * $sinDPhi + cos($phi1) * cos($phi2) * $sinDLam * $sinDLam;
    $c = 2 * atan2(sqrt($a), sqrt(1 - $a));
    return EARTH_RADIUS_M * $c;
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

// ---------------------------------------------------------------------------
// Barangay GeoJSON (schematic boxes for the prototype map)
// ---------------------------------------------------------------------------

function barangays_geojson() {
    $features = [];
    foreach (BARANGAYS as $i => $b) {
        $half = 0.7; // km
        $dLat = $half / 111.32;
        $dLng = $half / (111.32 * cos(deg2rad($b['lat'])));
        $ring = [
            [$b['lng'] - $dLng, $b['lat'] - $dLat],
            [$b['lng'] + $dLng, $b['lat'] - $dLat],
            [$b['lng'] + $dLng, $b['lat'] + $dLat],
            [$b['lng'] - $dLng, $b['lat'] + $dLat],
            [$b['lng'] - $dLng, $b['lat'] - $dLat],
        ];
        $features[] = [
            'type'       => 'Feature',
            'id'         => $i + 1,
            'properties' => [
                'id'              => $i + 1,
                'name'            => $b['name'],
                'is_poblacion'    => $b['isPoblacion'],
                'population_2020' => $b['population'],
            ],
            'geometry' => ['type' => 'Polygon', 'coordinates' => [$ring]],
        ];
    }
    return [
        'type'     => 'FeatureCollection',
        'name'     => 'VAS_Barangay_Zones_Schematic',
        'features' => $features,
    ];
}

// Allow CORS for local testing
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PATCH, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}
