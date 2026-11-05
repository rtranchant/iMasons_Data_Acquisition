<?php
// Attendee page: one link per event. Follows whatever is live in the run of show.

declare(strict_types=1);

require __DIR__ . '/../src/model.php';
set_exception_handler('page_error_handler');
require __DIR__ . '/../src/layout.php';

migrate();
$event = event_by_code((string) ($_GET['c'] ?? ''));

page_start($event ? $event['name'] : 'iMasons', 'phone', $event ? $event['name'] : '');
if (!$event): ?>
  <h1>Event not found</h1>
  <p class="lead">Check the link or scan the QR code on the screen again.</p>
<?php
    page_end();
    exit;
endif;
?>
<div id="app" aria-live="polite">
  <div class="waiting"><div class="pulse"></div><p class="muted">Connecting…</p></div>
</div>
<?php
page_end('<script>window.EVENT_CODE = ' . json_encode($event['code']) . ';</script><script src="assets/phone.js?v=8"></script>');
