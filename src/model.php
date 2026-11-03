<?php
// Domain logic shared by the admin, remote, screen and attendee pages.

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

/** Seconds since a phone's last poll for it to count as "in the room". */
const ONLINE_WINDOW = 20;

const MODULES = [
    'm1' => [
        'name'   => "Yes / No / Don't know",
        'short'  => 'Statements',
        'slides' => ['collect' => 'Live participation', 'results' => 'Results'],
    ],
    'm2' => [
        'name'   => 'Text input',
        'short'  => 'Text input',
        'slides' => ['collect' => 'Live statements', 'wall' => 'Statement wall'],
    ],
    'm3' => [
        'name'   => 'Send me the collateral',
        'short'  => 'Collateral',
        'event_toggle' => true, // one per event, switched on in the event settings
        'slides' => ['collect' => 'Sign-ups'],
    ],
    'm4' => [
        'name'   => 'Pledge – get me connected',
        'short'  => 'Pledge',
        'slides' => ['collect' => 'Pledges'],
    ],
];

function default_config(string $module): array
{
    switch ($module) {
        case 'm1':
            return [
                'intro'       => 'Tell us where you stand. Answer as many statements as you like.',
                'min_answers' => 8,
                'threshold'   => 0.75,
            ];
        case 'm2':
            return [
                'prompt'     => 'Write the newspaper headline you would like to read about our industry in 2030.',
                'max_length' => 120,
                'questions'  => [],
            ];
        case 'm3':
            return [
                'intro'   => "Leave your details and we'll send you the materials created in this session.",
                'consent' => 'I agree that iMasons may store my name and email address to send me these materials.',
            ];
        case 'm4':
            return [
                'pledge'  => 'I commit to ...',
                'intro'   => 'Make yourself known so others committed to this pledge can reach out.',
                'consent' => 'I agree that iMasons may store my name and email address and share them with fellow attendees about this pledge.',
            ];
    }
    throw new InvalidArgumentException('Unknown module');
}

// ---------------------------------------------------------------- events & instances

function event_by_code(string $code): ?array
{
    $row = q('SELECT * FROM events WHERE code = ?', [strtoupper(trim($code))])->fetch();
    return $row ?: null;
}

function event_by_id(int $id): ?array
{
    $row = q('SELECT * FROM events WHERE id = ?', [$id])->fetch();
    return $row ?: null;
}

function decode_instance(array $row): array
{
    $cfg = json_decode((string) $row['config'], true);
    $row['config'] = array_merge(default_config($row['module']), is_array($cfg) ? $cfg : []);
    $row['is_open'] = (bool) $row['is_open'];
    return $row;
}

function instance_by_id(int $id): ?array
{
    $row = q('SELECT * FROM instances WHERE id = ?', [$id])->fetch();
    return $row ? decode_instance($row) : null;
}

function event_instances(int $eventId): array
{
    $rows = q('SELECT * FROM instances WHERE event_id = ? ORDER BY id', [$eventId])->fetchAll();
    return array_map('decode_instance', $rows);
}

function create_instance(int $eventId, string $module, string $title, ?int $afterSlideId = null): int
{
    if (!isset(MODULES[$module])) {
        throw new InvalidArgumentException('Unknown module');
    }
    q('INSERT INTO instances (event_id, module, title, config, is_open, created_at) VALUES (?,?,?,?,1,UTC_TIMESTAMP())',
        [$eventId, $module, $title, json_encode(default_config($module), JSON_UNESCAPED_UNICODE)]);
    $id = (int) db()->lastInsertId();
    $new = [];
    foreach (array_keys(MODULES[$module]['slides']) as $kind) {
        $new[] = ['kind' => $kind, 'instance_id' => $id];
    }
    insert_slides($eventId, $afterSlideId, $new);
    return $id;
}

function save_instance_config(int $instanceId, array $config): void
{
    q('UPDATE instances SET config = ? WHERE id = ?', [json_encode($config, JSON_UNESCAPED_UNICODE), $instanceId]);
}

// ---------------------------------------------------------------- run of show

