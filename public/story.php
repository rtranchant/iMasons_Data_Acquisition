<?php
// Impact story: a press-release style write-up of the session, written by Claude from everything collected.

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
$eid = (int) $event['id'];
$back = 'story.php?event=' . $eid;
$hasKey = (string) (app_config()['anthropic_api_key'] ?? '') !== '';
$do = (string) ($_POST['do'] ?? $_GET['do'] ?? '');

if ($do === 'status') {
    $r = story_row($eid) ?? ['status' => 'none', 'error' => '', 'updated_at' => null];
    json_out(['status' => $r['status'], 'error' => (string) $r['error'], 'updated_at' => $r['updated_at']]);
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    check_csrf($_POST['csrf'] ?? null);
    $notes = str_multiline((string) ($_POST['notes'] ?? ''), 4000);
    if ($do === 'save_story') {
        story_save($eid, ['story' => str_multiline((string) ($_POST['story'] ?? ''), 60000), 'status' => 'ready', 'error' => '']);
        flash('Story saved.');
        redirect($back);
    }
    if ($do === 'notes') {
        story_save($eid, ['notes' => $notes]);
        flash('Notes saved.');
        redirect($back);
    }
    if ($do === 'generate') {
        // Streamed: a dot is sent for each piece of text, so the web server never sees an idle request.
        // If the browser gives up, the story still finishes and is saved; the page checks the status.
        story_save($eid, ['notes' => $notes, 'status' => 'writing', 'error' => '']);
        ignore_user_abort(true);
        set_time_limit(330);
        session_write_close();
        while (ob_get_level()) {
            ob_end_flush();
        }
        header('Content-Type: text/plain; charset=utf-8');
        header('Cache-Control: no-store');
        header('X-Accel-Buffering: no');
        echo str_repeat(' ', 1024), "\n";
        flush();
        $last = microtime(true);
        [$story, $err] = story_generate($event, $notes, function () use (&$last) {
            if (microtime(true) - $last > 1) {
                echo '.';
                flush();
                $last = microtime(true);
            }
        });
        $model = (string) (app_config()['anthropic_model'] ?? STORY_DEFAULT_MODEL);
        if ($err === '' && $story !== '') {
            story_save($eid, ['story' => $story, 'status' => 'ready', 'error' => '', 'model' => $model]);
        } else {
            $prev = story_row($eid);
            story_save($eid, ['status' => ($prev && $prev['story'] !== '') ? 'ready' : 'none', 'error' => $err ?: 'The AI service returned no text.']);
        }
        echo "\n", json_encode(['ok' => $err === '' && $story !== '', 'error' => $err]);
        exit;
    }
    redirect($back);
}

$row = story_row($eid) ?? ['notes' => '', 'story' => '', 'status' => 'none', 'error' => '', 'model' => '', 'updated_at' => null];
// A story stuck in "writing" for over 6 minutes did not finish (e.g. the server was restarted).
$writing = $row['status'] === 'writing' && $row['updated_at'] && strtotime($row['updated_at'] . ' UTC') > time() - 360;
$msg = flash();
page_start('Impact story – ' . $event['name'], '', $event['name']);
if ($msg) echo '<div class="flash">' . h($msg) . '</div>';
?>
<p class="small"><a href="admin.php?event=<?= $eid ?>">← <?= h($event['name']) ?></a></p>
<h1>Impact story</h1>
<p class="lead">A press-release style story of the session: what it was about, what was discussed, and what the room said, with every number traced back to the activity it came from.</p>

<form method="post" class="box" id="story-form">
  <?= csrf_field() ?><input type="hidden" name="do" value="notes">
  <label for="notes" style="margin-top:0">Notes for the writer (optional)</label>
  <textarea id="notes" name="notes" style="min-height:110px" placeholder="Where and when it took place, who spoke, partners, the purpose of the session, what to emphasize, what happens next…"><?= h((string) $row['notes']) ?></textarea>
  <p class="muted small">The story uses only these notes, the uploaded slides and the collected results. No attendee names or emails are sent.</p>
  <div class="actions">
    <?php if ($hasKey): ?>
      <button class="btn" type="button" id="write" <?= $writing ? 'disabled' : '' ?>><?= $row['story'] ? 'Write it again' : 'Write the impact story' ?></button>
    <?php endif; ?>
    <button class="btn light" type="submit">Save notes</button>
    <span class="muted small" id="wstatus"><?= $writing ? 'Writing… (this takes about a minute)' : '' ?></span>
  </div>
  <?php if ($row['error']): ?><div class="error" style="margin-top:12px"><?= h('Last attempt failed: ' . $row['error']) ?></div><?php endif; ?>
</form>

