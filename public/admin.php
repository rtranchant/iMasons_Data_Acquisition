<?php
// Admin: events, module instances, module configuration, run of show, exports.

declare(strict_types=1);

require __DIR__ . '/../src/model.php';
set_exception_handler('page_error_handler');
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
    event_bump($eventId);   // phones and the screen see the change on their next check, not after a cache expires

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
                $gone = q('SELECT p.id, p.pkey FROM deck_pages p JOIN decks d ON d.id = p.deck_id WHERE d.event_id = ?', [$eventId])->fetchAll();
                q('DELETE FROM events WHERE id = ?', [$eventId]);
                media_forget($gone);
                flash('Event deleted with all its data.');
                redirect('admin.php');
            }
            flash('Type DELETE to confirm.');
            redirect($back);

        case 'add_instance':
            $module = (string) ($_POST['module'] ?? '');
            $title = str_limit((string) ($_POST['title'] ?? ''), 200);
            if (!isset(MODULES[$module]) || !empty(MODULES[$module]['event_toggle'])) {
                redirect($back);
            }
            if ($title === '') {
                $title = MODULES[$module]['short'];
            }
            $after = ($_POST['after_slide_id'] ?? '') === '' ? null : (int) $_POST['after_slide_id'];
            $id = create_instance($eventId, $module, $title, $after);
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

        case 'toggle_collateral':
            $event = event_by_id($eventId);
            if ($event) {
                $on = !empty($_POST['on']);
                set_collateral($event, $on);
                flash($on ? 'Collateral request switched on: its page was added at the end of the run of show and the email banner now shows on phones.'
                          : 'Collateral request switched off: its page was removed. Sign-ups collected so far are kept.');
            }
            redirect($back . '#collateral');

        case 'reorder':
            reorder_slides($eventId, array_map('intval', (array) ($_POST['ids'] ?? [])));
            if (!empty($_POST['ajax'])) {
                json_out(['ok' => true]);
            }
            redirect($back . '#ros');

        case 'delete_deck':
            $gone = q('SELECT p.id, p.pkey FROM deck_pages p JOIN decks d ON d.id = p.deck_id WHERE d.id = ? AND d.event_id = ?', [(int) ($_POST['deck_id'] ?? 0), $eventId])->fetchAll();
            q('DELETE FROM decks WHERE id = ? AND event_id = ?', [(int) ($_POST['deck_id'] ?? 0), $eventId]);
            media_forget($gone);
            flash('Slides deleted.');
            redirect($back . '#decks');

        case 'rename_deck':
            $name = str_limit((string) ($_POST['name'] ?? ''), 200);
            if ($name !== '') {
                q('UPDATE decks SET name = ? WHERE id = ? AND event_id = ?', [$name, (int) ($_POST['deck_id'] ?? 0), $eventId]);
            }
            redirect($back . '#decks');

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
            $text = function (string $k, int $max = 2000) { return str_multiline((string) ($_POST[$k] ?? ''), $max); };
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
                    $cfg['max_length'] = max(20, min(3000, (int) ($_POST['max_length'] ?? 120)));
                    $cfg['questions'] = parse_questions((string) ($_POST['questions'] ?? ''));
                    break;
                case 'm6':
                case 'm7':
                case 'm8':
                    [$cfg, $svMsg] = sv_config_from_post($inst['module'], $cfg, $_POST);
                    break;
                case 'm5':
                    $cfg['prompt'] = $text('prompt', 400) ?: QF_DEFAULT_PROMPT;
                    $cfg['set'] = in_array($_POST['set'] ?? '', ['core', 'all', 'ids'], true) ? $_POST['set'] : 'core';
                    $cfg['ids'] = str_limit((string) ($_POST['ids'] ?? ''), 4000);
                    $cfg['min_n'] = max(1, min(100, (int) ($_POST['min_n'] ?? 6)));
                    $cfg['flag_min'] = max(1, min(500, (int) ($_POST['flag_min'] ?? 15)));
                    $cfg['flag_yes'] = max(50, min(100, (int) ($_POST['flag_yes'] ?? 90))) / 100;
                    $cfg['flag_checks'] = max(1, min(4, (int) ($_POST['flag_checks'] ?? 2)));
                    $cfg['flag_mode'] = in_array($_POST['flag_mode'] ?? '', ['exclude', 'half', 'include'], true) ? $_POST['flag_mode'] : 'exclude';
                    $cfg['view_side'] = in_array($_POST['view_side'] ?? '', ['hiring', 'candidate', 'education', 'all'], true) ? $_POST['view_side'] : 'hiring';
                    $cfg['theme'] = str_limit((string) ($_POST['theme'] ?? ''), 120);
                    $cfg['stage'] = str_limit((string) ($_POST['stage'] ?? ''), 60);
                    $cfg['tab'] = max(1, min(4, (int) ($_POST['tab'] ?? 1)));
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
            flash(empty($svMsg) ? 'Saved.' : 'Saved. ' . $svMsg);
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

        case 'qf_import':
            if ($inst && $inst['module'] === 'm5') {
                $csv = '';
                if (!empty($_FILES['csv_file']['tmp_name']) && is_uploaded_file($_FILES['csv_file']['tmp_name'])) {
                    $csv = (string) file_get_contents($_FILES['csv_file']['tmp_name']);
                } else {
                    $csv = (string) ($_POST['csv_text'] ?? '');
                }
                [$added, $updated, $errors] = qf_import_csv((int) $inst['id'], $csv);
                flash(($added || $updated ? "Imported: $added new, $updated updated statement" . ($added + $updated === 1 ? '' : 's') . '. ' : '')
                    . ($errors ? implode(' ', array_slice($errors, 0, 5)) . (count($errors) > 5 ? ' …and ' . (count($errors) - 5) . ' more.' : '') : ''));
            }
            redirect($back . '#statements');

        case 'qf_open':
            if ($inst) {
                q('UPDATE instances SET is_open = ? WHERE id = ?', [empty($_POST['open']) ? 0 : 1, $inst['id']]);
            }
            redirect($back);

        case 'qf_show':
            $sid = (int) ($_POST['slide_id'] ?? 0);
            if ($inst && q('SELECT 1 FROM slides WHERE id = ? AND instance_id = ?', [$sid, $inst['id']])->fetchColumn()) {
                q('UPDATE slides SET hidden = 0 WHERE id = ?', [$sid]);
                set_current_slide($eventId, $sid);
                flash('Now on the big screen.');
            }
            redirect($back);

        case 'qf_seed':
            if ($inst && $inst['module'] === 'm5' && ($_POST['confirm'] ?? '') === 'DEMO') {
                $n = qf_seed_demo($inst);
                flash($n ? "Added $n demo participants with answers. Use Clear test answers before the session." : 'Import statements first.');
            } else {
                flash('Type DEMO to confirm.');
            }
            redirect($back);

        case 'reset_data':
            if ($inst && ($_POST['confirm'] ?? '') === 'RESET') {
                if ($inst['module'] === 'm5') {
                    qf_clear((int) $inst['id']);
                } elseif ($inst['module'] === 'm1') {
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
    if ($inst['module'] === 'm5') {
        [$qst, $qpeople, $qanswers] = qf_load($inst);
        $flags = qf_person_stats($qst, $qanswers, qf_options($inst['config']));
        fputcsv($out, ['Statement id', 'Theme', 'Topic', 'Stage', 'Statement', 'Core set', 'Check statement',
                       'Participant', 'Side', 'Segment', 'Answer', 'Answered at (UTC)', 'Flagged as straight-liner']);
        $rows = q('SELECT participant_id, statement_id, answer, created FROM qf_answers WHERE instance_id = ? ORDER BY created', [$inst['id']])->fetchAll();
        $label = ['Y' => 'Yes', 'N' => 'No', 'D' => "Don't know"];
        foreach ($rows as $r) {
            $s = $qst[(int) $r['statement_id']] ?? null;
            $p = $qpeople[(int) $r['participant_id']] ?? ['side' => '', 'segment' => ''];
            if (!$s) {
                continue;
            }
            fputcsv($out, [$s['code'], $s['theme'], $s['topic'], $s['stage'], $s['statement'], $s['core_set'] ? 'Yes' : 'No', $s['is_check'] ? 'Yes' : 'No',
                'P' . $r['participant_id'], $p['side'], $p['segment'], $label[$r['answer']] ?? $r['answer'], $r['created'],
                !empty($flags[(int) $r['participant_id']]['flagged']) ? 'Yes' : 'No']);
        }
        exit;
    }
    if (in_array($inst['module'], SV_MODULES, true)) {
        fputcsv($out, ['Question', $inst['config']['prompt']]);
        $head = ['Submitted (UTC)', 'Participant', 'Answer'];
        if ($inst['module'] === 'm7') {
            $head[] = 'Other text';
            $head[] = 'Other text status';
        }
        fputcsv($out, $head);
        foreach (q('SELECT * FROM submissions WHERE instance_id = ? ORDER BY updated_at', [$inst['id']])->fetchAll() as $r) {
            $line = array_merge([$r['updated_at'], 'P' . $r['participant_id']], sv_export_answer($inst, $r));
            $line = array_map(function ($v) { $v = (string) $v; return preg_match('/^[=+@]|^-(?![0-9.])/', $v) ? "'" . $v : $v; }, $line);
            fputcsv($out, $line);
        }
        exit;
    }
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
    $colors = ['m1' => '', 'm2' => 'purple', 'm3' => 'green', 'm4' => 'pink', 'm5' => 'red', 'm6' => 'teal', 'm7' => 'teal', 'm8' => 'teal', 'deck' => 'grey'];
    return '<span class="badge ' . $colors[$module] . '">' . h($module === 'deck' ? 'Slides' : MODULES[$module]['short']) . '</span>';
}

function position_options(array $slides): string
{
    $html = '<option value="">At the end</option><option value="0">At the start</option>';
    foreach ($slides as $i => $s) {
        $html .= '<option value="' . $s['id'] . '">After ' . ($i + 1) . '. ' . h($s['title'] . ' – ' . $s['label']) . '</option>';
    }
    return $html;
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
        <a class="btn block" href="screen.php?c=<?= h($event['code']) ?>" target="imasons_screen_<?= h($event['code']) ?>">Open big screen</a></div>
      <div class="box ink"><div class="label">Remote</div><p class="small muted">Open on your phone to run the session.</p>
        <a class="btn block" href="remote.php?c=<?= h($event['code']) ?>">Open remote</a></div>
      <div class="box ink"><div class="label">Attendee link</div><p class="small muted">The QR code on screen points here.</p>
        <div class="codebox"><?= h($join) ?></div></div>
    </div>

    <div class="section">After the session</div>
    <div class="grid2" style="margin:16px 0 8px">
      <div class="box"><label style="margin-top:0">Slides with results</label><p class="small muted">A PDF with a picture of every slide, including the data pages with their final results.</p>
        <a class="btn light" href="export.php?event=<?= $id ?>">Export slides as PDF</a></div>
      <div class="box"><label style="margin-top:0">Impact story</label><p class="small muted">A press-release style story of the session, written from everything collected.</p>
        <a class="btn light" href="story.php?event=<?= $id ?>">Impact story</a></div>
    </div>

    <div class="section">Upload slides</div>
    <div class="box" id="uploader" data-event="<?= $id ?>">
      <p class="muted small" style="margin-top:0">In PowerPoint choose <b>File → Save As → PDF</b> (or File → Export → PNG) and upload the result.
        Each page becomes a slide you can place between the data collection pages. Animations and videos are not carried over.</p>
      <div class="grid2">
        <div><label for="up-file">PDF, or PNG/JPG images</label>
          <input type="file" id="up-file" accept="application/pdf,image/png,image/jpeg" multiple></div>
        <div><label for="up-name">Name</label><input type="text" id="up-name" placeholder="Defaults to the file name"></div>
        <div><label for="up-after">Place in the run of show</label>
          <select id="up-after"><?= position_options($slides) ?></select></div>
      </div>
      <p><button class="btn" type="button" id="up-go">Upload slides</button></p>
      <div id="up-status" class="small" aria-live="polite"></div>
    </div>

    <div class="section">Add a data collection module</div>
    <form method="post" class="box">
      <?= csrf_field() ?><input type="hidden" name="do" value="add_instance"><input type="hidden" name="event_id" value="<?= $id ?>">
      <div class="grid2">
        <div><label for="module">Module</label>
          <select id="module" name="module">
            <?php foreach (MODULES as $key => $m): if (!empty($m['event_toggle'])) continue; ?><option value="<?= h($key) ?>"><?= h($m['name']) ?></option><?php endforeach; ?>
          </select></div>
        <div><label for="title">Title (shown on screen and phones)</label>
          <input type="text" id="title" name="title" placeholder="e.g. Where does the industry stand?"></div>
        <div><label for="after">Place in the run of show</label>
          <select id="after" name="after_slide_id"><?= position_options($slides) ?></select></div>
      </div>
      <p><button class="btn" type="submit">Add to run of show</button></p>
    </form>

    <div class="section" id="ros">Run of show</div>
    <p class="muted small">Drag ☰ to reorder (or use the arrows). Hidden slides are skipped. Hiding a live participation slide also hides its results.</p>
    <?php if (!$slides): ?>
      <p class="muted">No slides yet. Add a module above.</p>
    <?php else: ?>
      <div class="list" id="ros-list" data-event="<?= $id ?>" data-csrf="<?= h(csrf_token()) ?>">
      <?php foreach ($slides as $i => $s): $isLive = (int) $event['current_slide_id'] === $s['id']; ?>
        <div class="row<?= $s['visible'] ? '' : ' is-hidden' ?><?= $isLive ? ' is-live' : '' ?>" data-id="<?= $s['id'] ?>">
          <span class="handle" title="Drag to reorder" aria-hidden="true">☰</span>
          <span class="label num" style="width:22px"><?= $i + 1 ?></span>
          <?php if ($s['thumb']): ?><img class="thumb" src="<?= h($s['thumb']) ?>" alt="" loading="lazy"><?php endif; ?>
          <div class="grow"><?= module_badge($s['module']) ?> <b><?= h($s['title']) ?></b> – <?= h($s['label']) ?>
            <?php if ($isLive): ?> <span class="badge green">Live</span><?php endif; ?>
            <?php if (!$s['visible']): ?> <span class="badge grey">Hidden</span><?php endif; ?>
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
            <?php if ($s['kind'] === 'image'): ?>
              <a class="btn light sm" href="#decks">Deck</a>
            <?php else: ?>
              <a class="btn light sm" href="admin.php?instance=<?= $s['instance_id'] ?>">Edit</a>
            <?php endif; ?>
          </div>
        </div>
      <?php endforeach; ?>
      </div>
    <?php endif; ?>

    <?php $decks = event_decks($id); if ($decks): ?>
    <div class="section" id="decks">Uploaded slides</div>
    <p class="muted small">Upload a revised version to update a deck in place: its slides keep their position in the run of show.</p>
    <div class="list">
      <?php foreach ($decks as $d): ?>
      <div class="row">
        <div class="grow"><b><?= h($d['name']) ?></b><br><span class="muted small"><?= (int) $d['pages'] ?> page<?= $d['pages'] == 1 ? '' : 's' ?> · updated <?= h(substr($d['updated_at'], 0, 16)) ?> UTC</span>
          <div class="small" id="rp-status-<?= (int) $d['id'] ?>" aria-live="polite"></div></div>
        <div class="actions">
          <label class="btn light sm" style="margin:0">Upload revised version
            <input type="file" class="replace-file" data-deck="<?= (int) $d['id'] ?>" accept="application/pdf,image/png,image/jpeg" multiple hidden></label>
          <form method="post" class="inline" onsubmit="return confirm('Delete these slides from the run of show?')"><?= csrf_field() ?>
            <input type="hidden" name="do" value="delete_deck"><input type="hidden" name="event_id" value="<?= $id ?>">
            <input type="hidden" name="deck_id" value="<?= (int) $d['id'] ?>"><button class="btn danger sm">Delete</button></form>
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

    <?php $coll = !empty($event['collateral_instance_id']) ? instance_by_id((int) $event['collateral_instance_id']) : null;
          $collOn = !empty($event['collateral_on']) && $coll; ?>
    <div class="section" id="collateral">Collateral request</div>
    <div class="box <?= $collOn ? 'accent' : '' ?>" style="margin-top:16px">
      <form method="post" class="actions" style="justify-content:space-between">
        <?= csrf_field() ?><input type="hidden" name="do" value="toggle_collateral"><input type="hidden" name="event_id" value="<?= $id ?>">
        <input type="hidden" name="on" value="<?= $collOn ? '0' : '1' ?>">
        <div><b>Send attendees the materials from this session</b><br>
          <span class="muted small"><?= $collOn
            ? 'On: a sign-up page is in the run of show, and every phone shows a banner to leave name and email.'
            : 'Off. Switching it on adds a sign-up page to the run of show and an email banner on every phone.' ?></span></div>
        <div class="switch" role="group" aria-label="Collateral request">
          <button type="<?= $collOn ? 'submit' : 'button' ?>" class="<?= $collOn ? '' : 'on' ?>">Off</button>
          <button type="<?= $collOn ? 'button' : 'submit' ?>" class="<?= $collOn ? 'on' : '' ?>">On</button>
        </div>
      </form>
      <?php if ($coll): $cd = instance_dots($coll); ?>
        <p class="small" style="margin-bottom:0"><?= $cd['done'] ?> sign-up<?= $cd['done'] == 1 ? '' : 's' ?> ·
          <a href="admin.php?instance=<?= (int) $coll['id'] ?>">Edit wording</a> ·
          <a href="admin.php?export=<?= (int) $coll['id'] ?>">Export CSV</a></p>
      <?php endif; ?>
    </div>

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
    page_end('<script>window.CSRF = ' . json_encode(csrf_token()) . ';</script>'
        . '<script src="assets/vendor/Sortable.min.js"></script>'
        . '<script src="assets/admin.js?v=3"></script>');
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
        <textarea id="intro" name="intro" style="min-height:70px"><?= h($cfg['intro']) ?></textarea>
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
        <input type="number" id="maxl" name="max_length" min="20" max="3000" value="<?= (int) $cfg['max_length'] ?>">
        <label for="qs">Optional multiple-choice questions</label>
        <textarea id="qs" name="questions" style="min-height:150px" placeholder="Which region are you based in?&#10;- Europe&#10;- North America&#10;- Asia Pacific&#10;&#10;How long have you been in the industry?&#10;- Under 5 years&#10;- 5 years or more"><?= h(questions_to_text($cfg['questions'])) ?></textarea>
        <p class="muted small">One question per block: the question on the first line, each option on its own line starting with "- ", and a blank line between questions.</p>
      </div>

      <?php elseif (in_array($inst['module'], SV_MODULES, true)): render_sv_settings($inst); ?>
      </div>

      <?php elseif ($inst['module'] === 'm5'): render_qf_settings($inst); ?>
      </div>

      <?php else: ?>
        <?php if ($inst['module'] === 'm4'): ?>
          <label for="pledge">Pledge</label>
          <textarea id="pledge" name="pledge" style="min-height:80px"><?= h($cfg['pledge']) ?></textarea>
        <?php endif; ?>
        <label for="intro">Instruction on phones</label>
        <textarea id="intro" name="intro" style="min-height:70px"><?= h($cfg['intro']) ?></textarea>
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

    <?php if ($inst['module'] === 'm5'): render_qf_panels($inst); elseif ($inst['module'] !== 'm1'): render_submissions($inst); endif; ?>

    <div class="section">Data</div>
    <div class="grid2" style="margin-top:16px">
      <div class="box"><label style="margin-top:0">Download</label><a class="btn light" href="admin.php?export=<?= $id ?>">Export CSV</a></div>
      <form method="post" class="box"><?= csrf_field() ?><input type="hidden" name="do" value="reset_data"><input type="hidden" name="instance_id" value="<?= $id ?>">
        <label for="rst" style="margin-top:0"><?= $inst['module'] === 'm5' ? 'Clear test answers (all answers and profiles; statements stay)' : 'Clear collected answers (e.g. after a rehearsal)' ?></label><input type="text" id="rst" name="confirm" placeholder="Type RESET">
        <p><button class="btn danger"><?= $inst['module'] === 'm5' ? 'Clear test answers' : 'Clear data' ?></button></p></form>
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
    if (in_array($inst['module'], SV_MODULES, true)) {
        render_sv_submissions($inst);
        return;
    }
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
            echo '<td class="txt">' . h($r['body']) . '</td><td>' . h($r['status']) . '</td>';
        } else {
            echo '<td>' . h($r['full_name']) . '</td><td>' . h($r['email']) . '</td>';
        }
        echo '</tr>';
    }
    echo '</table></div>';
}

