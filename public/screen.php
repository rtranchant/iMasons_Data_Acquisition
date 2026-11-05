<?php
// Big screen for the room. Public and read-only: it only shows what is meant to be projected.

declare(strict_types=1);

require __DIR__ . '/../src/model.php';
set_exception_handler('page_error_handler');

migrate();
$event = event_by_code((string) ($_GET['c'] ?? ''));
if (!$event) {
    http_response_code(404);
    exit('Event not found');
}
?><!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex">
  <title><?= h($event['name']) ?> – Screen</title>
  <link rel="preload" href="assets/fonts/lexend-400.woff2" as="font" type="font/woff2" crossorigin>
  <link rel="preload" href="assets/fonts/lexend-600.woff2" as="font" type="font/woff2" crossorigin>
  <link rel="stylesheet" href="assets/fonts/lexend.css?v=1">
  <link rel="stylesheet" href="assets/screen.css?v=25">
</head>
<body>
  <div id="screen" aria-live="polite"></div>
  <div class="offline" id="offline" hidden>Reconnecting…</div>
  <button id="fs" class="fs" type="button" title="Full screen">⛶ Full screen</button>
  <script src="assets/vendor/qrcode.js?v=1"></script>
  <?php if (isset($_GET['preview'])): ?><script src="assets/vendor/html-to-image.js?v=1"></script><?php endif; ?>
  <script>window.EVENT_CODE = <?= json_encode($event['code']) ?>; window.SCREEN_CHECK_MS = <?= (int) round(1000 * max(0.4, min(30, (float) (app_config()['screen_check_s'] ?? 5)))) ?>;<?php if (is_admin()): ?> window.CSRF = <?= json_encode(csrf_token()) ?>;<?php endif; ?></script>
  <script src="assets/screen.js?v=23"></script>
</body>
</html>
