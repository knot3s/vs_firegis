<?php
// GET /api/export/csv  — download incidents as CSV
// GET /api/export/pdf  — download incidents as PDF
require_once __DIR__ . '/../lib.php';

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$incidents = load_incidents();
usort($incidents, fn($a, $b) => strcmp($b['created_at'], $a['created_at']));
$stamp = gmdate('Y-m-d');

// --- CSV ---
if (str_ends_with($path, '/csv')) {
    $headers = ['id','created_at','barangay_name','category','reporter_name','reporter_phone','notes',
        'device_lat','device_lng','pinned_lat','pinned_lng','discrepancy_meters','spatial_status','lifecycle_status'];
    $csv = fopen('php://temp', 'r+');
    fputcsv($csv, $headers);
    foreach ($incidents as $r) {
        $line = [];
        foreach ($headers as $h) $line[] = $r[$h] ?? '';
        fputcsv($csv, $line);
    }
    rewind($csv);
    $out = stream_get_contents($csv);
    fclose($csv);
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="vas-firegis-incidents-' . $stamp . '.csv"');
    echo "\xEF\xBB\xBF" . $out;
    exit;
}

// --- PDF (simple text log) ---
$lines = [
    "VAS FireGIS Incident Log",
    "Municipality of Vincenzo A. Sagun, Zamboanga del Sur",
    "Exported: " . gmdate('Y-m-d\TH:i:s\Z'),
    "Records: " . count($incidents),
    "",
];
foreach ($incidents as $i => $r) {
    $lines[] = ($i + 1) . ". " . ($r['id'] ?? '') . "  [" . ($r['lifecycle_status'] ?? '') . "]  " . ($r['spatial_status'] ?? '');
    $lines[] = "   " . ($r['created_at'] ?? '') . "  Brgy. " . ($r['barangay_name'] ?? '') . "  Type: " . ($r['category'] ?? 'n/a');
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
foreach ($objects as $obj) { $offsets[] = strlen($pdf); $pdf .= $obj . "\n"; }
$xrefPos = strlen($pdf);
$pdf .= "xref\n0 " . (count($objects) + 1) . "\n0000000000 65535 f \n";
for ($i = 1; $i < count($offsets); $i++) {
    $pdf .= str_pad((string)$offsets[$i], 10, '0', STR_PAD_LEFT) . " 00000 n \n";
}
$pdf .= "trailer << /Size " . (count($objects) + 1) . " /Root 1 0 R >>\nstartxref\n$xrefPos\n%%EOF";

header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="vas-firegis-incidents-' . $stamp . '.pdf"');
echo $pdf;
