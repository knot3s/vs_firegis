<?php
// POST /api/reports  — resident submits a fire incident
require_once __DIR__ . '/../lib.php';

$body = read_body();

$reporter_name  = trim((string)($body['reporter_name'] ?? ''));
$reporter_phone = trim((string)($body['reporter_phone'] ?? ''));
$barangay_name  = trim((string)($body['barangay_name'] ?? ''));
$category       = trim((string)($body['category'] ?? ''));
$notes          = trim((string)($body['notes'] ?? ''));
$pinned_lat     = isset($body['pinned_lat']) ? (float)$body['pinned_lat'] : NAN;
$pinned_lng     = isset($body['pinned_lng']) ? (float)$body['pinned_lng'] : NAN;

$rawDevLat = $body['device_lat'] ?? null;
$rawDevLng = $body['device_lng'] ?? null;
$device_lat = ($rawDevLat === null || $rawDevLat === '') ? null : (float)$rawDevLat;
$device_lng = ($rawDevLng === null || $rawDevLng === '') ? null : (float)$rawDevLng;

// Validation
if ($reporter_name === '' || $reporter_phone === '' || $barangay_name === '' || $category === '') {
    json_response(['error' => 'Name, phone, barangay, and emergency type are required.'], 400);
}
if (!in_array($category, EMERGENCY_CATEGORIES, true)) {
    json_response(['error' => 'Unknown emergency type.', 'allowed' => EMERGENCY_CATEGORIES], 400);
}
if (!is_finite($pinned_lat) || !is_finite($pinned_lng)) {
    json_response(['error' => 'Pinned coordinates are required.'], 400);
}
$known = false;
foreach (BARANGAYS as $b) { if ($b['name'] === $barangay_name) { $known = true; break; } }
if (!$known) {
    json_response(['error' => 'Unknown barangay.'], 400);
}

// Spatial verification
$spatial = classify_spatial($device_lat, $device_lng, $pinned_lat, $pinned_lng);
$now = gmdate('Y-m-d\TH:i:s\Z');

$incident = [
    'id'                 => new_incident_id(),
    'reporter_name'      => $reporter_name,
    'reporter_phone'     => $reporter_phone,
    'barangay_name'      => $barangay_name,
    'category'           => $category,
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

$incidents = load_incidents();
$incidents[] = $incident;
save_incidents($incidents);

json_response(['ok' => true, 'incident' => $incident], 201);