// ---------------------------------------------------------------- quick-fire (module 5) admin

function render_qf_settings(array $inst): void
{
    $cfg = $inst['config'];
    $sel = function (string $name, array $opts, $cur) {
        $h = '<select id="' . $name . '" name="' . $name . '">';
        foreach ($opts as $v => $l) {
            $h .= '<option value="' . h($v) . '"' . ((string) $cur === (string) $v ? ' selected' : '') . '>' . h($l) . '</option>';
        }
        return $h . '</select>';
    };
    $st = qf_statements($inst);
    $themes = array_values(array_unique(array_filter(array_map(function ($s) { return $s['is_check'] ? '' : $s['theme']; }, $st))));
    sort($themes);
    $core = count(array_filter($st, function ($s) { return $s['core_set'] && !$s['is_check']; }));
    $all = count(array_filter($st, function ($s) { return !$s['is_check']; }));
    ?>
        <label for="prompt">Text shown above every card</label>
        <textarea id="prompt" name="prompt" style="min-height:60px"><?= h($cfg['prompt']) ?></textarea>
        <div class="grid2">
          <div><label for="set">Statement set</label>
            <?= $sel('set', ['core' => "Core set ($core)", 'all' => "All ($all)", 'ids' => 'Pasted list of ids'], $cfg['set']) ?></div>
          <div><label for="ids">Ids for the pasted list (e.g. the top 15 for the second pass)</label>
            <input type="text" id="ids" name="ids" value="<?= h($cfg['ids']) ?>" placeholder="Q001, Q014, Q027"></div>
          <div><label for="min_n">Minimum answers before a statement is shown on the results page</label>
            <input type="number" id="min_n" name="min_n" min="1" value="<?= (int) $cfg['min_n'] ?>"></div>
        </div>
        <h3 style="margin-top:24px">Straight-liners</h3>
        <p class="muted small" style="margin-top:0">Flagged if they answered at least <b>A</b> statements and said Yes to at least <b>B</b>% of them, or said Yes to <b>C</b> or more check statements.</p>
        <div class="grid2">
          <div><label for="flag_min">A: at least this many answers</label><input type="number" id="flag_min" name="flag_min" min="1" value="<?= (int) $cfg['flag_min'] ?>"></div>
          <div><label for="flag_yes">B: Yes on at least (%)</label><input type="number" id="flag_yes" name="flag_yes" min="50" max="100" value="<?= (int) round($cfg['flag_yes'] * 100) ?>"></div>
          <div><label for="flag_checks">C: Yes to this many check statements</label><input type="number" id="flag_checks" name="flag_checks" min="1" max="4" value="<?= (int) $cfg['flag_checks'] ?>"></div>
          <div><label for="flag_mode">Flagged people are</label>
            <?= $sel('flag_mode', ['exclude' => 'Excluded (default)', 'half' => 'Counted at half weight', 'include' => 'Included'], $cfg['flag_mode']) ?></div>
        </div>
        <h3 style="margin-top:24px">Results view</h3>
        <div class="grid2">
          <div><label for="view_side">Side shown</label>
            <?= $sel('view_side', ['hiring' => 'Hiring side', 'candidate' => 'Candidate side', 'education' => 'Education side', 'all' => 'Everyone'], $cfg['view_side']) ?></div>
          <div><label for="theme">Theme filter (optional)</label>
            <?= $sel('theme', ['' => 'All themes'] + array_combine($themes, $themes), $cfg['theme']) ?></div>
          <div><label for="stage">Stage filter (optional)</label>
            <?= $sel('stage', ['' => 'All stages'] + array_combine(QF_STAGES, QF_STAGES), $cfg['stage']) ?></div>
          <div><label for="tab">Tab on the results screen</label>
            <?= $sel('tab', [1 => '1. Top problems', 2 => '2. Where it breaks', 3 => '3. Blind spots', 4 => '4. Side by side'], $cfg['tab']) ?></div>
        </div>
    <?php
}

