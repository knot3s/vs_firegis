<?php
/**
 * VAS FireGIS — Hostinger diagnostic tool.
 * Visit this file directly in your browser: yourdomain.com/vs_firegis/diagnostic.php
 * Do NOT leave this file on a production server — delete it after diagnosing.
 */

$checks = [];

// 1. PHP version
$phpOk = version_compare(PHP_VERSION, '7.4.0', '>=');
$checks[] = [
    'name' => 'PHP version',
    'value' => PHP_VERSION,
    'pass' => $phpOk,
    'fix' => $phpOk ? '' : 'In Hostinger → PHP Configuration, select PHP 7.4 or higher (8.x recommended).',
];

// 2. JSON extension
$checks[] = [
    'name' => 'JSON extension',
    'value' => extension_loaded('json') ? 'loaded' : 'MISSING',
    'pass' => extension_loaded('json'),
    'fix' => 'Enable the json extension in Hostinger PHP Configuration.',
];

// 3. mod_rewrite / .htaccess (best-effort detection)
$htaccess = __DIR__ . '/.htaccess';
$checks[] = [
    'name' => '.htaccess file present',
    'value' => is_file($htaccess) ? 'yes' : 'NO',
    'pass' => is_file($htaccess),
    'fix' => 'Upload the .htaccess file from the repo to the vs_firegis folder.',
];

// 4. data directory writable
$dataDir = __DIR__ . '/data';
$dataWritable = is_dir($dataDir) && is_writable($dataDir);
if (!is_dir($dataDir)) {
    @mkdir($dataDir, 0755, true);
    $dataWritable = is_dir($dataDir) && is_writable($dataDir);
}
$checks[] = [
    'name' => 'data/ directory writable',
    'value' => $dataWritable ? 'yes' : 'NO',
    'pass' => $dataWritable,
    'fix' => 'In Hostinger File Manager, right-click the data/ folder → File Permissions → set to 755.',
];

// 5. Write test
$testFile = $dataDir . '/_write_test.tmp';
$canWrite = @file_put_contents($testFile, 'ok') !== false;
if ($canWrite) @unlink($testFile);
$checks[] = [
    'name' => 'Can write to data/',
    'value' => $canWrite ? 'yes' : 'NO',
    'pass' => $canWrite,
    'fix' => 'Set data/ folder permissions to 755 (or 775 if needed).',
];

// 6. Test that lib.php loads without errors
$libOk = false;
$libError = '';
try {
    require_once __DIR__ . '/lib.php';
    $libOk = true;
} catch (Throwable $e) {
    $libError = $e->getMessage();
}
$checks[] = [
    'name' => 'lib.php loads without errors',
    'value' => $libOk ? 'yes' : 'ERROR: ' . $libError,
    'pass' => $libOk,
    'fix' => $libOk ? '' : 'Check the error message above — usually a PHP version issue.',
];

// 7. Test that auth endpoint logic works
$authOk = false;
$authError = '';
try {
    // Simulate the auth check
    $user = 'firestaff' === STAFF_USERNAME;
    $pass = 'vas-bfp-2026' === STAFF_PASSWORD;
    $authOk = $user && $pass;
} catch (Throwable $e) {
    $authError = $e->getMessage();
}
$checks[] = [
    'name' => 'Credentials check works',
    'value' => $authOk ? 'firestaff / vas-bfp-2026' : 'ERROR: ' . $authError,
    'pass' => $authOk,
    'fix' => $authOk ? '' : 'Credentials constants are missing in lib.php.',
];

// Output as readable HTML
header('Content-Type: text/html; charset=utf-8');
?><!DOCTYPE html>
<html lang="en">
<head><meta charset="utf-8"><title>VAS FireGIS Diagnostic</title>
<style>
  body { font-family: sans-serif; max-width: 720px; margin: 2rem auto; padding: 0 1rem; background: #111; color: #eee; }
  h1 { color: #f59e0b; }
  .check { padding: 1rem; margin: 0.5rem 0; border-radius: 8px; border: 1px solid #333; }
  .pass { border-color: #22c55e; }
  .fail { border-color: #ef4444; background: #1a0a0a; }
  .name { font-weight: bold; }
  .value { color: #94a3b8; font-size: 0.9rem; margin-top: 0.25rem; }
  .fix { color: #fbbf24; font-size: 0.85rem; margin-top: 0.5rem; }
  .pass .value { color: #86efac; }
</style>
</head>
<body>
<h1>🔥 VAS FireGIS — Hostinger Diagnostic</h1>
<p>PHP <?php echo PHP_VERSION; ?> on <?php echo php_uname('s'); ?></p>
<?php foreach ($checks as $c): ?>
  <div class="check <?php echo $c['pass'] ? 'pass' : 'fail'; ?>">
    <div class="name"><?php echo $c['pass'] ? '✅' : '❌'; ?> <?php echo htmlspecialchars($c['name']); ?></div>
    <div class="value"><?php echo htmlspecialchars($c['value']); ?></div>
    <?php if (!$c['pass'] && $c['fix']): ?>
      <div class="fix">👉 <?php echo htmlspecialchars($c['fix']); ?></div>
    <?php endif; ?>
  </div>
<?php endforeach; ?>
<p style="margin-top:2rem; color:#666; font-size:0.8rem;">
  ⚠️ Delete this file (diagnostic.php) after fixing the issues — it exposes server info.
</p>
</body>
</html>
