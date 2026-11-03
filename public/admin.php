<?php
// Admin: events, module instances, module configuration, run of show, exports.

declare(strict_types=1);

require __DIR__ . '/../src/model.php';
require __DIR__ . '/../src/layout.php';

require_admin();
migrate();

// ---------------------------------------------------------------- actions (POST)

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    check_csrf($_POST['csrf'] ?? null);
    $do = (string) ($_POST['do'] ?? '');
    $eventId = (int) ($_POST['event_id'] ?? 0);
    $instanceId = (int) ($_POST['instance_id'] ?? 0);
    $inst = $instanceId ? instance_by_id($instanceId) : null;
    if ($inst) {
        $eventId = (int) $inst['event_id'];
    }
    $back = $inst ? 'admin.php?instance=' . $inst['id'] : ($eventId ? 'admin.php?event=' . $eventId : 'admin.php');

    switch ($do) {
        case 'create_event':
            $name = str_limit((string) ($_POST['name'] ?? ''), 200);
            if ($name === '') {
                flash('Give the event a name.');
                redirect('admin.php');
            }
            do {
                $code = random_code();
            } while (event_by_code($code));
            q('INSERT INTO events (code, name, created_at) VALUES (?,?,UTC_TIMESTAMP())', [$code, $name]);
            redirect('admin.php?event=' . db()->lastInsertId());

        case 'rename_event':
            $name = str_limit((string) ($_POST['name'] ?? ''), 200);
            if ($name !== '') {
                q('UPDATE events SET name = ? WHERE id = ?', [$name, $eventId]);
                flash('Event renamed.');
            }
            redirect($back);

        case 'delete_event':
            if (($_POST['confirm'] ?? '') === 'DELETE') {
                q('DELETE FROM events WHERE id = ?', [$eventId]);
                flash('Event deleted with all its data.');
                redirect('admin.php');
            }
            flash('Type DELETE to confirm.');
            redirect($back);

        case 'add_instance':
            $module = (string) ($_POST['module'] ?? '');
            $title = str_limit((string) ($_POST['title'] ?? ''), 200);
            if (!isset(MODULES[$module])) {
                redirect($back);
            }
            if ($title === '') {
                $title = MODULES[$module]['short'];
            }
            $id = create_instance($eventId, $module, $title);
            flash('Module added to the run of show. Set it up below.');
            redirect('admin.php?instance=' . $id);

        case 'delete_instance':
            if ($inst && ($_POST['confirm'] ?? '') === 'DELETE') {
                q('DELETE FROM instances WHERE id = ?', [$inst['id']]);
                flash('Module and its data deleted.');
                redirect('admin.php?event=' . $eventId);
            }
            flash('Type DELETE to confirm.');
            redirect($back);

        case 'slide_move':
            move_slide($eventId, (int) $_POST['slide_id'], (int) $_POST['dir'] > 0 ? 1 : -1);
            redirect($back . '#ros');

        case 'slide_hide':
            q('UPDATE slides SET hidden = 1 - hidden WHERE id = ? AND event_id = ?', [(int) $_POST['slide_id'], $eventId]);
            redirect($back . '#ros');

        case 'save_instance':
            if (!$inst) {
                redirect('admin.php');
            }
            $title = str_limit((string) ($_POST['title'] ?? ''), 200);
            if ($title !== '') {
                q('UPDATE instances SET title = ? WHERE id = ?', [$title, $inst['id']]);
            }
            $cfg = $inst['config'];
            $text = function (string $k, int $max = 2000) { return trim(mb_substr((string) ($_POST[$k] ?? ''), 0, $max)); };
            switch ($inst['module']) {
                case 'm1':
                    $cfg['intro'] = $text('intro', 500);
                    $cfg['min_answers'] = max(1, min(500, (int) ($_POST['min_answers'] ?? 8)));
                    $cfg['threshold'] = max(51, min(100, (int) ($_POST['threshold'] ?? 75))) / 100;
                    foreach ((array) ($_POST['st'] ?? []) as $sid => $stText) {
                        $stText = str_limit((string) $stText, 500);
                        $owned = q('SELECT 1 FROM m1_statements WHERE id = ? AND instance_id = ?', [(int) $sid, $inst['id']])->fetchColumn();
                        if ($owned && $stText !== '') {
                            m1_update_statement((int) $sid, $stText);
                        }
                    }
                    m1_add_statements((int) $inst['id'], preg_split('/\R/', (string) ($_POST['new_statements'] ?? '')));
                    break;
                case 'm2':
                    $cfg['prompt'] = $text('prompt', 500);
                    $cfg['max_length'] = max(20, min(500, (int) ($_POST['max_length'] ?? 120)));
                    $cfg['questions'] = parse_questions((string) ($_POST['questions'] ?? ''));
                    break;
                case 'm4':
                    $cfg['pledge'] = $text('pledge', 1000);
                    // fall through
                case 'm3':
                    $cfg['intro'] = $text('intro', 500);
                    $cfg['consent'] = $text('consent', 500);
                    break;
            }
            save_instance_config((int) $inst['id'], $cfg);
            flash('Saved.');
            redirect($back);

        case 'm1_remove':
            if ($inst) {
                q('UPDATE m1_statements SET active = 0 WHERE id = ? AND instance_id = ?', [(int) $_POST['statement_id'], $inst['id']]);
                flash('Statement removed.');
            }
            redirect($back . '#statements');

        case 'm1_move':
            if ($inst) {
                $ids = q('SELECT id FROM m1_statements WHERE instance_id = ? AND active = 1 ORDER BY position, id', [$inst['id']])
                    ->fetchAll(PDO::FETCH_COLUMN);
                $i = array_search((string) $_POST['statement_id'], array_map('strval', $ids), true);
                $j = $i === false ? false : $i + ((int) $_POST['dir'] > 0 ? 1 : -1);
                if ($i !== false && $j >= 0 && $j < count($ids)) {
                    [$ids[$i], $ids[$j]] = [$ids[$j], $ids[$i]];
                    foreach ($ids as $k => $id) {
                        q('UPDATE m1_statements SET position = ? WHERE id = ?', [($k + 1) * 10, $id]);
                    }
                }
            }
            redirect($back . '#statements');

        case 'reset_data':
            if ($inst && ($_POST['confirm'] ?? '') === 'RESET') {
                if ($inst['module'] === 'm1') {
                    q('DELETE a FROM m1_answers a JOIN m1_statements s ON s.id = a.statement_id WHERE s.instance_id = ?', [$inst['id']]);
                } else {
                    q('DELETE FROM submissions WHERE instance_id = ?', [$inst['id']]);
                }
                flash('Collected data cleared.');
            } else {
                flash('Type RESET to confirm.');
            }
            redirect($back);
    }
    redirect($back);
}

