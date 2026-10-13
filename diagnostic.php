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

// 8. Test direct API file access (bypasses .htaccess rewrite)
$directMetaUrl = 'api/meta.php';
$directMetaOk = false;
$directMetaValue = '';
$ctx = stream_context_create(['http' => ['timeout' => 10, 'ignore_errors' => true]]);
$directRaw = @file_get_contents($directMetaUrl, false, $ctx);
if ($directRaw !== false) {
    $directMetaOk = strpos($directRaw, '{') !== false;
    $directMetaValue = $directMetaOk ? 'returns JSON' : 'returns: ' . substr(trim(strip_tags($directRaw)), 0, 100);
} else {
    $directMetaValue = 'file_get_contents failed';
}
$checks[] = [
    'name' => 'api/meta.php (direct file access)',
    'value' => $directMetaOk ? '✅ works' : '❌ ' . $directMetaValue,
    'pass' => $directMetaOk,
    'fix' => $directMetaOk ? '' : 'api/meta.php is not returning JSON — check the file is uploaded correctly.',
];

// 9. Test the .htaccess rewrite (GET /api/meta)
$baseUrl = (!empty($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . rtrim(dirname($_SERVER['SCRIPT_NAME']), '/') . '/';
$rewriteUrl = $baseUrl . 'api/meta';
$rewriteRaw = @file_get_contents($rewriteUrl, false, $ctx);
$rewriteOk = $rewriteRaw !== false && strpos($rewriteRaw, '{') !== false;
$rewriteValue = $rewriteOk ? 'returns JSON' : ($rewriteRaw ? substr(trim(strip_tags($rewriteRaw)), 0, 150) : 'request failed');
$checks[] = [
    'name' => 'GET /api/meta (via .htaccess rewrite)',
    'value' => $rewriteOk ? '✅ rewrite works' : '❌ ' . $rewriteValue,
    'pass' => $rewriteOk,
    'fix' => $rewriteOk ? '' : '.htaccess rewrite is not working. Check that mod_rewrite is enabled and .htaccess is in the right folder.',
];

// 10. Test POST to /api/auth/login (via rewrite)
$postUrl = $baseUrl . 'api/auth/login';
$postBody = json_encode(['role' => 'fire_personnel', 'username' => 'firestaff', 'password' => 'vas-bfp-2026']);
$postCtx = stream_context_create(['http' => [
    'method' => 'POST',
    'header' => "Content-Type: application/json\r\n",
    'content' => $postBody,
    'timeout' => 10,
    'ignore_errors' => true,
]]);
$postRaw = @file_get_contents($postUrl, false, $postCtx);
$postOk = $postRaw !== false && strpos($postRaw, 'fire_personnel') !== false;
$postValue = $postOk ? '✅ login works' : ($postRaw ? substr(trim(strip_tags($postRaw)), 0, 200) : 'request failed');
$checks[] = [
    'name' => 'POST /api/auth/login (via rewrite)',
    'value' => $postOk ? '✅ login returns fire_personnel' : '❌ ' . $postValue,
    'pass' => $postOk,
    'fix' => $postOk ? '' : 'If GET works but POST returns 503, Hostinger WAF/bot protection is blocking POST to /api/*. Try disabling "Bot Protection" in Hostinger → Security, or contact support.',
];

// 11. Test POST directly to api/auth.php (bypass rewrite)
$postDirectUrl = $baseUrl . 'api/auth.php';
$postDirectRaw = @file_get_contents($postDirectUrl, false, $postCtx);
$postDirectOk = $postDirectRaw !== false && strpos($postDirectRaw, 'fire_personnel') !== false;
$postDirectValue = $postDirectOk ? '✅ login works' : ($postDirectRaw ? substr(trim(strip_tags($postDirectRaw)), 0, 200) : 'request failed');
$checks[] = [
    'name' => 'POST api/auth.php (direct, no rewrite)',
    'value' => $postDirectOk ? '✅ direct POST works' : '❌ ' . $postDirectValue,
    'pass' => $postDirectOk,
    'fix' => $postDirectOk ? '' : 'POST is being blocked entirely — check Hostinger WAF/security settings.',
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
  .value { color: #94a3b8; font-size: 0.9rem; margin-top: 0.25rem; word-break: break-word; }
  .fix { color: #fbbf24; font-size: 0.85rem; margin-top: 0.5rem; }
  .pass .value { color: #86efac; }
  .info { margin-top: 2rem; padding: 1rem; background: #1a1a2e; border-radius: 8px; border: 1px solid #4c1d95; }
</style>
</head>
<body>
<h1>🔥 VAS FireGIS — Hostinger Diagnostic</h1>
<p>PHP <?php echo PHP_VERSION; ?> on <?php echo php_uname('s'); ?></p>
<p>Base URL: <code><?php echo htmlspecialchars($baseUrl); ?></code></p>
<?php foreach ($checks as $c): ?>
  <div class="check <?php echo $c['pass'] ? 'pass' : 'fail'; ?>">
    <div class="name"><?php echo $c['pass'] ? '✅' : '❌'; ?> <?php echo htmlspecialchars($c['name']); ?></div>
    <div class="value"><?php echo htmlspecialchars($c['value']); ?></div>
    <?php if (!$c['pass'] && $c['fix']): ?>
      <div class="fix">👉 <?php echo htmlspecialchars($c['fix']); ?></div>
    <?php endif; ?>
  </div>
<?php endforeach; ?>

<div class="info">
  <h3 style="margin:0 0 0.5rem;color:#a78bfa">How to interpret the last 3 checks:</h3>
  <ul style="margin:0;padding-left:1.2rem;line-height:1.6">
    <li><b>GET /api/meta fails</b> → <code>.htaccess</code> rewrite is broken (mod_rewrite off or wrong folder)</li>
    <li><b>GET works, POST fails</b> → Hostinger WAF/Bot Protection is blocking POST to <code>/api/*</code></li>
    <li><b>Direct POST to auth.php works, /api/auth/login fails</b> → rewrite + POST combo is blocked</li>
    <li><b>All fail with 503</b> → Hostinger resource limits (CPU/RAM) — try again later or upgrade plan</li>
  </ul>
</div>

<p style="margin-top:2rem; color:#666; font-size:0.8rem;">
  ⚠️ Delete this file (diagnostic.php) after fixing the issues — it exposes server info.
</p>
</body>
</html>
