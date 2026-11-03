<?php
// JSON API polled by the attendee phones, the big screen and the remote.
//   GET  ?a=phone&c=CODE      attendee state          POST ?a=m1_answer|submit&c=CODE
//   GET  ?a=screen&c=CODE     big screen state
//   GET  ?a=remote&c=CODE     remote state (admin)    POST ?a=next|prev|goto|hide|open|moderate (admin)

declare(strict_types=1);

require __DIR__ . '/../src/model.php';

try {
    $action = (string) ($_GET['a'] ?? '');
    $event = event_by_code((string) ($_GET['c'] ?? ''));
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
            handle_phone($event, $action, $input);
            break;
        case 'screen':
            // Short long-poll: when the screen says which slide it shows, hold the request (max ~1.5 s)
            // until the slide changes, so a click shows up on screen almost at once.
            if (isset($_GET['wait'])) {
                $wait = (int) $_GET['wait'];
                for ($i = 0; $i < 12 && (int) ($event['current_slide_id'] ?? 0) === $wait; $i++) {
                    usleep(120000);
                    $event = event_by_id((int) $event['id']);
                }
            }
            json_out(screen_state($event));
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
                if (!empty($_GET['lite'])) {
                    json_out(['ok' => true]); // laptop keys/clicks: the screen picks the change up itself
                }
                $event = event_by_id((int) $event['id']);
            }
            json_out(remote_state($event));
    }
} catch (Throwable $e) {
    error_log('[imasons api] ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    json_out(['error' => 'server_error'], 500);
}

// ---------------------------------------------------------------- attendee phone

function handle_phone(array $event, string $action, array $input): void
{
    $cookie = 'imp_' . $event['code'];
    $p = participant_for($event, $_COOKIE[$cookie] ?? null);
    if (($_COOKIE[$cookie] ?? '') !== $p['token']) {
        setcookie($cookie, $p['token'], [
            'expires' => time() + 60 * 60 * 24 * 30, 'path' => '/',
            'secure' => is_https(), 'httponly' => true, 'samesite' => 'Lax',
        ]);
    }
    $pid = (int) $p['id'];

    $instance = live_slide_with_instance($event);
    if ($instance && !$instance['is_open']) {
        $instance = null;
    }

    $collAll = collateral_instance($event);
    $coll = ($collAll && $collAll['is_open']) ? $collAll : null; // accepting sign-ups

    $notice = null;
    if ($action === 'collateral') {
        // From the email banner: works whatever is live, as long as the collateral request is on and open.
        if (!$coll) {
            $notice = 'Sign-up for the materials is closed. Thank you!';
        } else {
            $err = save_submission($coll, $pid, $input);
            if ($err !== null) {
                json_out(['error' => 'validation', 'message' => $err], 422);
            }
        }
    } elseif ($action !== 'phone') {
        $target = (int) ($input['instance_id'] ?? 0);
        if (!$instance || (int) $instance['id'] !== $target) {
            $notice = 'This activity has just closed. Thank you!';
        } elseif ($action === 'm1_answer') {
            m1_save_answer($instance, $pid, (int) ($input['statement_id'] ?? 0), (int) ($input['version'] ?? 0),
                (string) ($input['answer'] ?? ''));
        } else {
            $err = save_submission($instance, $pid, $input);
            if ($err !== null) {
                json_out(['error' => 'validation', 'message' => $err], 422);
            }
        }
    }

    touch_participant($p, $instance ? (int) $instance['id'] : null);
    $out = ['event' => $event['name'], 'notice' => $notice, 'instance' => null, 'collateral' => null];
    if ($collAll) {
        $signedUp = (bool) submission_for((int) $collAll['id'], $pid);
        // Banner: open form while sign-ups are open; greyed confirmation for anyone already signed up.
        if ($coll || $signedUp) {
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
        [$next, $answeredCount] = m1_next_statement($inst, $pid);
        $v['intro'] = $cfg['intro'];
        $v['answered'] = $answeredCount;
        $v['statement'] = $next ? ['id' => $next['id'], 'version' => $next['version'], 'text' => $next['text']] : null;
        return $v;
    }
    $sub = submission_for((int) $inst['id'], $pid);
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

    if ($inst['module'] === 'm2') {
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
    $s = ['id' => $slide['id'], 'kind' => $slide['kind'], 'module' => $inst['module'], 'title' => $inst['title'],
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
    $m2ids = array_map(function ($i) { return (int) $i['id']; }, array_filter($all, function ($i) { return $i['module'] === 'm2'; }));
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
        } elseif ($inst['module'] === 'm2') {
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
