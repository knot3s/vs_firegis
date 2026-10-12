<?php
// GET /api/barangays  — barangay polygons for the map
require_once __DIR__ . '/../lib.php';
json_response(barangays_geojson());
