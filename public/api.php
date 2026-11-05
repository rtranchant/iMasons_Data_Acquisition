<?php
// JSON API polled by the attendee phones, the big screen and the remote.
//   GET  ?a=phone&c=CODE      attendee state          POST ?a=m1_answer|submit|collateral&c=CODE
//   GET  ?a=screen&c=CODE     big screen state
//   GET  ?a=remote&c=CODE     remote state (admin)    POST ?a=next|prev|goto|hide|open|moderate (admin)

declare(strict_types=1);

require __DIR__ . '/../src/model.php';

try {
    $action = (string) ($_GET['a'] ?? '');
    $code = (string) ($_GET['c'] ?? '');
    $participant = null;
    if (in_array($action, ['phone', 'm1_answer', 'submit', 'collateral', 'qf_profile', 'qf_answer'], true)) {
        [$event, $participant] = phone_context($code, $_COOKIE['imp_' . strtoupper(trim($code))] ?? null);
    } else {
        $event = event_by_code($code);
    }
    if (!$event) {
        json_out(['error' => 'event_not_found'], 404);
    }
    $isPost = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
    $input = $isPost ? (json_decode((string) file_get_contents('php://input'), true) ?: []) : [];

    switch ($action) {
        case 'phone':
        case 'm1_answer':
        case 'submit':
        case 'collateral':
        case 'qf_profile':
        case 'qf_answer':
            handle_phone($event, $participant, $action, $input);
            break;
        case 'screen':
            // Short request, answered at once. If nothing changed since the screen's last copy,
            // only a tiny "same" reply is sent.
            // Export: an admin can ask for any one slide (?preview=slide id) without changing what the room sees.
            if (isset($_GET['preview']) && is_admin()) {
                $event['current_slide_id'] = (int) $_GET['preview'];
            }
            json_out_unless_same(screen_state($event));
            break;
        default:
            require_admin(true);
            if ($isPost) {
                if (!hash_equals(csrf_token(), (string) ($_SERVER['HTTP_X_CSRF'] ?? ''))) {
                    json_out(['error' => 'bad_csrf'], 400);
                }
            }
            // Release the session lock right away so the host's other requests never queue behind this one.
            session_write_close();
            if ($isPost) {
                remote_action($event, $action, $input);
                event_bump((int) $event['id']);
                if (!empty($_GET['lite'])) {
                    json_out(['ok' => true]); // laptop keys/clicks: the screen picks the change up itself
                }
                $event = event_by_id((int) $event['id']);
            }
            json_out_unless_same(remote_state($event));
    }
} catch (Throwable $e) {
    error_log('[imasons api] ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    if (db_busy_error($e)) {
        header('Retry-After: 3');
        json_out(['error' => 'busy'], 503);   // phones, screen and remote all wait and try again
    }
    json_out(['error' => 'server_error'], 500);
}

// ---------------------------------------------------------------- attendee phone
// Phones never hold a connection open. They make short requests and get a small reply:
//   GET  phone       what is live now (every 10 s, and when the attendee taps or comes back to the page)
//   POST m1_answer   one Yes/No/Don't know answer       → {ok}
//   POST submit      a module 2/3/4 form                → {ok} or a validation message
//   POST collateral  the email banner form              → {ok} or a validation message

