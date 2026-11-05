<?php
// Serves an uploaded slide page (or its thumbnail). URLs carry a random key and never change
// content (a revised deck gets new page ids), so they are cached for a long time.

declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';
set_exception_handler('page_error_handler');

$id = (int) ($_GET['p'] ?? 0);
$key = (string) ($_GET['k'] ?? '');
$thumb = !empty($_GET['t']);

if (($_SERVER['HTTP_IF_NONE_MATCH'] ?? '') === '"' . $id . ($thumb ? 't' : '') . '-' . $key . '"') {
    http_response_code(304);
    exit;
}

$row = q('SELECT mime, pkey, ' . ($thumb ? 'thumb' : 'data') . ' AS bytes FROM deck_pages WHERE id = ?', [$id])->fetch();
if (!$row || !hash_equals($row['pkey'], $key)) {
    http_response_code(404);
    exit('Not found');
}
header('Content-Type: ' . ($thumb ? 'image/jpeg' : $row['mime']));
header('Content-Length: ' . strlen($row['bytes']));
header('Cache-Control: public, max-age=31536000, immutable');
header('ETag: "' . $id . ($thumb ? 't' : '') . '-' . $key . '"');
header('X-Content-Type-Options: nosniff');
echo $row['bytes'];