/**
 * All slides of an event in order, with instance details and visibility.
 * A results/wall slide is effectively hidden when its collection slide is hidden.
 */
function event_slides(int $eventId): array
{
    $rows = q('SELECT s.*, i.module, i.title, i.is_open,
                      p.page_no, p.pkey, d.id AS deck_id, d.name AS deck_name
               FROM slides s
               LEFT JOIN instances i ON i.id = s.instance_id
               LEFT JOIN deck_pages p ON p.id = s.page_id
               LEFT JOIN decks d ON d.id = p.deck_id
               WHERE s.event_id = ? ORDER BY s.position, s.id', [$eventId])->fetchAll();
    $collectHidden = [];
    foreach ($rows as $r) {
        if ($r['kind'] === 'collect') {
            $collectHidden[$r['instance_id']] = (bool) $r['hidden'];
        }
    }
    foreach ($rows as &$r) {
        $r['id'] = (int) $r['id'];
        $r['instance_id'] = (int) $r['instance_id'];
        $r['hidden'] = (bool) $r['hidden'];
        $r['is_open'] = (bool) $r['is_open'];
        if ($r['kind'] === 'image') {
            $r['module'] = 'deck';
            $r['title'] = $r['deck_name'];
            $r['label'] = 'Slide ' . $r['page_no'];
            $r['deck_id'] = (int) $r['deck_id'];
            $r['image'] = page_url((int) $r['page_id'], $r['pkey']);
            $r['thumb'] = page_url((int) $r['page_id'], $r['pkey'], true);
            $r['hidden_by_parent'] = false;
        } else {
            $r['label'] = MODULES[$r['module']]['slides'][$r['kind']] ?? $r['kind'];
            $r['image'] = $r['thumb'] = null;
            $r['hidden_by_parent'] = $r['kind'] !== 'collect' && !empty($collectHidden[$r['instance_id']]);
        }
        $r['visible'] = !$r['hidden'] && !$r['hidden_by_parent'];
    }
    unset($r);
    return $rows;
}

/** The slide currently live on screen, or null if none / it has been hidden. */
function current_slide(array $event, ?array $slides = null): ?array
{
    if (empty($event['current_slide_id'])) {
        return null;
    }
    $slides = $slides ?? event_slides((int) $event['id']);
    foreach ($slides as $s) {
        if ($s['id'] === (int) $event['current_slide_id']) {
            return $s['visible'] ? $s : null;
        }
    }
    return null;
}

/** Move to the next/previous visible slide, skipping hidden ones. */
function step_slide(array $event, int $direction): void
{
    $slides = event_slides((int) $event['id']);
    $visible = array_values(array_filter($slides, function ($s) { return $s['visible']; }));
    if (!$visible) {
        set_current_slide((int) $event['id'], null);
        return;
    }
    $currentId = (int) ($event['current_slide_id'] ?? 0);
    // Position of the current slide among ALL slides, so stepping from a hidden one still works.
    $currentIndexAll = null;
    foreach ($slides as $i => $s) {
        if ($s['id'] === $currentId) {
            $currentIndexAll = $i;
        }
    }
    if ($currentIndexAll === null) {
        $target = $direction > 0 ? $visible[0] : $visible[count($visible) - 1];
        set_current_slide((int) $event['id'], $target['id']);
        return;
    }
    $n = count($slides);
    for ($i = $currentIndexAll + $direction; $i >= 0 && $i < $n; $i += $direction) {
        if ($slides[$i]['visible']) {
            set_current_slide((int) $event['id'], $slides[$i]['id']);
            return;
        }
    }
    // Already at the end (or start): stay put.
}

/** The next visible slide after the current one (for preloading on the big screen). */
function next_visible_slide(array $event, array $slides): ?array
{
    $found = empty($event['current_slide_id']);
    foreach ($slides as $s) {
        if ($found && $s['visible']) {
            return $s;
        }
        if ($s['id'] === (int) $event['current_slide_id']) {
            $found = true;
        }
    }
    return null;
}

/** Rewrite slide positions to match the given order of slide ids (unknown ids are ignored). */
function reorder_slides(int $eventId, array $orderedIds): void
{
    $existing = array_column(event_slides($eventId), 'id');
    $ordered = array_values(array_intersect(array_map('intval', $orderedIds), $existing));
    // Anything not mentioned keeps its relative place at the end.
    foreach ($existing as $id) {
        if (!in_array($id, $ordered, true)) {
            $ordered[] = $id;
        }
    }
    foreach ($ordered as $i => $id) {
        q('UPDATE slides SET position = ? WHERE id = ? AND event_id = ?', [($i + 1) * 10, $id, $eventId]);
    }
}

/**
 * Insert new slides after a given slide (or at the end when $afterSlideId is null, or at the start when 0).
 * Each new slide: ['kind' => ..., 'instance_id' => ?int, 'page_id' => ?int]. Returns new slide ids.
 */
function insert_slides(int $eventId, ?int $afterSlideId, array $newSlides): array
{
    $ids = [];
    foreach ($newSlides as $n) {
        q('INSERT INTO slides (event_id, instance_id, page_id, kind, position, hidden) VALUES (?,?,?,?,0,0)',
            [$eventId, $n['instance_id'] ?? null, $n['page_id'] ?? null, $n['kind']]);
        $ids[] = (int) db()->lastInsertId();
    }
    $order = [];
    $inserted = false;
    if ($afterSlideId === 0) {
        $order = $ids;
        $inserted = true;
    }
    foreach (event_slides($eventId) as $s) {
        if (in_array($s['id'], $ids, true)) {
            continue;
        }
        $order[] = $s['id'];
        if (!$inserted && $afterSlideId !== null && $s['id'] === $afterSlideId) {
            $order = array_merge($order, $ids);
            $inserted = true;
        }
    }
    if (!$inserted) {
        $order = array_merge($order, $ids);
    }
    reorder_slides($eventId, $order);
    return $ids;
}

function set_current_slide(int $eventId, ?int $slideId): void
{
    q('UPDATE events SET current_slide_id = ? WHERE id = ?', [$slideId, $eventId]);
}

/** Swap a slide with its neighbour in the run of show. */
function move_slide(int $eventId, int $slideId, int $direction): void
{
    $slides = event_slides($eventId);
    // Normalise positions so swaps are clean.
    foreach ($slides as $i => $s) {
        q('UPDATE slides SET position = ? WHERE id = ?', [($i + 1) * 10, $s['id']]);
    }
    foreach ($slides as $i => $s) {
        if ($s['id'] === $slideId) {
            $j = $i + $direction;
            if ($j < 0 || $j >= count($slides)) {
                return;
            }
            q('UPDATE slides SET position = ? WHERE id = ?', [($j + 1) * 10, $s['id']]);
            q('UPDATE slides SET position = ? WHERE id = ?', [($i + 1) * 10, $slides[$j]['id']]);
            return;
        }
    }
}

// ---------------------------------------------------------------- collateral (one per event)

/** The event's collateral instance when the toggle is on, else null. */
function collateral_instance(array $event): ?array
{
    if (empty($event['collateral_on']) || empty($event['collateral_instance_id'])) {
        return null;
    }
    return instance_by_id((int) $event['collateral_instance_id']);
}

/**
 * Switch the collateral request on (adds its page at the end of the run of show)
 * or off (removes the page; the instance and its sign-ups are kept).
 */
function set_collateral(array $event, bool $on): void
{
    $eid = (int) $event['id'];
    $inst = !empty($event['collateral_instance_id']) ? instance_by_id((int) $event['collateral_instance_id']) : null;
    if ($on) {
        if (!$inst) {
            $id = create_instance($eid, 'm3', 'Get the materials');
            q('UPDATE events SET collateral_instance_id = ? WHERE id = ?', [$id, $eid]);
        } elseif (!q('SELECT 1 FROM slides WHERE instance_id = ?', [$inst['id']])->fetchColumn()) {
            insert_slides($eid, null, [['kind' => 'collect', 'instance_id' => (int) $inst['id']]]);
        }
        q('UPDATE events SET collateral_on = 1 WHERE id = ?', [$eid]);
    } else {
        if ($inst) {
            q('DELETE FROM slides WHERE instance_id = ?', [$inst['id']]);
        }
        q('UPDATE events SET collateral_on = 0 WHERE id = ?', [$eid]);
    }
}

// ---------------------------------------------------------------- slide decks

function page_url(int $pageId, string $key, bool $thumb = false): string
{
    return 'media.php?p=' . $pageId . '&k=' . $key . ($thumb ? '&t=1' : '');
}

function event_decks(int $eventId): array
{
    return q('SELECT d.*, (SELECT COUNT(*) FROM deck_pages p WHERE p.deck_id = d.id AND p.batch = d.current_batch) AS pages
              FROM decks d WHERE d.event_id = ? AND d.current_batch > 0 ORDER BY d.created_at', [$eventId])->fetchAll();
}

/**
 * Make an uploaded batch of pages live.
 * New deck: one slide per page, inserted after $afterSlideId (null = end).
 * Revised deck: existing slides keep their place and now show the new page with the same number;
 * extra pages are added after the deck's last slide; slides for pages that no longer exist are removed.
 */
function deck_finish(array $deck, int $batch, ?int $afterSlideId): int
{
    $eventId = (int) $deck['event_id'];
    $pages = q('SELECT id, page_no FROM deck_pages WHERE deck_id = ? AND batch = ? ORDER BY page_no', [$deck['id'], $batch])->fetchAll();
    if (!$pages) {
        throw new RuntimeException('No pages were uploaded.');
    }
    $byNo = [];
    foreach ($pages as $p) {
        $byNo[(int) $p['page_no']] = (int) $p['id'];
    }
    $existing = q('SELECT s.id, p.page_no FROM slides s JOIN deck_pages p ON p.id = s.page_id
                   WHERE p.deck_id = ? ORDER BY s.position, s.id', [$deck['id']])->fetchAll();

    db()->beginTransaction();
    try {
        if (!$existing) {
            $new = [];
            foreach ($byNo as $pid) {
                $new[] = ['kind' => 'image', 'page_id' => $pid];
            }
            insert_slides($eventId, $afterSlideId, $new);
        } else {
            $have = [];
            $lastSlide = null;
            foreach ($existing as $e) {
                $no = (int) $e['page_no'];
                $lastSlide = (int) $e['id'];
                if (isset($byNo[$no])) {
                    q('UPDATE slides SET page_id = ? WHERE id = ?', [$byNo[$no], $e['id']]);
                    $have[$no] = true;
                } else {
                    q('DELETE FROM slides WHERE id = ?', [$e['id']]);
                }
            }
            $extra = [];
            foreach ($byNo as $no => $pid) {
                if (empty($have[$no])) {
                    $extra[] = ['kind' => 'image', 'page_id' => $pid];
                }
            }
            if ($extra) {
                // After the deck's last remaining slide (or the end if all were removed).
                $remaining = q('SELECT s.id FROM slides s JOIN deck_pages p ON p.id = s.page_id
                                WHERE p.deck_id = ? ORDER BY s.position DESC, s.id DESC LIMIT 1', [$deck['id']])->fetchColumn();
                insert_slides($eventId, $remaining ? (int) $remaining : null, $extra);
            }
        }
        q('DELETE FROM deck_pages WHERE deck_id = ? AND batch <> ?', [$deck['id'], $batch]);
        q('UPDATE decks SET current_batch = ?, updated_at = UTC_TIMESTAMP() WHERE id = ?', [$batch, $deck['id']]);
        db()->commit();
    } catch (Throwable $e) {
        db()->rollBack();
        throw $e;
    }
    return count($pages);
}

// ---------------------------------------------------------------- participants

function participant_for(array $event, ?string $token): array
{
    if (is_string($token) && preg_match('/^[a-f0-9]{32}$/', $token)) {
        $p = q('SELECT * FROM participants WHERE token = ? AND event_id = ?', [$token, $event['id']])->fetch();
        if ($p) {
            return $p;
        }
    }
    $token = bin2hex(random_bytes(16));
    q('INSERT INTO participants (event_id, token, last_seen, created_at) VALUES (?,?,UTC_TIMESTAMP(),UTC_TIMESTAMP())',
        [$event['id'], $token]);
    return q('SELECT * FROM participants WHERE id = ?', [db()->lastInsertId()])->fetch();
}

function touch_participant(int $participantId, ?int $instanceId): void
{
    q('UPDATE participants SET last_seen = UTC_TIMESTAMP(), seen_instance_id = ? WHERE id = ?', [$instanceId, $participantId]);
}

/**
 * Dots for the big screen: people who have contributed to this instance (green)
 * and phones currently looking at it that haven't contributed yet (grey).
 */
function instance_dots(array $instance): array
{
    $id = (int) $instance['id'];
    if ($instance['module'] === 'm1') {
        $contributors = q('SELECT DISTINCT a.participant_id FROM m1_answers a
                           JOIN m1_statements s ON s.id = a.statement_id
                           WHERE s.instance_id = ?', [$id])->fetchAll(PDO::FETCH_COLUMN);
    } else {
        $contributors = q('SELECT participant_id FROM submissions WHERE instance_id = ?', [$id])->fetchAll(PDO::FETCH_COLUMN);
    }
    $online = q('SELECT id FROM participants WHERE seen_instance_id = ? AND last_seen >= UTC_TIMESTAMP() - INTERVAL ? SECOND',
        [$id, ONLINE_WINDOW])->fetchAll(PDO::FETCH_COLUMN);
    $contributors = array_map('intval', $contributors);
    $watching = array_diff(array_map('intval', $online), $contributors);
    return ['done' => count($contributors), 'watching' => count($watching)];
}

function online_count(int $eventId): int
{
    return (int) q('SELECT COUNT(*) FROM participants WHERE event_id = ? AND last_seen >= UTC_TIMESTAMP() - INTERVAL ? SECOND',
        [$eventId, ONLINE_WINDOW])->fetchColumn();
}

// ---------------------------------------------------------------- module 1

/** Classify a statement: open (not enough answers), yes, no, or revise. */
function m1_classify(int $yes, int $no, int $dk, array $cfg): string
{
    $n = $yes + $no + $dk;
    if ($n < (int) $cfg['min_answers']) {
        return 'open';
    }
    $t = (float) $cfg['threshold'];
    if ($yes / $n >= $t) {
        return 'yes';
    }
    if ($no / $n >= $t) {
        return 'no';
    }
    return 'revise';
}

/** Per-statement counts for the current version of each active statement. */
function m1_stats(array $instance): array
{
    $rows = q("SELECT s.id, s.text, s.version, s.position,
                  SUM(a.answer = 'yes') AS yes, SUM(a.answer = 'no') AS no, SUM(a.answer = 'dk') AS dk
               FROM m1_statements s
               LEFT JOIN m1_answers a ON a.statement_id = s.id AND a.version = s.version
               WHERE s.instance_id = ? AND s.active = 1
               GROUP BY s.id, s.text, s.version, s.position
               ORDER BY s.position, s.id", [$instance['id']])->fetchAll();
    foreach ($rows as &$r) {
        $r['id'] = (int) $r['id'];
        $r['version'] = (int) $r['version'];
        $r['yes'] = (int) $r['yes'];
        $r['no'] = (int) $r['no'];
        $r['dk'] = (int) $r['dk'];
        $r['n'] = $r['yes'] + $r['no'] + $r['dk'];
        $r['status'] = m1_classify($r['yes'], $r['no'], $r['dk'], $instance['config']);
    }
    unset($r);
    return $rows;
}

/**
 * Next statement for an attendee: one they haven't answered (current version),
 * not yet decided Yes/No, preferring statements with the fewest answers.
 */
function m1_next_statement(array $instance, int $participantId): ?array
{
    $answered = array_flip(array_map(function ($r) { return $r['statement_id'] . ':' . $r['version']; },
        q('SELECT a.statement_id, a.version FROM m1_answers a JOIN m1_statements s ON s.id = a.statement_id
           WHERE s.instance_id = ? AND a.participant_id = ?', [$instance['id'], $participantId])->fetchAll()));
    $best = null;
    foreach (m1_stats($instance) as $s) {
        if ($s['status'] === 'yes' || $s['status'] === 'no' || isset($answered[$s['id'] . ':' . $s['version']])) {
            continue;
        }
        if ($best === null || $s['n'] < $best['n']) {
            $best = $s;
        }
    }
    return $best;
}

function m1_save_answer(array $instance, int $participantId, int $statementId, int $version, string $answer): void
{
    if (!in_array($answer, ['yes', 'no', 'dk'], true)) {
        throw new InvalidArgumentException('Bad answer');
    }
    $s = q('SELECT * FROM m1_statements WHERE id = ? AND instance_id = ? AND active = 1', [$statementId, $instance['id']])->fetch();
    if (!$s || (int) $s['version'] !== $version) {
        return; // statement was edited or removed meanwhile; ignore stale answer
    }
    q('INSERT IGNORE INTO m1_answers (statement_id, version, participant_id, answer, created_at)
       VALUES (?,?,?,?,UTC_TIMESTAMP())', [$statementId, $version, $participantId, $answer]);
}

/** Change a statement's wording: archives the old text and restarts its count. */
function m1_update_statement(int $statementId, string $text): void
{
    $s = q('SELECT * FROM m1_statements WHERE id = ?', [$statementId])->fetch();
    if (!$s || $s['text'] === $text) {
        return;
    }
    q('INSERT INTO m1_statement_history (statement_id, version, text, replaced_at) VALUES (?,?,?,UTC_TIMESTAMP())',
        [$statementId, $s['version'], $s['text']]);
    q('UPDATE m1_statements SET text = ?, version = version + 1 WHERE id = ?', [$text, $statementId]);
}

function m1_add_statements(int $instanceId, array $lines): void
{
    $pos = (int) q('SELECT COALESCE(MAX(position), 0) FROM m1_statements WHERE instance_id = ?', [$instanceId])->fetchColumn();
    foreach ($lines as $line) {
        $line = str_limit($line, 500);
        if ($line === '') {
            continue;
        }
        $pos += 10;
        q('INSERT INTO m1_statements (instance_id, position, text) VALUES (?,?,?)', [$instanceId, $pos, $line]);
    }
}

// ---------------------------------------------------------------- modules 2-4

function submission_for(int $instanceId, int $participantId): ?array
{
    $row = q('SELECT * FROM submissions WHERE instance_id = ? AND participant_id = ?', [$instanceId, $participantId])->fetch();
    return $row ?: null;
}

function approved_statements(int $instanceId): array
{
    return q("SELECT id, body FROM submissions WHERE instance_id = ? AND status = 'approved' AND body <> ''
              ORDER BY updated_at", [$instanceId])->fetchAll();
}

/** Parse the admin's question text: blocks separated by blank lines, first line = question, "- " lines = options. */
function parse_questions(string $text): array
{
    $out = [];
    foreach (preg_split('/\R\s*\R/', trim($text)) as $block) {
        $lines = array_values(array_filter(array_map('trim', preg_split('/\R/', $block)), 'strlen'));
        if (!$lines) {
            continue;
        }
        $q = ['q' => str_limit(ltrim($lines[0], '- '), 300), 'options' => []];
        foreach (array_slice($lines, 1) as $l) {
            $opt = str_limit(ltrim($l, '-* '), 200);
            if ($opt !== '') {
                $q['options'][] = $opt;
            }
        }
        if ($q['q'] !== '' && count($q['options']) >= 2) {
            $out[] = $q;
        }
    }
    return $out;
}

function questions_to_text(array $questions): string
{
    $blocks = [];
    foreach ($questions as $q) {
        $blocks[] = $q['q'] . "\n" . implode("\n", array_map(function ($o) { return '- ' . $o; }, $q['options']));
    }
    return implode("\n\n", $blocks);
}