function handle_phone(array $event, array $p, string $action, array $input): void
{
    header('Connection: close');   // nothing stays open between a phone and the server
    $cookie = 'imp_' . $event['code'];
    if (($_COOKIE[$cookie] ?? '') !== $p['token']) {
        setcookie($cookie, $p['token'], [
            'expires' => time() + 60 * 60 * 24 * 30, 'path' => '/',
            'secure' => is_https(), 'httponly' => true, 'samesite' => 'Lax',
        ]);
    }
    $pid = (int) $p['id'];

    if ($action === 'qf_profile') {
        qf_save_profile($event, $pid, $input);
        json_out(['ok' => true]);
    }
    if ($action === 'qf_answer') {
        // Quick-fire: one write per answer, grouped when several were waiting on the phone.
        $answers = isset($input['answers']) && is_array($input['answers']) ? $input['answers'] : [$input];
        qf_save_answers((int) $event['id'], (int) ($input['instance_id'] ?? 0), $pid, $answers);
        json_out(['ok' => true]);
    }
    if ($action === 'm1_answer') {
        // Only the write: one INSERT per answer. Accepted while the module is open, even if the screen
        // has moved on, so answers queued on a phone with poor Wi-Fi are not lost. A phone sends the
        // answers given while its previous request was still on its way together in one request.
        $answers = isset($input['answers']) && is_array($input['answers']) ? $input['answers'] : [$input];
        m1_save_answers((int) $event['id'], (int) ($input['instance_id'] ?? 0), $pid, $answers);
        json_out(['ok' => true]);
    }

    if ($action === 'submit' || $action === 'collateral') {
        if ($action === 'collateral') {
            $inst = collateral_instance($event);
        } else {
            $inst = instance_by_id((int) ($input['instance_id'] ?? 0));
            if ($inst && ((int) $inst['event_id'] !== (int) $event['id'] || !in_array($inst['module'], ['m2', 'm3', 'm4', 'm6', 'm7', 'm8'], true))) {
                $inst = null;
            }
        }
        if (!$inst || !$inst['is_open']) {
            json_out(['ok' => false, 'closed' => true, 'message' => 'This has just closed. Thank you!']);
        }
        $err = save_submission($inst, $pid, $input);
        if ($err !== null) {
            json_out(['ok' => false, 'error' => 'validation', 'message' => $err], 422);
        }
        json_out(['ok' => true]);
    }

    // Answers saved on the phone come with its regular check (POST), grouped by module and question set.
    if (!empty($input['answers']) && is_array($input['answers'])) {
        $groups = [];
        foreach (array_slice($input['answers'], 0, 100) as $item) {
            $kind = (string) ($item['a'] ?? '');
            $body = is_array($item['b'] ?? null) ? $item['b'] : [];
            if ($kind === 'm1_answer' || $kind === 'qf_answer') {
                $groups[$kind . ':' . (int) ($body['instance_id'] ?? 0)][] = $body;
            }
        }
        foreach ($groups as $k => $answers) {
            [$kind, $iid] = explode(':', $k);
            if ($kind === 'm1_answer') {
                m1_save_answers((int) $event['id'], (int) $iid, $pid, $answers);
            } else {
                qf_save_answers((int) $event['id'], (int) $iid, $pid, $answers);
            }
        }
    }

    // Read: what is live now.
    $instance = live_slide_with_instance($event);
    if ($instance && !$instance['is_open']) {
        $instance = null;
    }
    $collAll = collateral_instance($event);
    touch_participant($p, $instance ? (int) $instance['id'] : null);
    $checkS = max(2, min(60, (int) (app_config()['phone_check_s'] ?? PHONE_CHECK_S)));
    $out = ['event' => $event['name'], 'instance' => null, 'collateral' => null, 'check_s' => $checkS];
    if ($collAll) {
        $signedUp = (bool) submission_for((int) $collAll['id'], $pid);
        // Banner: open form while sign-ups are open; greyed confirmation for anyone already signed up.
        if ($collAll['is_open'] || $signedUp) {
            $out['collateral'] = [
                'id' => (int) $collAll['id'],
                'intro' => $collAll['config']['intro'],
                'consent' => $collAll['config']['consent'],
                'submitted' => $signedUp,
            ];
        }
    }
    if ($instance) {
        $out['instance'] = phone_view($instance, $pid);
    }
    json_out($out);
}

