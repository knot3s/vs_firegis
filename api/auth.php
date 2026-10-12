<?php
// POST /api/auth/login  — fire personnel login
require_once __DIR__ . '/../lib.php';

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

$user = ($body['username'] ?? '') === STAFF_USERNAME;
$pass = ($body['password'] ?? '') === STAFF_PASSWORD;

if (!$user || !$pass) {
    json_response(['ok' => false, 'error' => 'Invalid fire personnel credentials.'], 401);
}

json_response([
    'ok'       => true,
    'role'     => 'fire_personnel',
    'token'    => 'personnel-staff',
    'name'     => STAFF_NAME,
    'username' => STAFF_USERNAME,
]);
