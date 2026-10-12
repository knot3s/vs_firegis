<?php
// GET /api/stream  — Server-Sent Events for live dashboard updates
require_once __DIR__ . '/../lib.php';

header('Content-Type: text/event-stream; charset=utf-8');
header('Cache-Control: no-cache, no-transform');
header('Connection: keep-alive');
header('X-Accel-Buffering: no');
@ini_set('zlib.output_compression', '0');
while (ob_get_level()) ob_end_flush();

// Send hello frame
echo "event: hello\ndata: " . json_encode(['ok' => true, 't' => time()]) . "\n\n";
flush();

// Watch the incidents file for changes and notify the dashboard to reload.
global $incidentsFile;
$lastMtime = is_file($incidentsFile) ? filemtime($incidentsFile) : 0;
$end = time() + 55; // keep alive ~55s, browser auto-reconnects

while (time() < $end) {
    if (connection_aborted()) break;
    clearstatcache(true, $incidentsFile);
    $mtime = is_file($incidentsFile) ? filemtime($incidentsFile) : 0;
    if ($mtime > $lastMtime) {
        $lastMtime = $mtime;
        echo "event: status\ndata: " . json_encode(['ok' => true, 't' => time()]) . "\n\n";
        flush();
    }
    sleep(2);
}
