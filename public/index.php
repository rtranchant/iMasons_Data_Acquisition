<?php
// Deployment test page. Shows which commit is live so you can confirm
// that a push to GitHub reached the server.

$version = ['commit' => 'unknown', 'deployed_at' => 'unknown'];
$versionFile = __DIR__ . '/version.json';
if (is_readable($versionFile)) {
    $data = json_decode((string) file_get_contents($versionFile), true);
    if (is_array($data)) {
        $version = array_merge($version, $data);
    }
}

function h(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>iMasons Data Acquisition – Deploy Test</title>
  <style>
    body { font-family: system-ui, sans-serif; background: #f4f5f7; color: #1a1a2e;
           display: flex; min-height: 100vh; align-items: center; justify-content: center; margin: 0; padding: 16px; }
    .card { background: #fff; border-radius: 12px; padding: 32px; max-width: 480px; width: 100%;
            box-shadow: 0 2px 12px rgba(0,0,0,.08); }
    h1 { font-size: 1.4rem; margin: 0 0 4px; }
    .ok { color: #1a7f37; font-weight: 600; margin: 0 0 24px; }
    dl { display: grid; grid-template-columns: auto 1fr; gap: 8px 16px; margin: 0; }
    dt { color: #666; }
    dd { margin: 0; font-family: ui-monospace, monospace; word-break: break-all; }
  </style>
</head>
<body>
  <div class="card">
    <h1>iMasons Data Acquisition</h1>
    <p class="ok">✓ Deployed automatically from GitHub</p>
    <dl>
      <dt>Commit</dt>      <dd><?= h(substr((string) $version['commit'], 0, 7)) ?></dd>
      <dt>Deployed at</dt> <dd><?= h((string) $version['deployed_at']) ?></dd>
      <dt>PHP version</dt> <dd><?= h(PHP_VERSION) ?></dd>
      <dt>Server time</dt> <dd><?= h(date('Y-m-d H:i:s T')) ?></dd>
    </dl>
  </div>
</body>
</html>