function phone_view(array $inst, int $pid): array
{
    $cfg = $inst['config'];
    $v = ['id' => (int) $inst['id'], 'module' => $inst['module'], 'title' => $inst['title']];
    if ($inst['module'] === 'm1') {
        // The phone gets every statement it can still answer and picks the order itself,
        // so a swipe never has to wait for the server.
        [$pool, $answeredCount] = m1_phone_pool($inst, $pid);
        $v['intro'] = $cfg['intro'];
        $v['answered'] = $answeredCount;
        $v['pool'] = $pool;
        return $v;
    }
    if ($inst['module'] === 'm5') {
        return $v + qf_phone_view($inst, $pid, (string) ($_GET['qh'] ?? ''));
    }
    $sub = submission_for((int) $inst['id'], $pid);
    if (in_array($inst['module'], SV_MODULES, true)) {
        return $v + sv_phone_view($inst, $sub);
    }
    $v['submitted'] = (bool) $sub;
    if ($inst['module'] === 'm2') {
        $v['prompt'] = $cfg['prompt'];
        $v['max_length'] = (int) $cfg['max_length'];
        $v['questions'] = $cfg['questions'];
        $v['mine'] = $sub ? $sub['body'] : null;
    } else {
        $v['intro'] = $cfg['intro'];
        $v['consent'] = $cfg['consent'];
        if ($inst['module'] === 'm4') {
            $v['pledge'] = $cfg['pledge'];
        }
    }
    return $v;
}

/** Validate and store a module 2/3/4 submission. Returns an error message or null. */
function save_submission(array $inst, int $pid, array $in): ?string
{
    $cfg = $inst['config'];
    $name = $email = $body = null;
    $answers = [];
    $consent = 0;
    $status = 'approved';

    if (in_array($inst['module'], SV_MODULES, true)) {
        $r = sv_validate($inst, $in);
        if (is_string($r)) {
            return $r;
        }
        [$answers, $body, $status] = $r;
    } elseif ($inst['module'] === 'm2') {
        $body = str_multiline((string) ($in['body'] ?? ''), max(20, (int) $cfg['max_length']));
        if ($body === '') {
            return 'Please write something first.';
        }
        foreach ($cfg['questions'] as $i => $question) {
            $choice = (string) ($in['answers'][$i] ?? '');
            if ($choice !== '' && !in_array($choice, $question['options'], true)) {
                return 'Please pick one of the options.';
            }
            $answers[] = ['q' => $question['q'], 'a' => $choice];
        }
        $status = 'pending'; // waits for approval on the remote
    } else {
        $name = str_limit((string) ($in['name'] ?? ''), 200);
        $email = trim((string) ($in['email'] ?? ''));
        if ($name === '') {
            return 'Please enter your full name.';
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 254) {
            return 'Please enter a valid email address.';
        }
        if (empty($in['consent'])) {
            return 'Please tick the box to agree before submitting.';
        }
        $consent = 1;
    }

    q('INSERT INTO submissions (instance_id, participant_id, full_name, email, body, answers, consent, status, created_at, updated_at)
       VALUES (?,?,?,?,?,?,?,?,UTC_TIMESTAMP(),UTC_TIMESTAMP())
       ON DUPLICATE KEY UPDATE full_name = VALUES(full_name), email = VALUES(email), body = VALUES(body),
           answers = VALUES(answers), consent = VALUES(consent), status = VALUES(status), updated_at = UTC_TIMESTAMP()',
        [$inst['id'], $pid, $name, $email, $body, json_encode($answers, JSON_UNESCAPED_UNICODE), $consent, $status]);
    return null;
}

// ---------------------------------------------------------------- big screen

