<?php
// After the session: every visible slide of the run of show as a picture, saved together as one PDF.
// The pictures are taken in this browser: uploaded slides are used as they are, and each data page is
// opened in a hidden copy of the big screen (?preview=slide id) showing its final data.

declare(strict_types=1);

require __DIR__ . '/../src/model.php';
require __DIR__ . '/../src/layout.php';
set_exception_handler('page_error_handler');

require_admin();
migrate();
$event = event_by_id((int) ($_GET['event'] ?? 0));
if (!$event) {
    redirect('admin.php');
}
$pages = [];
foreach (event_slides((int) $event['id']) as $s) {
    if (!$s['visible']) {
        continue;
    }
    $label = $s['title'] . ' – ' . $s['label'];
    if ($s['kind'] === 'image') {
        $pages[] = ['label' => $label, 'image' => $s['image']];
    } elseif ($s['kind'] === 'video') {
        // The video's thumbnail stands in for it (a local copy; a title page if YouTube could not be reached).
        $pages[] = strpos((string) $s['thumb'], 'media/') === 0 ? ['label' => $label, 'image' => $s['thumb']] : ['label' => $label, 'slide' => $s['id']];
    } elseif ($s['module'] === 'm5' && $s['kind'] === 'results') {
        foreach (['Top problems', 'Where it breaks', 'Blind spots', 'Side by side'] as $t => $name) {
            $pages[] = ['label' => $label . ' – ' . $name, 'slide' => $s['id'], 'tab' => $t + 1];
        }
    } else {
        $pages[] = ['label' => $label, 'slide' => $s['id']];
    }
}
$file = preg_replace('/[^A-Za-z0-9_-]+/', '_', $event['name']) . '_slides_' . gmdate('Y-m-d') . '.pdf';

page_start('Export slides – ' . $event['name'], '', $event['name']);
?>
<p class="small"><a href="admin.php?event=<?= (int) $event['id'] ?>">← <?= h($event['name']) ?></a></p>
<h1>Export slides</h1>
<p class="lead">A PDF with a picture of every slide in the run of show (<?= count($pages) ?> pages), including the data pages with their final results.
  Hidden slides are left out. Quick-fire results get one page per tab.</p>
<div class="box">
  <p style="margin-top:0"><button class="btn" id="go" type="button" <?= $pages ? '' : 'disabled' ?>>Create the PDF</button>
    <span class="muted small" id="status"><?= $pages ? 'Keep this tab open while it runs (about 3 seconds per data page).' : 'The run of show is empty.' ?></span></p>
  <div class="bar" style="height:8px;background:var(--light)"><i id="prog" style="display:block;height:100%;width:0;background:var(--lime)"></i></div>
  <div class="export-stage"><iframe id="frame" title="Slide being captured" tabindex="-1"></iframe></div>
  <p class="muted small" id="done" hidden></p>
</div>
<p class="muted small">All the answers themselves (every text, every number) are in each module's CSV export.</p>
<style>
  .export-stage { width: 480px; max-width: 100%; aspect-ratio: 16 / 9; overflow: hidden; position: relative; margin-top: 16px; background: #010203; }
  .export-stage iframe { position: absolute; left: 0; top: 0; width: 1920px; height: 1080px; border: 0; transform-origin: 0 0; transform: scale(.25); pointer-events: none; }
</style>
<?php
page_end('<script>window.EXPORT = ' . json_encode(['code' => $event['code'], 'pages' => $pages, 'file' => $file], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . ';</script>'
    . '<script src="assets/vendor/jspdf.umd.min.js?v=1"></script><script src="assets/export.js?v=1"></script>');
