<?php
// Slide upload (admin). The browser renders each PDF page (or resizes each image) and sends pages one by one:
//   POST ?do=start   JSON {event_id, name, replace_deck_id?}           -> {deck_id, batch}
//   POST ?do=page    multipart deck_id, batch, page_no, image, thumb    -> {ok}
//   POST ?do=finish  JSON {deck_id, batch, after_slide_id?}             -> {pages}

declare(strict_types=1);

require __DIR__ . '/../src/model.php';
set_exception_handler('page_error_handler');

const MAX_PAGE_BYTES = 8 * 1024 * 1024;
const MAX_PAGES = 300;

try {
    require_admin(true);
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || !hash_equals(csrf_token(), (string) ($_SERVER['HTTP_X_CSRF'] ?? ''))) {
        json_out(['error' => 'bad_request'], 400);
    }
    session_write_close(); // uploads can take a while; don't block the host's other pages
    $do = (string) ($_GET['do'] ?? '');
    $in = $do === 'page' ? $_POST : (json_decode((string) file_get_contents('php://input'), true) ?: []);

    if ($do === 'start') {
        $event = event_by_id((int) ($in['event_id'] ?? 0));
        if (!$event) {
            json_out(['error' => 'Event not found'], 404);
        }
        $replaceId = (int) ($in['replace_deck_id'] ?? 0);
        if ($replaceId) {
            $deck = q('SELECT * FROM decks WHERE id = ? AND event_id = ?', [$replaceId, $event['id']])->fetch();
            if (!$deck) {
                json_out(['error' => 'Deck not found'], 404);
            }
            $deckId = (int) $deck['id'];
            $batch = 1 + (int) q('SELECT GREATEST(COALESCE(MAX(batch), 0), ?) FROM deck_pages WHERE deck_id = ?',
                [$deck['current_batch'], $deckId])->fetchColumn();
        } else {
            $name = str_limit((string) ($in['name'] ?? ''), 200);
            q('INSERT INTO decks (event_id, name, current_batch, created_at, updated_at) VALUES (?,?,0,UTC_TIMESTAMP(),UTC_TIMESTAMP())',
                [$event['id'], $name !== '' ? $name : 'Slides']);
            $deckId = (int) db()->lastInsertId();
            $batch = 1;
        }
        json_out(['deck_id' => $deckId, 'batch' => $batch]);
    }

    $deck = q('SELECT * FROM decks WHERE id = ?', [(int) ($in['deck_id'] ?? 0)])->fetch();
    if (!$deck) {
        json_out(['error' => 'Deck not found'], 404);
    }
    $batch = (int) ($in['batch'] ?? 0);

    if ($do === 'page') {
        $pageNo = (int) ($in['page_no'] ?? 0);
        if ($pageNo < 1 || $pageNo > MAX_PAGES) {
            json_out(['error' => 'Bad page number'], 400);
        }
        $files = [];
        foreach (['image', 'thumb'] as $f) {
            $up = $_FILES[$f] ?? null;
            if (!$up || $up['error'] !== UPLOAD_ERR_OK || $up['size'] > MAX_PAGE_BYTES) {
                json_out(['error' => 'Upload of page ' . $pageNo . ' failed (code ' . ($up['error'] ?? 'missing') . ')'], 400);
            }
            $info = @getimagesize($up['tmp_name']);
            if (!$info || !in_array($info['mime'], ['image/jpeg', 'image/png'], true)) {
                json_out(['error' => 'Page ' . $pageNo . ' is not a JPEG or PNG image'], 400);
            }
            $files[$f] = ['data' => file_get_contents($up['tmp_name']), 'mime' => $info['mime'], 'w' => $info[0], 'h' => $info[1]];
        }
        $st = db()->prepare('INSERT INTO deck_pages (deck_id, batch, page_no, pkey, mime, width, height, data, thumb, created_at)
                             VALUES (?,?,?,?,?,?,?,?,?,UTC_TIMESTAMP())
                             ON DUPLICATE KEY UPDATE mime = VALUES(mime), width = VALUES(width), height = VALUES(height),
                                 data = VALUES(data), thumb = VALUES(thumb)');
        $st->bindValue(1, (int) $deck['id'], PDO::PARAM_INT);
        $st->bindValue(2, $batch, PDO::PARAM_INT);
        $st->bindValue(3, $pageNo, PDO::PARAM_INT);
        $st->bindValue(4, bin2hex(random_bytes(8)));
        $st->bindValue(5, $files['image']['mime']);
        $st->bindValue(6, $files['image']['w'], PDO::PARAM_INT);
        $st->bindValue(7, $files['image']['h'], PDO::PARAM_INT);
        $st->bindValue(8, $files['image']['data'], PDO::PARAM_LOB);
        $st->bindValue(9, $files['thumb']['data'], PDO::PARAM_LOB);
        $st->execute();
        // Save the files to disk too, so showing this slide never reads it back from the database.
        $pg = q('SELECT id, pkey FROM deck_pages WHERE deck_id = ? AND batch = ? AND page_no = ?', [(int) $deck['id'], $batch, $pageNo])->fetch();
        $pid = (int) ($pg['id'] ?? 0);
        $pkey = (string) ($pg['pkey'] ?? '');
        if ($pid && $pkey !== '') {
            media_write($pid, $pkey, false, $files['image']['mime'], $files['image']['data']);
            media_write($pid, $pkey, true, 'image/jpeg', $files['thumb']['data']);
        }
        json_out(['ok' => true]);
    }

    if ($do === 'finish') {
        $after = isset($in['after_slide_id']) && $in['after_slide_id'] !== '' && $in['after_slide_id'] !== null
            ? (int) $in['after_slide_id'] : null;
        $n = deck_finish($deck, $batch, $after);
        json_out(['pages' => $n]);
    }

    json_out(['error' => 'unknown_action'], 400);
} catch (Throwable $e) {
    error_log('[imasons upload] ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    $msg = $e instanceof PDOException && stripos($e->getMessage(), 'packet') !== false
        ? 'A page is too large for the database. Try again; pages are compressed further on retry.'
        : 'Server error: ' . $e->getMessage();
    json_out(['error' => $msg], 500);
}