function screen_state(array $event): array
{
    $slides = event_slides((int) $event['id']);
    $slide = current_slide($event, $slides);
    $next = next_visible_slide($event, $slides);
    $out = [
        'event' => $event['name'],
        'join_url' => app_url('e.php?c=' . $event['code']),
        'code' => $event['code'],
        'raw_slide_id' => (int) ($event['current_slide_id'] ?? 0),
        'preload' => neighbour_images($event, $slides),
        // The visible run of show in order, so the laptop can move between uploaded slides instantly.
        'nav' => array_values(array_map(function ($s) { return ['id' => $s['id'], 'image' => $s['image']]; },
            array_filter($slides, function ($s) { return $s['visible']; }))),
        'slide' => null,
    ];
    if (!$slide) {
        return $out;
    }
    if ($slide['kind'] === 'image') {
        $out['slide'] = ['id' => $slide['id'], 'kind' => 'image', 'module' => 'deck', 'title' => $slide['title'], 'image' => $slide['image']];
        return $out;
    }
    $inst = instance_by_id($slide['instance_id']);
    $cfg = $inst['config'];
    $s = ['id' => $slide['id'], 'instance_id' => (int) $inst['id'], 'kind' => $slide['kind'], 'module' => $inst['module'], 'title' => $inst['title'],
          'is_open' => $inst['is_open']];

    if ($slide['kind'] === 'collect') {
        $s['dots'] = instance_dots($inst);
    }
    switch ($inst['module'] . ':' . $slide['kind']) {
        case 'm1:collect':
            $stats = m1_stats($inst);
            $s['total_answers'] = array_sum(array_column($stats, 'n'));
            break;
        case 'm1:results':
            $s['yes'] = $s['no'] = $s['revise'] = [];
            foreach (m1_stats($inst) as $st) {
                if ($st['status'] === 'open') {
                    continue;
                }
                $s[$st['status']][] = ['text' => $st['text'], 'yes' => $st['yes'], 'no' => $st['no'],
                                       'dk' => $st['dk'], 'n' => $st['n']];
            }
            usort($s['yes'], function ($a, $b) { return $b['yes'] / $b['n'] <=> $a['yes'] / $a['n']; });
            usort($s['no'], function ($a, $b) { return $b['no'] / $b['n'] <=> $a['no'] / $a['n']; });
            $s['pending'] = count(array_filter(m1_stats($inst), function ($st) { return $st['status'] === 'open'; }));
            break;
        case 'm2:collect':
        case 'm2:wall':
            $s['prompt'] = $cfg['prompt'];
            $s['statements'] = array_map(function ($r) { return ['id' => (int) $r['id'], 'text' => $r['body']]; },
                approved_statements((int) $inst['id']));
            break;
        case 'm3:collect':
            $s['intro'] = $cfg['intro'];
            break;
        case 'm4:collect':
            $s['pledge'] = $cfg['pledge'];
            $s['intro'] = $cfg['intro'];
            break;
        case 'm5:collect':
            // Participation only: no statement text and no ranking, so the screen doesn't steer answers.
            $s['live'] = qf_live_stats($inst);
            $s['min_n'] = (int) $cfg['min_n'];
            break;
        case 'm6:collect':
        case 'm7:collect':
        case 'm8:collect':
            $s['prompt'] = $cfg['prompt'];
            break;
        case 'm6:results':
        case 'm7:results':
        case 'm8:results':
            $s['prompt'] = $cfg['prompt'];
            $s['results'] = sv_results($inst);
            break;
        case 'm5:results':
            $s['results'] = qf_results($inst);
            $s['tab'] = max(1, min(4, (int) $cfg['tab']));
            break;
    }
    $out['slide'] = $s;
    return $out;
}

// ---------------------------------------------------------------- remote

