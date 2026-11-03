<?php
// Remote control for the host's phone.

declare(strict_types=1);

require __DIR__ . '/../src/model.php';
require __DIR__ . '/../src/layout.php';

require_admin();
migrate();
$event = event_by_code((string) ($_GET['c'] ?? ''));
if (!$event) {
    redirect('admin.php');
}

page_start('Remote – ' . $event['name'], 'remote', 'Remote · ' . $event['name']);
?>
<div class="remote-nav">
  <button class="btn light" id="prev" type="button">← Previous</button>
  <button class="btn lime" id="next" type="button">Next →</button>
</div>
<div class="error" id="err" hidden></div>
<div id="app"><p class="muted">Loading…</p></div>
<p class="small muted" style="margin-top:28px">
  <a href="screen.php?c=<?= h($event['code']) ?>" target="_blank" rel="noopener">Open big screen</a> ·
  <a href="admin.php?event=<?= (int) $event['id'] ?>">Edit event</a>
</p>
<?php
page_end('<script>window.EVENT_CODE = ' . json_encode($event['code']) . '; window.CSRF = ' . json_encode(csrf_token()) . ';</script>'
    . '<script src="assets/remote.js?v=1"></script>');
