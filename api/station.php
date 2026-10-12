<?php
// GET /api/station  — fire station coordinates for the dashboard map
require_once __DIR__ . '/../lib.php';
json_response(STATION);