function remote_action(array $event, string $action, array $in): void
{
    $eid = (int) $event['id'];
    switch ($action) {
        case 'next':
            step_slide($event, 1);
            break;
        case 'prev':
            step_slide($event, -1);
            break;
        case 'goto':
            $id = (int) ($in['slide_id'] ?? 0);
            if (q('SELECT 1 FROM slides WHERE id = ? AND event_id = ?', [$id, $eid])->fetchColumn()) {
                set_current_slide($eid, $id);
            }
            break;
        case 'blank':
            set_current_slide($eid, null);
            break;
        case 'hide':
            q('UPDATE slides SET hidden = ? WHERE id = ? AND event_id = ?',
                [empty($in['hidden']) ? 0 : 1, (int) ($in['slide_id'] ?? 0), $eid]);
            break;
        case 'open':
            q('UPDATE instances SET is_open = ? WHERE id = ? AND event_id = ?',
                [empty($in['open']) ? 0 : 1, (int) ($in['instance_id'] ?? 0), $eid]);
            break;
        case 'qf_tab':
        case 'qf_view':
            $inst = instance_by_id((int) ($in['instance_id'] ?? 0));
            if ($inst && $inst['module'] === 'm5' && (int) $inst['event_id'] === $eid) {
                $cfg = $inst['config'];
                if ($action === 'qf_tab') {
                    $cfg['tab'] = max(1, min(4, (int) ($in['tab'] ?? 1)));
                } elseif (in_array((string) ($in['side'] ?? ''), ['hiring', 'candidate', 'education', 'all'], true)) {
                    $cfg['view_side'] = (string) $in['side'];
                }
                save_instance_config((int) $inst['id'], $cfg);
            }
            break;
        case 'moderate':
            $status = (string) ($in['status'] ?? '');
            if (in_array($status, ['approved', 'hidden', 'pending'], true)) {
                q('UPDATE submissions s JOIN instances i ON i.id = s.instance_id
                   SET s.status = ? WHERE s.id = ? AND i.event_id = ?', [$status, (int) ($in['submission_id'] ?? 0), $eid]);
            }
            break;
        default:
            json_out(['error' => 'unknown_action'], 400);
    }
}

function remote_state(array $event): array
{
    $slides = event_slides((int) $event['id']);
    $current = current_slide($event, $slides);
    $next = next_visible_slide($event, $slides);
    $instances = [];
    $all = event_instances((int) $event['id']);
    $dots = event_dots((int) $event['id']);
    // All text-input statements to moderate, in one query.
    $m2ids = array_map(function ($i) { return (int) $i['id']; }, array_filter($all, function ($i) { return in_array($i['module'], ['m2', 'm7'], true); }));
    $m2subs = [];
    if ($m2ids) {
        $rows = q("SELECT id, instance_id, body, status FROM submissions
                   WHERE instance_id IN (" . implode(',', $m2ids) . ") AND status IN ('pending', 'approved') AND body <> ''
                   ORDER BY created_at")->fetchAll();
        foreach ($rows as $r) {
            $m2subs[(int) $r['instance_id']][$r['status']][] = ['id' => (int) $r['id'], 'body' => $r['body']];
        }
    }
    foreach ($all as $inst) {
        $i = ['id' => (int) $inst['id'], 'module' => $inst['module'], 'title' => $inst['title'],
              'is_open' => $inst['is_open'], 'dots' => $dots[(int) $inst['id']] ?? ['done' => 0, 'watching' => 0]];
        if ($inst['module'] === 'm1') {
            $stats = m1_stats($inst);
            $i['statements'] = array_map(function ($s) {
                return ['text' => $s['text'], 'yes' => $s['yes'], 'no' => $s['no'], 'dk' => $s['dk'], 'status' => $s['status']];
            }, $stats);
            $i['total_answers'] = array_sum(array_column($stats, 'n'));
        } elseif ($inst['module'] === 'm5') {
            $live = qf_live_stats($inst);
            $i['qf'] = ['people' => $live['people'], 'by_side' => $live['by_side'], 'answers' => $live['answers'],
                        'coverage' => $live['active'] ? round(100 * $live['reached'] / $live['active']) : 0,
                        'tab' => (int) $inst['config']['tab'], 'side' => $inst['config']['view_side']];
        } elseif ($inst['module'] === 'm2' || $inst['module'] === 'm7') {
            $i['pending'] = $m2subs[(int) $inst['id']]['pending'] ?? [];
            $i['approved'] = array_reverse($m2subs[(int) $inst['id']]['approved'] ?? []);
        }
        $instances[] = $i;
    }
    return [
        'event' => $event['name'],
        'online' => online_count((int) $event['id']),
        'current_slide_id' => $current ? $current['id'] : null,
        'next_slide_id' => $next ? $next['id'] : null,
        'slides' => array_map(function ($s) {
            return ['id' => $s['id'], 'instance_id' => $s['instance_id'], 'module' => $s['module'], 'title' => $s['title'],
                    'label' => $s['label'], 'kind' => $s['kind'], 'hidden' => $s['hidden'], 'thumb' => $s['thumb'],
                    'hidden_by_parent' => $s['hidden_by_parent'], 'visible' => $s['visible']];
        }, $slides),
        'instances' => $instances,
    ];
}