// ---------------------------------------------------------------- CSV export

if (isset($_GET['export'])) {
    $inst = instance_by_id((int) $_GET['export']);
    if (!$inst) {
        http_response_code(404);
        exit('Not found');
    }
    $filename = preg_replace('/[^A-Za-z0-9_-]+/', '_', $inst['title']) . '_' . gmdate('Y-m-d') . '.csv';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM so Excel reads accents correctly
    if ($inst['module'] === 'm1') {
        fputcsv($out, ['Statement', 'Wording version', 'Yes', 'No', "Don't know", 'Total', 'Result']);
        foreach (m1_stats($inst) as $s) {
            fputcsv($out, [$s['text'], $s['version'], $s['yes'], $s['no'], $s['dk'], $s['n'],
                ['open' => 'Not enough answers', 'yes' => 'Yes', 'no' => 'No', 'revise' => 'Needs revision'][$s['status']]]);
        }
        $old = q('SELECT h.text, h.version, SUM(a.answer="yes") y, SUM(a.answer="no") n, SUM(a.answer="dk") d
                  FROM m1_statement_history h JOIN m1_statements s ON s.id = h.statement_id
                  LEFT JOIN m1_answers a ON a.statement_id = h.statement_id AND a.version = h.version
                  WHERE s.instance_id = ? GROUP BY h.id, h.text, h.version', [$inst['id']])->fetchAll();
        foreach ($old as $o) {
            fputcsv($out, [$o['text'], $o['version'], (int) $o['y'], (int) $o['n'], (int) $o['d'],
                (int) $o['y'] + (int) $o['n'] + (int) $o['d'], 'Earlier wording (revised)']);
        }
    } else {
        $rows = q('SELECT * FROM submissions WHERE instance_id = ? ORDER BY created_at', [$inst['id']])->fetchAll();
        $questions = $inst['module'] === 'm2' ? $inst['config']['questions'] : [];
        $head = $inst['module'] === 'm2' ? ['Submitted (UTC)', 'Text', 'Status'] : ['Submitted (UTC)', 'Full name', 'Email', 'Consent'];
        foreach ($questions as $qq) {
            $head[] = $qq['q'];
        }
        fputcsv($out, $head);
        foreach ($rows as $r) {
            $line = $inst['module'] === 'm2'
                ? [$r['updated_at'], $r['body'], $r['status']]
                : [$r['updated_at'], $r['full_name'], $r['email'], $r['consent'] ? 'Yes' : 'No'];
            $ans = json_decode((string) $r['answers'], true) ?: [];
            foreach ($questions as $i => $qq) {
                $line[] = $ans[$i]['a'] ?? '';
            }
            // Neutralise spreadsheet formulas in attendee-typed text.
            $line = array_map(function ($v) { $v = (string) $v; return preg_match('/^[=+\-@]/', $v) ? "'" . $v : $v; }, $line);
            fputcsv($out, $line);
        }
    }
    exit;
}

// ---------------------------------------------------------------- pages

$msg = flash();

if (isset($_GET['instance'])) {
    render_instance((int) $_GET['instance'], $msg);
} elseif (isset($_GET['event'])) {
    render_event((int) $_GET['event'], $msg);
} else {
    render_events($msg);
}

function module_badge(string $module): string
{
    $colors = ['m1' => '', 'm2' => 'purple', 'm3' => 'green', 'm4' => 'pink'];
    return '<span class="badge ' . $colors[$module] . '">' . h(MODULES[$module]['short']) . '</span>';
}

function render_events(?string $msg): void
{
    $events = q('SELECT e.*, (SELECT COUNT(*) FROM instances i WHERE i.event_id = e.id) AS modules
                 FROM events e ORDER BY e.created_at DESC')->fetchAll();
    page_start('Events – iMasons Data Acquisition', '', 'Data Acquisition');
    if ($msg) echo '<div class="flash">' . h($msg) . '</div>';
    ?>
    <h1>Events</h1>
    <p class="lead">Each event has its own run of show, big screen, remote and attendee link.</p>
    <form method="post" class="box accent">
      <?= csrf_field() ?><input type="hidden" name="do" value="create_event">
      <label for="name" style="margin-top:0">New event</label>
      <div class="actions"><input type="text" id="name" name="name" placeholder="e.g. iMasons Everywhere – Dublin" style="flex:1;min-width:220px" required>
      <button class="btn" type="submit">Create event</button></div>
    </form>
    <?php if ($events): ?>
      <div class="list">
      <?php foreach ($events as $e): ?>
        <div class="row">
          <div class="grow"><a href="admin.php?event=<?= (int) $e['id'] ?>"><b><?= h($e['name']) ?></b></a><br>
            <span class="muted small">Code <?= h($e['code']) ?> · <?= (int) $e['modules'] ?> module<?= $e['modules'] == 1 ? '' : 's' ?> · created <?= h(substr($e['created_at'], 0, 10)) ?></span></div>
          <div class="actions">
            <a class="btn light sm" href="admin.php?event=<?= (int) $e['id'] ?>">Set up</a>
            <a class="btn sm" href="remote.php?c=<?= h($e['code']) ?>">Remote</a>
          </div>
        </div>
      <?php endforeach; ?>
      </div>
    <?php endif;
    page_end();
}

function render_event(int $id, ?string $msg): void
{
    $event = event_by_id($id);
    if (!$event) {
        redirect('admin.php');
    }
    $slides = event_slides($id);
    $instances = event_instances($id);
    page_start($event['name'] . ' – iMasons', '', $event['name']);
    if ($msg) echo '<div class="flash">' . h($msg) . '</div>';
    $join = app_url('e.php?c=' . $event['code']);
    ?>
    <p class="small"><a href="admin.php">← All events</a></p>
    <h1><?= h($event['name']) ?></h1>
    <div class="grid2" style="margin:20px 0">
      <div class="box ink"><div class="label">Big screen</div><p class="small muted">Open on the room's screen and press F11 for full screen.</p>
        <a class="btn block" href="screen.php?c=<?= h($event['code']) ?>" target="_blank" rel="noopener">Open big screen</a></div>
      <div class="box ink"><div class="label">Remote</div><p class="small muted">Open on your phone to run the session.</p>
        <a class="btn block" href="remote.php?c=<?= h($event['code']) ?>">Open remote</a></div>
      <div class="box ink"><div class="label">Attendee link</div><p class="small muted">The QR code on screen points here.</p>
        <div class="codebox"><?= h($join) ?></div></div>
    </div>

    <div class="section">Add a data collection module</div>
    <form method="post" class="box">
      <?= csrf_field() ?><input type="hidden" name="do" value="add_instance"><input type="hidden" name="event_id" value="<?= $id ?>">
      <div class="grid2">
        <div><label for="module">Module</label>
          <select id="module" name="module">
            <?php foreach (MODULES as $key => $m): ?><option value="<?= h($key) ?>"><?= h($m['name']) ?></option><?php endforeach; ?>
          </select></div>
        <div><label for="title">Title (shown on screen and phones)</label>
          <input type="text" id="title" name="title" placeholder="e.g. Where does the industry stand?"></div>
      </div>
      <p><button class="btn" type="submit">Add to run of show</button></p>
    </form>

    <div class="section" id="ros">Run of show</div>
    <p class="muted small">Hidden slides are skipped. Hiding a live participation slide also hides its results. Use the arrows to reorder.</p>
    <?php if (!$slides): ?>
      <p class="muted">No slides yet. Add a module above.</p>
    <?php else: ?>
      <div class="list">
      <?php foreach ($slides as $i => $s): $isLive = (int) $event['current_slide_id'] === $s['id']; ?>
        <div class="row<?= $s['visible'] ? '' : ' is-hidden' ?><?= $isLive ? ' is-live' : '' ?>">
          <span class="label" style="width:22px"><?= $i + 1 ?></span>
          <div class="grow"><?= module_badge($s['module']) ?> <b><?= h($s['title']) ?></b> – <?= h($s['label']) ?>
            <?php if ($isLive): ?> <span class="badge green">Live</span><?php endif; ?>
            <?php if ($s['hidden_by_parent']): ?><br><span class="small">Hidden because its participation slide is hidden</span><?php endif; ?>
          </div>
          <div class="actions">
            <?php foreach ([-1 => '↑', 1 => '↓'] as $dir => $arrow): ?>
              <form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="do" value="slide_move">
                <input type="hidden" name="event_id" value="<?= $id ?>"><input type="hidden" name="slide_id" value="<?= $s['id'] ?>">
                <input type="hidden" name="dir" value="<?= $dir ?>"><button class="btn light sm" aria-label="Move <?= $dir < 0 ? 'up' : 'down' ?>"><?= $arrow ?></button></form>
            <?php endforeach; ?>
            <form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="do" value="slide_hide">
              <input type="hidden" name="event_id" value="<?= $id ?>"><input type="hidden" name="slide_id" value="<?= $s['id'] ?>">
              <button class="btn light sm"><?= $s['hidden'] ? 'Show' : 'Hide' ?></button></form>
            <a class="btn light sm" href="admin.php?instance=<?= $s['instance_id'] ?>">Edit</a>
          </div>
        </div>
      <?php endforeach; ?>
      </div>
    <?php endif; ?>

    <?php if ($instances): ?>
    <div class="section">Modules and exports</div>
    <div class="list">
      <?php foreach ($instances as $inst): $d = instance_dots($inst); ?>
      <div class="row">
        <div class="grow"><?= module_badge($inst['module']) ?> <b><?= h($inst['title']) ?></b><br>
          <span class="muted small"><?= $d['done'] ?> participant<?= $d['done'] == 1 ? '' : 's' ?> · <?= $inst['is_open'] ? 'Open' : 'Closed' ?></span></div>
        <div class="actions"><a class="btn light sm" href="admin.php?instance=<?= (int) $inst['id'] ?>">Edit</a>
          <a class="btn light sm" href="admin.php?export=<?= (int) $inst['id'] ?>">Export CSV</a></div>
      </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <div class="section">Event settings</div>
    <div class="grid2" style="margin-top:16px">
      <form method="post" class="box"><?= csrf_field() ?><input type="hidden" name="do" value="rename_event"><input type="hidden" name="event_id" value="<?= $id ?>">
        <label for="ename" style="margin-top:0">Event name</label><input type="text" id="ename" name="name" value="<?= h($event['name']) ?>">
        <p><button class="btn light">Rename</button></p></form>
      <form method="post" class="box"><?= csrf_field() ?><input type="hidden" name="do" value="delete_event"><input type="hidden" name="event_id" value="<?= $id ?>">
        <label for="edel" style="margin-top:0">Delete event and all collected data</label><input type="text" id="edel" name="confirm" placeholder="Type DELETE">
        <p><button class="btn danger">Delete event</button></p></form>
    </div>
    <?php
    page_end();
}

function render_instance(int $id, ?string $msg): void
{
    $inst = instance_by_id($id);
    if (!$inst) {
        redirect('admin.php');
    }
    $event = event_by_id((int) $inst['event_id']);
    $cfg = $inst['config'];
    page_start($inst['title'] . ' – iMasons', '', $event['name']);
    if ($msg) echo '<div class="flash">' . h($msg) . '</div>';
    ?>
    <p class="small"><a href="admin.php?event=<?= (int) $event['id'] ?>">← <?= h($event['name']) ?></a></p>
    <h1><?= h($inst['title']) ?></h1>
    <p class="lead"><?= module_badge($inst['module']) ?> <?= h(MODULES[$inst['module']]['name']) ?></p>

    <form method="post">
      <?= csrf_field() ?><input type="hidden" name="do" value="save_instance"><input type="hidden" name="instance_id" value="<?= $id ?>">
      <div class="box">
        <label for="title" style="margin-top:0">Title (shown on screen and phones)</label>
        <input type="text" id="title" name="title" value="<?= h($inst['title']) ?>" required>

      <?php if ($inst['module'] === 'm1'): $stats = m1_stats($inst); ?>
        <label for="intro">Instruction on phones</label>
        <input type="text" id="intro" name="intro" value="<?= h($cfg['intro']) ?>">
        <div class="grid2">
          <div><label for="min">Answers needed before deciding</label><input type="number" id="min" name="min_answers" min="1" value="<?= (int) $cfg['min_answers'] ?>"></div>
          <div><label for="thr">Agreement needed to stop asking (%)</label><input type="number" id="thr" name="threshold" min="51" max="100" value="<?= (int) round($cfg['threshold'] * 100) ?>"></div>
        </div>
        <p class="muted small">Once a statement has this many answers and this share say Yes (or No), it is no longer asked. Otherwise it is flagged "Needs revision". "Don't know" counts in the total.</p>
      </div>

      <div class="section" id="statements">Statements (<?= count($stats) ?>)</div>
      <p class="muted small">Changing a statement's wording restarts its count; earlier answers are kept in the export.</p>
      <?php if ($stats): ?>
      <div class="list">
        <?php foreach ($stats as $i => $s): ?>
          <div class="row">
            <span class="label" style="width:22px"><?= $i + 1 ?></span>
            <div class="grow"><input type="text" name="st[<?= $s['id'] ?>]" value="<?= h($s['text']) ?>" aria-label="Statement <?= $i + 1 ?>">
              <div class="small muted" style="margin-top:4px">
                <?= status_badge($s['status']) ?> Yes <?= $s['yes'] ?> · No <?= $s['no'] ?> · Don't know <?= $s['dk'] ?><?= $s['version'] > 1 ? ' · wording v' . $s['version'] : '' ?>
              </div></div>
            <div class="actions">
              <button class="btn light sm" form="mv<?= $s['id'] ?>u" aria-label="Move up">↑</button>
              <button class="btn light sm" form="mv<?= $s['id'] ?>d" aria-label="Move down">↓</button>
              <button class="btn danger sm" form="rm<?= $s['id'] ?>">Remove</button>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
      <label for="new">Add statements (one per line)</label>
      <textarea id="new" name="new_statements" placeholder="Our industry is ready for 1 GW campuses.&#10;Water use is our biggest reputational risk."></textarea>

      <?php elseif ($inst['module'] === 'm2'): ?>
        <label for="prompt">Prompt</label>
        <textarea id="prompt" name="prompt" style="min-height:70px"><?= h($cfg['prompt']) ?></textarea>
        <label for="maxl">Maximum length (characters)</label>
        <input type="number" id="maxl" name="max_length" min="20" max="500" value="<?= (int) $cfg['max_length'] ?>">
        <label for="qs">Optional multiple-choice questions</label>
        <textarea id="qs" name="questions" style="min-height:150px" placeholder="Which region are you based in?&#10;- Europe&#10;- North America&#10;- Asia Pacific&#10;&#10;How long have you been in the industry?&#10;- Under 5 years&#10;- 5 years or more"><?= h(questions_to_text($cfg['questions'])) ?></textarea>
        <p class="muted small">One question per block: the question on the first line, each option on its own line starting with "- ", and a blank line between questions.</p>
      </div>

      <?php else: ?>
        <?php if ($inst['module'] === 'm4'): ?>
          <label for="pledge">Pledge</label>
          <textarea id="pledge" name="pledge" style="min-height:80px"><?= h($cfg['pledge']) ?></textarea>
        <?php endif; ?>
        <label for="intro">Instruction on phones</label>
        <input type="text" id="intro" name="intro" value="<?= h($cfg['intro']) ?>">
        <label for="consent">Consent tick box text</label>
        <textarea id="consent" name="consent" style="min-height:70px"><?= h($cfg['consent']) ?></textarea>
      </div>
      <?php endif; ?>

      <p><button class="btn" type="submit">Save</button></p>
    </form>

    <?php if ($inst['module'] === 'm1'): foreach (m1_stats($inst) as $s): ?>
      <?php foreach (['u' => -1, 'd' => 1] as $k => $dir): ?>
        <form method="post" id="mv<?= $s['id'] . $k ?>" hidden><?= csrf_field() ?><input type="hidden" name="do" value="m1_move">
          <input type="hidden" name="instance_id" value="<?= $id ?>"><input type="hidden" name="statement_id" value="<?= $s['id'] ?>"><input type="hidden" name="dir" value="<?= $dir ?>"></form>
      <?php endforeach; ?>
      <form method="post" id="rm<?= $s['id'] ?>" hidden onsubmit="return confirm('Remove this statement?')"><?= csrf_field() ?><input type="hidden" name="do" value="m1_remove">
        <input type="hidden" name="instance_id" value="<?= $id ?>"><input type="hidden" name="statement_id" value="<?= $s['id'] ?>"></form>
    <?php endforeach; endif; ?>

    <?php if ($inst['module'] !== 'm1'): render_submissions($inst); endif; ?>

    <div class="section">Data</div>
    <div class="grid2" style="margin-top:16px">
      <div class="box"><label style="margin-top:0">Download</label><a class="btn light" href="admin.php?export=<?= $id ?>">Export CSV</a></div>
      <form method="post" class="box"><?= csrf_field() ?><input type="hidden" name="do" value="reset_data"><input type="hidden" name="instance_id" value="<?= $id ?>">
        <label for="rst" style="margin-top:0">Clear collected answers (e.g. after a rehearsal)</label><input type="text" id="rst" name="confirm" placeholder="Type RESET">
        <p><button class="btn danger">Clear data</button></p></form>
      <form method="post" class="box"><?= csrf_field() ?><input type="hidden" name="do" value="delete_instance"><input type="hidden" name="instance_id" value="<?= $id ?>">
        <label for="del" style="margin-top:0">Delete this module and its slides</label><input type="text" id="del" name="confirm" placeholder="Type DELETE">
        <p><button class="btn danger">Delete module</button></p></form>
    </div>
    <?php
    page_end();
}

function status_badge(string $status): string
{
    $map = ['open' => ['grey', 'Collecting'], 'yes' => ['green', 'Yes'], 'no' => ['red', 'No'], 'revise' => ['purple', 'Needs revision']];
    return '<span class="badge ' . $map[$status][0] . '">' . $map[$status][1] . '</span>';
}

function render_submissions(array $inst): void
{
    $rows = q('SELECT * FROM submissions WHERE instance_id = ? ORDER BY created_at DESC LIMIT 500', [$inst['id']])->fetchAll();
    echo '<div class="section">Responses (' . count($rows) . ')</div>';
    if (!$rows) {
        echo '<p class="muted">Nothing yet.</p>';
        return;
    }
    echo '<div style="overflow-x:auto;margin-top:12px"><table class="data"><tr><th>When (UTC)</th>';
    echo $inst['module'] === 'm2' ? '<th>Text</th><th>Status</th>' : '<th>Name</th><th>Email</th>';
    echo '</tr>';
    foreach ($rows as $r) {
        echo '<tr><td>' . h(substr($r['updated_at'], 5, 11)) . '</td>';
        if ($inst['module'] === 'm2') {
            echo '<td>' . h($r['body']) . '</td><td>' . h($r['status']) . '</td>';
        } else {
            echo '<td>' . h($r['full_name']) . '</td><td>' . h($r['email']) . '</td>';
        }
        echo '</tr>';
    }
    echo '</table></div>';
}