<?php if (!$hasKey): ?>
  <div class="box">
    <label style="margin-top:0">Write it with Claude</label>
    <p class="small">To have the app write the story itself, add an Anthropic API key to <b>imasons_config.php</b> on the server: <code>'anthropic_api_key' => 'sk-ant-…',</code></p>
    <p class="small">Until then: copy the material below, paste it into a new chat with Claude, and attach the slides PDF from <a href="export.php?event=<?= $eid ?>">Export slides</a> so Claude can read the slides too.</p>
    <textarea id="material" readonly style="min-height:220px;font-size:13px"><?= h(story_system_prompt() . "\n\n---\n\n" . story_user_text($event, (string) $row['notes'])) ?></textarea>
    <p><button class="btn light" type="button" id="copy-material">Copy the material</button></p>
  </div>
<?php endif; ?>

<?php if ($row['story']): ?>
  <div class="section">The story<?= $row['updated_at'] ? ' <span class="muted small">(' . h(substr((string) $row['updated_at'], 0, 16)) . ' UTC)</span>' : '' ?></div>
  <article class="box story" id="story-view"><?= story_html((string) $row['story']) ?></article>
  <div class="actions">
    <button class="btn light" type="button" id="copy-story">Copy text</button>
    <a class="btn light" id="dl-story" download="<?= h(preg_replace('/[^A-Za-z0-9_-]+/', '_', $event['name'])) ?>_impact_story.md" href="data:text/markdown;charset=utf-8,<?= rawurlencode((string) $row['story']) ?>">Download (.md)</a>
    <button class="btn light" type="button" id="edit-story">Edit</button>
  </div>
  <form method="post" id="story-edit" hidden style="margin-top:12px">
    <?= csrf_field() ?><input type="hidden" name="do" value="save_story">
    <textarea name="story" id="story-text" style="min-height:420px"><?= h((string) $row['story']) ?></textarea>
    <p class="muted small">Markdown: # headline, ## section, **bold**, "- " for a list.</p>
    <p><button class="btn" type="submit">Save story</button></p>
  </form>
  <style>.story h2 { font-size: 28px; line-height: 1.2; margin: 0 0 12px; } .story h3 { font-size: 20px; margin: 24px 0 8px; } .story p, .story li { font-size: 16px; line-height: 1.6; }</style>
<?php endif; ?>

<script>
(function () {
  var write = document.getElementById('write'), st = document.getElementById('wstatus'), form = document.getElementById('story-form');
  function copy(text, btn) { navigator.clipboard.writeText(text).then(function () { var t = btn.textContent; btn.textContent = 'Copied'; setTimeout(function () { btn.textContent = t; }, 1500); }); }
  var cm = document.getElementById('copy-material'); if (cm) cm.addEventListener('click', function () { copy(document.getElementById('material').value, cm); });
  var cs = document.getElementById('copy-story'); if (cs) cs.addEventListener('click', function () { copy(document.getElementById('story-text').value, cs); });
  var ed = document.getElementById('edit-story'); if (ed) ed.addEventListener('click', function () { document.getElementById('story-edit').hidden = false; document.getElementById('story-text').focus(); });

  // While a story is being written (here or in another tab), check every few seconds and reload when it is done.
  function watch() {
    st.textContent = 'Writing… (this takes about a minute)';
    var tries = 0;
    (function check() {
      fetch('story.php?event=<?= $eid ?>&do=status', { credentials: 'same-origin', cache: 'no-store' }).then(function (r) { return r.json(); }).then(function (j) {
        if (j.status !== 'writing') location.reload();
        else if (++tries < 120) setTimeout(check, 4000);
      }, function () { setTimeout(check, 6000); });
    })();
  }
  if (write) write.addEventListener('click', function () {
    write.disabled = true;
    st.textContent = 'Writing… (this takes about a minute)';
    var fd = new FormData(form); fd.set('do', 'generate');
    var dots = 0;
    fetch('story.php?event=<?= $eid ?>', { method: 'POST', body: fd, credentials: 'same-origin' }).then(function (r) {
      if (!r.body || !r.body.getReader) return r.text();
      var reader = r.body.getReader(), dec = new TextDecoder(), all = '';
      return (function pump() {
        return reader.read().then(function (x) {
          if (x.done) return all;
          var t = dec.decode(x.value, { stream: true }); all += t;
          dots += (t.match(/\./g) || []).length;
          st.textContent = 'Writing… ' + '•'.repeat(Math.min(dots, 40));
          return pump();
        });
      })();
    }).then(function (all) {
      var last = all.trim().split('\n').pop();
      try { var j = JSON.parse(last); } catch (e) { return watch(); }
      location.reload();
    }, watch);
  });
  <?php if ($writing): ?>watch();<?php endif; ?>
})();
</script>
<?php
page_end();
