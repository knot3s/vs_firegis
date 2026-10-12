<?php
// GET /api/meta  — app configuration for the frontend
require_once __DIR__ . '/../lib.php';

// GET /health is also routed here via ?health=1
if (isset($_GET['health'])) {
    json_response(['ok' => true, 'persistence' => 'json', 'uptime' => 0]);
}

json_response([
    'title'                 => 'EMERGENCY RESPONSE IN FIRE (GIS)',
    'municipality'          => 'Vincenzo A. Sagun, Zamboanga del Sur',
    'mapCenter'             => MAP_CENTER,
    'station'               => STATION,
    'verifyThresholdMeters' => VERIFY_THRESHOLD_M,
    'earthRadiusMeters'     => EARTH_RADIUS_M,
    'lifecycle'             => LIFECYCLE,
    'persistence'           => 'json',
]);