function render_qf_panels(array $inst): void
{
    $id = (int) $inst['id'];
    $st = qf_statements($inst);
    $inSet = count(array_filter($st, function ($s) { return $s['in_set']; }));
    $checks = count(array_filter($st, function ($s) { return $s['is_check']; }));
    $live = qf_live_stats($inst);
    $slides = q('SELECT id, kind FROM slides WHERE instance_id = ?', [$id])->fetchAll();
    ?>
    <div class="section">Run it</div>
    <div class="grid2" style="margin-top:16px">
      <form method="post" class="box accent"><?= csrf_field() ?><input type="hidden" name="do" value="qf_open"><input type="hidden" name="instance_id" value="<?= $id ?>">
        <input type="hidden" name="open" value="<?= $inst['is_open'] ? '0' : '1' ?>">
        <label style="margin-top:0">Quick-fire is <b><?= $inst['is_open'] ? 'open' : 'closed' ?></b></label>
        <p class="muted small">Phones can answer only while it is open.</p>
        <button class="btn <?= $inst['is_open'] ? 'light' : 'lime' ?>"><?= $inst['is_open'] ? 'Close it' : 'Open it' ?></button></form>
      <div class="box">
        <label style="margin-top:0">Show on the big screen</label>
        <div class="actions">
        <?php foreach ($slides as $sl): ?>
          <form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="do" value="qf_show"><input type="hidden" name="instance_id" value="<?= $id ?>">
            <input type="hidden" name="slide_id" value="<?= (int) $sl['id'] ?>"><button class="btn sm"><?= $sl['kind'] === 'collect' ? 'Live feedback' : 'Results' ?></button></form>
        <?php endforeach; ?>
        </div>
        <p class="small"><a href="screen.php?c=<?= h(event_by_id((int) $inst['event_id'])['code']) ?>" target="imasons_screen_<?= h(event_by_id((int) $inst['event_id'])['code']) ?>">Open the big screen</a></p>
      </div>
      <div class="box">
        <label style="margin-top:0">So far</label>
        <p class="small" style="margin:0"><?= (int) $live['people'] ?> people · <?= (int) $live['answers'] ?> answers · <?= (int) $live['reached'] ?> of <?= (int) $live['active'] ?> statements have reached the minimum n</p>
      </div>
    </div>

    <div class="section" id="statements">Statements</div>
    <p class="muted small"><?= count($st) - $checks ?> statements loaded (<?= $inSet ?> in the current set) and <?= $checks ?> check statements (Q901–Q904, added automatically).</p>
    <form method="post" enctype="multipart/form-data" class="box"><?= csrf_field() ?><input type="hidden" name="do" value="qf_import"><input type="hidden" name="instance_id" value="<?= $id ?>">
      <label for="csvf" style="margin-top:0">Import statements from CSV</label>
      <p class="muted small" style="margin-top:0">Columns: <code>id, theme, topic, stage, statement, core_set</code>, optional <code>is_check</code>, in any order. Ids already loaded are updated; new ids are added. Answers are kept.</p>
      <input type="file" id="csvf" name="csv_file" accept=".csv,text/csv">
      <details><summary class="small">…or paste the CSV text</summary><textarea name="csv_text" style="min-height:120px" placeholder="id,theme,topic,stage,statement,core_set"></textarea></details>
      <p><button class="btn">Import</button></p>
    </form>
    <?php if ($st): ?>
    <details><summary>Show the <?= count($st) ?> statements</summary>
      <div style="overflow-x:auto"><table class="data"><tr><th>Id</th><th>Theme</th><th>Stage</th><th>Statement</th><th>Core</th><th>In set</th></tr>
      <?php foreach ($st as $s): ?>
        <tr><td><?= h($s['code']) ?></td><td><?= h($s['theme']) ?></td><td><?= h($s['stage']) ?></td><td><?= h($s['statement']) ?></td>
            <td><?= $s['is_check'] ? 'check' : ($s['core_set'] ? 'yes' : '–') ?></td><td><?= $s['in_set'] ? 'yes' : '–' ?></td></tr>
      <?php endforeach; ?>
      </table></div>
    </details>
    <?php endif; ?>

    <div class="section">Rehearsal</div>
    <form method="post" class="box" style="margin-top:16px"><?= csrf_field() ?><input type="hidden" name="do" value="qf_seed"><input type="hidden" name="instance_id" value="<?= $id ?>">
      <label for="demo" style="margin-top:0">Add demo answers: 40 fake people (30 hiring, 6 candidate, 4 education), one straight-liner</label>
      <p class="muted small" style="margin-top:0">To check the big screen before the session. Remove them with <b>Clear test answers</b> below.</p>
      <input type="text" id="demo" name="confirm" placeholder="Type DEMO" style="max-width:220px"> <button class="btn light">Add demo answers</button>
    </form>

    <div class="section">How to read the results</div>
    <div class="box qf-howto" style="margin-top:16px">
      <p><b>What a Yes means.</b> Someone has seen this problem stop people getting hired in the last 12 months. It is experience, not opinion.</p>
      <p><b>Rank by lift, not % Yes.</b> Most people say Yes to most statements. Lift shows how much more often a statement got a Yes than those same people usually give. +25 is a strong signal; +5 or less is the room's normal level of agreement.</p>
      <p><b>Check n.</b> n is how many Yes and No answers a statement has. Under 10, treat it as a hint, not a finding. A gap of less than about 15 points between two statements with small n is not a real difference.</p>
      <p><b>This is the room, not the industry.</b> About 60 people, mostly from the hiring side, who chose to attend. Use the results to decide which problems to explore, not to quantify them.</p>
      <p><b>Stay on one side.</b> The default view is the hiring side, because that's most of the room. It shows what employers see. It cannot show what candidates experience. Only compare sides on Tab 4, and only when it shows a chart.</p>
      <p><b>Use the blind spots.</b> A high Don't know rate on the hiring side often marks exactly where the disconnect is: something that happens to candidates out of employers' sight. Take those statements to the panel's veteran and student voices, and into deep dive 1.</p>
      <p><b>Straight-liners.</b> People who said Yes to almost everything, or Yes to the check statements, are left out of the results by default. The header shows how many.</p>
      <h3 style="margin-top:22px">What each tab on the big screen shows</h3>
      <p><b>1. Top problems.</b> The big number is the statement's lift in points: how much more often it got a Yes than the same people usually give. The lime bar shows that lift against the top statement's. n is the number of Yes and No answers; "low n" marks fewer than 10. Statements need the minimum n set above to appear; check statements never do.</p>
      <p><b>2. Where it breaks.</b> Each stage block counts its "strong problems": statements with a lift of +20 or more (among those with the minimum n). White = none, light lime = 1, lime = 2 or more. "Not enough answers" means the stage has fewer than 3 statements with the minimum n. Below, up to 4 stages with a strong problem, each with its top statement and lift.</p>
      <p><b>3. Blind spots.</b> The big number is the share of "Don't know" among all answers to the statement (n counts all answers here, including Don't know). On the hiring side, this is what employers can't see from where they sit.</p>
      <p><b>4. Side by side.</b> Candidate lift minus hiring lift, per statement, only where both sides have the minimum n and each side has at least 8 people. Left: statements candidates see as a bigger problem than employers do; right: the reverse. Never compare sides on raw % Yes. With few candidate answers per statement, read the pattern, not the exact numbers.</p>
      <p><b>On the big screen:</b> keys 1–4 switch tabs; → and ← (or a clicker) go through the tabs and then on to the next slide; ↓ and ↑ show the next or previous 5 rows; Enter shows the top row alone, full screen; Esc goes back to the list.</p>
    </div>
    <?php
}

// ---------------------------------------------------------------- survey modules (m6 scale, m7 multiple choice, m8 number)

function render_sv_settings(array $inst): void
{
    $cfg = $inst['config'];
    $num = function ($v) { return $v === null ? '' : rtrim(rtrim(number_format((float) $v, 3, '.', ''), '0'), '.'); };
    ?>
        <label for="prompt">Question</label>
        <textarea id="prompt" name="prompt" style="min-height:70px"><?= h($cfg['prompt']) ?></textarea>
    <?php if ($inst['module'] === 'm6'): ?>
        <div class="grid2">
          <div><label for="smin">Lowest value</label><input type="number" id="smin" name="min" step="1" value="<?= (int) $cfg['min'] ?>"></div>
          <div><label for="smax">Highest value</label><input type="number" id="smax" name="max" step="1" value="<?= (int) $cfg['max'] ?>"></div>
          <div><label for="slo">Label for the lowest value (optional)</label><input type="text" id="slo" name="low_label" value="<?= h($cfg['low_label']) ?>"></div>
          <div><label for="shi">Label for the highest value (optional)</label><input type="text" id="shi" name="high_label" value="<?= h($cfg['high_label']) ?>"></div>
        </div>
        <p class="muted small">Whole numbers only. Up to 11 points show as buttons on phones; longer scales show as a slider.</p>
    <?php elseif ($inst['module'] === 'm7'): ?>
        <label for="opts">Options (one per line)</label>
        <textarea id="opts" name="options" style="min-height:140px"><?= h(implode("\n", $cfg['options'])) ?></textarea>
        <p class="muted small">Renaming an option starts its count again; answers to the old wording stay in the export.</p>
        <label class="check"><input type="checkbox" name="multi" value="1" <?= $cfg['multi'] ? 'checked' : '' ?>> People can pick more than one option</label>
        <div class="grid2">
          <div><label for="mp">Most options one person can pick (0 = no limit)</label><input type="number" id="mp" name="max_picks" min="0" max="30" value="<?= (int) $cfg['max_picks'] ?>"></div>
        </div>
        <label class="check"><input type="checkbox" name="allow_other" value="1" <?= $cfg['allow_other'] ? 'checked' : '' ?>> Offer an "Other" answer with a text box</label>
        <label for="ol">Name of the "Other" answer</label>
        <input type="text" id="ol" name="other_label" value="<?= h($cfg['other_label']) ?>">
        <p class="muted small">"Other" texts wait for your approval on the remote, as in the text module. They count straight away; approval only decides whether the text is shown on the screen.</p>
    <?php else: ?>
        <div class="grid2">
          <div><label for="nmin">Smallest number allowed (blank = no limit)</label><input type="number" id="nmin" name="min" step="any" value="<?= h($num($cfg['min'])) ?>"></div>
          <div><label for="nmax">Largest number allowed (blank = no limit)</label><input type="number" id="nmax" name="max" step="any" value="<?= h($num($cfg['max'])) ?>"></div>
          <div><label for="ndec">Decimal places allowed</label><input type="number" id="ndec" name="decimals" min="0" max="3" value="<?= (int) $cfg['decimals'] ?>"></div>
          <div><label for="nunit">Unit (optional, e.g. MW, years, %)</label><input type="text" id="nunit" name="unit" value="<?= h($cfg['unit']) ?>"></div>
        </div>
        <p class="muted small">Phones refuse numbers outside these limits.</p>
    <?php endif; ?>
        <p class="muted small">On the answers screen: the bar runs from the lowest to the highest answer, the box covers the middle 80% of answers (10th to 90th percentile), and the white line marks the average.</p>
    <?php
}

function render_sv_submissions(array $inst): void
{
    $res = sv_results($inst);
    echo '<div class="section">Answers (' . (int) $res['people'] . ')</div>';
    if ($inst['module'] === 'm7') {
        echo '<table class="data" style="margin-top:12px"><tr><th>Option</th><th>Chosen</th></tr>';
        foreach ($res['choices'] as $c) {
            echo '<tr><td class="txt">' . h($c['label']) . '</td><td>' . (int) $c['count'] . '</td></tr>';
        }
        echo '</table>';
        $rows = q("SELECT updated_at, body, status FROM submissions WHERE instance_id = ? AND body <> '' ORDER BY updated_at DESC LIMIT 500", [$inst['id']])->fetchAll();
        if ($rows) {
            echo '<p class="small muted" style="margin-top:16px">"' . h($inst['config']['other_label']) . '" texts. Approve them on the remote.</p>';
            echo '<table class="data"><tr><th>When (UTC)</th><th>Text</th><th>Status</th></tr>';
            foreach ($rows as $r) {
                echo '<tr><td>' . h(substr($r['updated_at'], 5, 11)) . '</td><td class="txt">' . h($r['body']) . '</td><td>' . h($r['status']) . '</td></tr>';
            }
            echo '</table>';
        }
        return;
    }
    $st = $res['stats'];
    if (!$st['n']) {
        echo '<p class="muted">Nothing yet.</p>';
        return;
    }
    $d = $inst['module'] === 'm6' ? 1 : (int) $inst['config']['decimals'] + 1;
    echo '<table class="data" style="margin-top:12px"><tr><th>Answers</th><th>Average</th><th>Lowest</th><th>10th percentile</th><th>90th percentile</th><th>Highest</th></tr><tr>';
    echo '<td>' . (int) $st['n'] . '</td>';
    foreach (['mean', 'min', 'p10', 'p90', 'max'] as $k) {
        echo '<td>' . h(sv_fmt((float) $st[$k], $d)) . '</td>';
    }
    echo '</tr></table>';
}
