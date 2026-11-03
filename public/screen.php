<?php
// Big screen for the room. Public and read-only: it only shows what is meant to be projected.

declare(strict_types=1);

require __DIR__ . '/../src/model.php';

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
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Lexend:wght@300;400;500;600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="assets/screen.css?v=1">
</head>
<body>
  <div class="stripe"></div>
  <header class="top">
    <span class="brand">iMasons</span>
    <span class="event" id="event"><?= h($event['name']) ?></span>
    <span class="offline" id="offline" hidden>Reconnecting…</span>
  </header>
  <main id="stage"></main>
  <button id="fs" class="fs" type="button" title="Full screen">⛶ Full screen</button>
  <script src="assets/qrcode.js?v=1"></script>
  <script>window.EVENT_CODE = <?= json_encode($event['code']) ?>;</script>
  <script src="assets/screen.js?v=1"></script>
</body>
</html>
