<?php
// Module 5: quick-fire cards. Yes always means "this is a problem that stops people getting hired".
// The calculations live in qf_compute(), a pure function covered by tests/qf_compute_test.php.

declare(strict_types=1);

const QF_STAGES = ['Awareness', 'Readiness', 'Matching', 'Screening', 'Selection', 'Clearance', 'Start', 'Growth and retention'];

const QF_SIDES = [
    'hiring'    => ['I hire, recruit or manage people', 'Hiring side'],
    'candidate' => ["I'm looking for a role, or got one in the last 2 years", 'Candidate side'],
    'education' => ['I train or educate people', 'Education side'],
    'other'     => ['Other', 'Other'],
];

const QF_SEGMENTS = ['Veteran or service member', 'Military spouse', 'Student', 'Career changer',
                     'Skilled tradesperson', 'Already in the industry', 'Other'];

const QF_CHECKS = [
    'Q901' => 'A candidate turned down an offer because the pay was too high.',
    'Q902' => 'A hire fell through because the employer replied too quickly.',
    'Q903' => 'A hire stopped because the candidate already knew every site procedure.',
    'Q904' => 'A new hire left because they had too much onboarding support.',
];

const QF_DEFAULT_PROMPT = "From what you've seen in the last 12 months, is this a problem that stops people getting hired?";

/** US spelling for statement texts (applied on import and once to stored statements). */
function qf_us_spelling(string $s): string
{
    $rules = [
        '/\b([Ff])avour/u'                                              => '$1avor',
        '/\b([Cc])olour/u'                                              => '$1olor',
        '/\b([Ll])icence/u'                                             => '$1icense',
        '/\b([Pp])ractis/u'                                             => '$1ractic',
        '/\b([Mm])aterialis(e|ed|es|ing|ation)\b/u'                    => '$1aterializ$2',
        '/\b([Rr])ecognis(e|ed|es|ing|able|ably|ation)\b/u'            => '$1ecogniz$2',
        '/\b([Ee])nrolment/u'                                           => '$1nrollment',
        '/\b([Ee])nrol(s?)\b/u'                                        => '$1nroll$2',
    ];
    return preg_replace(array_keys($rules), array_values($rules), $s);
}

function qf_default_config(): array
{
    return [
        'prompt'      => QF_DEFAULT_PROMPT,
        'set'         => 'core',     // core | all | ids
        'ids'         => '',
        'min_n'       => 6,
        'flag_min'    => 15,
        'flag_yes'    => 0.9,
        'flag_checks' => 2,
        'flag_mode'   => 'exclude',  // exclude | half | include
        'view_side'   => 'hiring',   // hiring | candidate | education | all
        'theme'       => '',
        'stage'       => '',
        'tab'         => 1,
    ];
}

function qf_side_label(string $side): string
{
    return $side === 'all' ? 'Everyone' : (QF_SIDES[$side][1] ?? $side);
}

// ---------------------------------------------------------------- statements: set, import

/** Codes picked for the "pasted list" set, normalised (Q1, q001 and Q001 all mean Q001 if that exists). */
function qf_parse_ids(string $text): array
{
    $out = [];
    foreach (preg_split('/[\s,;]+/', strtoupper($text)) as $c) {
        if ($c !== '') {
            $out[$c] = true;
        }
    }
    return $out;
}

/** Is this statement part of the active set (check statements are handled separately)? */
function qf_in_set(array $st, array $cfg, array $ids): bool
{
    if (!$st['active'] || $st['is_check']) {
        return false;
    }
    switch ($cfg['set']) {
        case 'all':
            return true;
        case 'ids':
            return isset($ids[strtoupper($st['code'])]);
        default:
            return (bool) $st['core_set'];
    }
}

/** Make sure the four check statements exist for this instance. */
function qf_ensure_checks(int $instanceId): void
{
    foreach (QF_CHECKS as $code => $text) {
        q("INSERT IGNORE INTO qf_statements (instance_id, code, theme, topic, stage, statement, core_set, is_check, active)
           VALUES (?, ?, 'Check', 'Check', 'Check', ?, 0, 1, 1)", [$instanceId, $code, $text]);
    }
}

/**
 * Import statements from CSV text: id, theme, topic, stage, statement, core_set [, is_check].
 * Existing codes are updated, new ones added. Returns [added, updated, errors[]].
 */
function qf_import_csv(int $instanceId, string $csv): array
{
    $csv = preg_replace('/^\xEF\xBB\xBF/', '', $csv);   // Excel's BOM
    $lines = preg_split('/\R/', trim($csv));
    $rows = [];
    // Keep quoted line breaks together by re-joining with str_getcsv on the whole text.
    $fh = fopen('php://memory', 'r+');
    fwrite($fh, $csv);
    rewind($fh);
    while (($r = fgetcsv($fh, 0, ',', '"', '\\')) !== false) {
        if ($r === [null] || count(array_filter($r, 'strlen')) === 0) {
            continue;
        }
        $rows[] = $r;
    }
    fclose($fh);
    if (!$rows) {
        return [0, 0, ['The file is empty.']];
    }
    $head = array_map(function ($h) { return strtolower(trim((string) $h)); }, $rows[0]);
    $col = array_flip($head);
    foreach (['id', 'theme', 'topic', 'stage', 'statement', 'core_set'] as $need) {
        if (!isset($col[$need])) {
            return [0, 0, ['Missing column "' . $need . '". The first row must be: id, theme, topic, stage, statement, core_set (is_check optional).']];
        }
    }
    $added = $updated = 0;
    $errors = [];
    $flag = function ($v) { $v = strtolower(trim((string) $v)); return in_array($v, ['1', 'yes', 'y', 'true', 'x'], true) ? 1 : 0; };
    foreach (array_slice($rows, 1) as $k => $r) {
        $code = strtoupper(trim((string) ($r[$col['id']] ?? '')));
        $text = qf_us_spelling(str_limit((string) ($r[$col['statement']] ?? ''), 600));
        if ($code === '' || $text === '') {
            $errors[] = 'Row ' . ($k + 2) . ': missing id or statement, skipped.';
            continue;
        }
        $stage = trim((string) ($r[$col['stage']] ?? ''));
        foreach (QF_STAGES as $known) {   // tolerate case differences
            if (strcasecmp($known, $stage) === 0) {
                $stage = $known;
            }
        }
        $params = [
            $instanceId, $code,
            qf_us_spelling(str_limit((string) ($r[$col['theme']] ?? ''), 120)),
            qf_us_spelling(str_limit((string) ($r[$col['topic']] ?? ''), 160)),
            str_limit($stage, 60),
            $text,
            $flag($r[$col['core_set']] ?? 0),
            isset($col['is_check']) ? $flag($r[$col['is_check']] ?? 0) : 0,
        ];
        $exists = q('SELECT id FROM qf_statements WHERE instance_id = ? AND code = ?', [$instanceId, $code])->fetchColumn();
        q('INSERT INTO qf_statements (instance_id, code, theme, topic, stage, statement, core_set, is_check, active)
           VALUES (?,?,?,?,?,?,?,?,1)
           ON DUPLICATE KEY UPDATE theme = VALUES(theme), topic = VALUES(topic), stage = VALUES(stage),
               statement = VALUES(statement), core_set = VALUES(core_set), is_check = VALUES(is_check), active = 1', $params);
        $exists ? $updated++ : $added++;
    }
    qf_ensure_checks($instanceId);
    return [$added, $updated, $errors];
}

/** All statements of an instance, keyed by id, with in_set worked out from the config. */
function qf_statements(array $inst): array
{
    $ids = qf_parse_ids((string) $inst['config']['ids']);
    $out = [];
    foreach (q('SELECT * FROM qf_statements WHERE instance_id = ? ORDER BY code', [$inst['id']])->fetchAll() as $r) {
        $r['id'] = (int) $r['id'];
        $r['core_set'] = (int) $r['core_set'];
        $r['is_check'] = (int) $r['is_check'];
        $r['active'] = (int) $r['active'];
        $r['in_set'] = qf_in_set($r, $inst['config'], $ids);
        $out[$r['id']] = $r;
    }
    return $out;
}

// ---------------------------------------------------------------- the calculations (pure)

/**
 * Flag each person against the straight-liner rule, over ALL their answers:
 * flagged if (answered >= flag_min non-check statements AND Yes on >= flag_yes of them)
 * OR Yes on >= flag_checks check statements. Returns [pid => ['flagged' => bool, 'yes' => int, 'no' => int, ...]].
 */
function qf_person_stats(array $statements, array $answers, array $opt): array
{
    $p = [];
    foreach ($answers as $a) {
        [$pid, $sid, $ans] = $a;
        if (!isset($statements[$sid])) {
            continue;
        }
        $x = &$p[$pid];
        if ($x === null) {
            $x = ['answered' => 0, 'yes' => 0, 'no' => 0, 'dk' => 0, 'check_yes' => 0];
        }
        if ($statements[$sid]['is_check']) {
            if ($ans === 'Y') {
                $x['check_yes']++;
            }
            unset($x);
            continue;
        }
        $x['answered']++;
        if ($ans === 'Y') {
            $x['yes']++;
        } elseif ($ans === 'N') {
            $x['no']++;
        } else {
            $x['dk']++;
        }
        unset($x);
    }
    foreach ($p as &$x) {
        $x['flagged'] = ($x['answered'] >= $opt['flag_min'] && $x['yes'] >= $opt['flag_yes'] * $x['answered'])
            || $x['check_yes'] >= $opt['flag_checks'];
        $x['r'] = ($x['yes'] + $x['no']) > 0 ? $x['yes'] / ($x['yes'] + $x['no']) : null;
    }
    unset($x);
    return $p;
}

/**
 * Results for one side.
 *   $statements: [sid => [code, theme, topic, stage, statement, is_check, in_set]]
 *   $people:     [pid => [side, segment]]
 *   $answers:    list of [pid, sid, 'Y'|'N'|'D']
 *   $opt:        side (hiring|candidate|education|other|all), min_n, flag_min, flag_yes, flag_checks,
 *                flag_mode (exclude|half|include), theme, stage
 * Lift: each answer scores s = (Yes ? 1 : 0) − r_p, where r_p is that person's own Yes rate over
 * non-check Yes/No answers; a statement's lift is the weighted mean of s over its Yes/No answers × 100.
 */
function qf_compute(array $statements, array $people, array $answers, array $opt): array
{
    $side = $opt['side'];
    $min = (int) $opt['min_n'];
    $ps = qf_person_stats($statements, $answers, $opt);

    // Who is in this view, with which weight.
    $weight = [];
    $included = $excluded = $half = 0;
    foreach ($people as $pid => $person) {
        if ($side !== 'all' && $person['side'] !== $side) {
            continue;
        }
        if (!isset($ps[$pid])) {
            continue;   // no answers yet
        }
        $w = 1.0;
        if ($ps[$pid]['flagged']) {
            if ($opt['flag_mode'] === 'exclude') {
                $excluded++;
                continue;
            }
            if ($opt['flag_mode'] === 'half') {
                $w = 0.5;
                $half++;
            }
        }
        $weight[$pid] = $w;
        $included++;
    }

    // Which statements are ranked: active set, not checks, matching the optional theme / stage filter.
    $ranked = [];
    foreach ($statements as $sid => $st) {
        if (empty($st['in_set']) || $st['is_check']) {
            continue;
        }
        if (($opt['theme'] ?? '') !== '' && strcasecmp($st['theme'], $opt['theme']) !== 0) {
            continue;
        }
        if (($opt['stage'] ?? '') !== '' && strcasecmp($st['stage'], $opt['stage']) !== 0) {
            continue;
        }
        $ranked[$sid] = ['n' => 0, 'w' => 0.0, 'wyes' => 0.0, 'ws' => 0.0, 'y' => 0, 'no' => 0, 'dk' => 0];
    }

    $roomW = $roomYes = 0.0;
    foreach ($answers as $a) {
        [$pid, $sid, $ans] = $a;
        if (!isset($weight[$pid]) || !isset($ranked[$sid])) {
            continue;
        }
        $x = &$ranked[$sid];
        if ($ans === 'D') {
            $x['dk']++;
            unset($x);
            continue;
        }
        $r = $ps[$pid]['r'];
        $w = $weight[$pid];
        $yes = $ans === 'Y' ? 1 : 0;
        $x['n']++;
        $yes ? $x['y']++ : $x['no']++;
        $x['w'] += $w;
        $x['wyes'] += $w * $yes;
        $x['ws'] += $w * ($yes - $r);
        $roomW += $w;
        $roomYes += $w * $yes;
        unset($x);
    }

    $rows = [];
    foreach ($ranked as $sid => $x) {
        $st = $statements[$sid];
        $total = $x['n'] + $x['dk'];
        $rows[$sid] = [
            'id' => $sid, 'code' => $st['code'], 'statement' => $st['statement'], 'stage' => $st['stage'], 'theme' => $st['theme'],
            'n' => $x['n'], 'yes' => $x['y'], 'no' => $x['no'], 'dk' => $x['dk'],
            'yes_pct' => $x['w'] > 0 ? $x['wyes'] / $x['w'] : null,
            'lift' => $x['w'] > 0 ? 100 * $x['ws'] / $x['w'] : null,
            'dk_share' => $total > 0 ? $x['dk'] / $total : null,
            'total' => $total,
        ];
    }

    $passing = array_filter($rows, function ($r) use ($min) { return $r['n'] >= $min && $r['lift'] !== null; });
    $top = array_values($passing);
    usort($top, function ($a, $b) { return $b['lift'] <=> $a['lift'] ?: $b['n'] <=> $a['n']; });
    $top = array_slice($top, 0, 10);

    $blind = array_values(array_filter($rows, function ($r) use ($min) { return $r['total'] >= $min && $r['dk'] > 0; }));
    usort($blind, function ($a, $b) { return $b['dk_share'] <=> $a['dk_share'] ?: $b['total'] <=> $a['total']; });
    $blind = array_slice($blind, 0, 10);

    $stages = [];
    foreach (QF_STAGES as $stage) {
        $in = array_values(array_filter($passing, function ($r) use ($stage) { return strcasecmp($r['stage'], $stage) === 0; }));
        if (count($in) < 3) {
            $stages[] = ['stage' => $stage, 'enough' => false, 'count' => count($in)];
            continue;
        }
        usort($in, function ($a, $b) { return $b['lift'] <=> $a['lift']; });
        $stages[] = ['stage' => $stage, 'enough' => true, 'count' => count($in),
                     'lift' => array_sum(array_column($in, 'lift')) / count($in), 'top' => $in[0]];
    }

    return [
        'side' => $side, 'included' => $included, 'excluded' => $excluded, 'half' => $half,
        'room_yes' => $roomW > 0 ? $roomYes / $roomW : null,
        'rows' => $rows, 'top' => $top, 'blind' => $blind, 'stages' => $stages,
        'flags' => array_map(function ($x) { return $x['flagged']; }, $ps),
    ];
}

/**
 * Hiring vs candidate lift, only where both sides pass min_n on the statement and each side has
 * at least $minPeople participants included. Returns ['ok' => bool, 'hiring_n', 'candidate_n', 'rows' => top 8 by |gap|].
 */
function qf_compare(array $statements, array $people, array $answers, array $opt, int $minPeople = 8, int $limit = 8): array
{
    $h = qf_compute($statements, $people, $answers, ['side' => 'hiring'] + $opt);
    $c = qf_compute($statements, $people, $answers, ['side' => 'candidate'] + $opt);
    $out = ['ok' => false, 'hiring_n' => $h['included'], 'candidate_n' => $c['included'], 'rows' => []];
    if ($h['included'] < $minPeople || $c['included'] < $minPeople) {
        return $out;
    }
    $min = (int) $opt['min_n'];
    foreach ($h['rows'] as $sid => $hr) {
        $cr = $c['rows'][$sid] ?? null;
        if (!$cr || $hr['n'] < $min || $cr['n'] < $min || $hr['lift'] === null || $cr['lift'] === null) {
            continue;
        }
        $out['rows'][] = ['id' => $sid, 'statement' => $hr['statement'], 'code' => $hr['code'],
            'hiring_lift' => $hr['lift'], 'hiring_n' => $hr['n'], 'candidate_lift' => $cr['lift'], 'candidate_n' => $cr['n'],
            'gap' => $cr['lift'] - $hr['lift']];
    }
    usort($out['rows'], function ($a, $b) { return abs($b['gap']) <=> abs($a['gap']); });
    $out['rows'] = array_slice($out['rows'], 0, $limit);
    $out['ok'] = count($out['rows']) > 0;
    return $out;
}

// ---------------------------------------------------------------- loading from the database

function qf_load(array $inst): array
{
    $statements = qf_statements($inst);
    $people = [];
    foreach (q('SELECT participant_id, side, segment FROM qf_participants WHERE instance_id = ?', [$inst['id']])->fetchAll() as $r) {
        $people[(int) $r['participant_id']] = ['side' => $r['side'], 'segment' => $r['segment']];
    }
    $answers = [];
    $latest = null;
    foreach (q('SELECT participant_id, statement_id, answer, created FROM qf_answers WHERE instance_id = ?', [$inst['id']])->fetchAll(PDO::FETCH_NUM) as $r) {
        $answers[] = [(int) $r[0], (int) $r[1], $r[2]];
        if ($latest === null || $r[3] > $latest) {
            $latest = $r[3];
        }
    }
    return [$statements, $people, $answers, $latest];
}

function qf_options(array $cfg, ?string $side = null): array
{
    return [
        'side' => $side ?? $cfg['view_side'], 'min_n' => (int) $cfg['min_n'],
        'flag_min' => (int) $cfg['flag_min'], 'flag_yes' => (float) $cfg['flag_yes'], 'flag_checks' => (int) $cfg['flag_checks'],
        'flag_mode' => $cfg['flag_mode'], 'theme' => $cfg['theme'], 'stage' => $cfg['stage'],
    ];
}

/** Small file cache so the big screen's frequent checks don't recompute everything each time. */
/** A number that changes whenever the host changes something in an event; part of every cache key for it. */
function event_rev(int $eventId): string
{
    return (string) @file_get_contents(rtrim(sys_get_temp_dir(), '/') . '/imasons_rev_' . md5(__DIR__) . '_' . $eventId);
}

/** The host changed the event (slide, open/closed, settings): phones, screen and remote see it on their next check. */
function event_bump(int $eventId): void
{
    if ($eventId > 0) {
        @file_put_contents(rtrim(sys_get_temp_dir(), '/') . '/imasons_rev_' . md5(__DIR__) . '_' . $eventId, uniqid('', true), LOCK_EX);
    }
}

function qf_cached(string $key, int $ttl, callable $fn): array
{
    $file = rtrim(sys_get_temp_dir(), '/') . '/imasons_qf_' . md5(__DIR__ . $key) . '.json';
    if (is_file($file) && time() - filemtime($file) < $ttl) {
        $data = json_decode((string) @file_get_contents($file), true);
        if (is_array($data)) {
            return $data;
        }
    }
    $data = $fn();
    @file_put_contents($file, json_encode($data, JSON_UNESCAPED_UNICODE), LOCK_EX);
    if (mt_rand(1, 500) === 1) {   // now and then, remove cache files nobody has used for an hour
        foreach ((array) glob(rtrim(sys_get_temp_dir(), '/') . '/imasons_qf_*.json') as $old) {
            if (is_file($old) && filemtime($old) < time() - 3600) {
                @unlink($old);
            }
        }
    }
    return $data;
}

/** Page A numbers: participation only, never statement text or results. */
function qf_live_stats(array $inst): array
{
    return qf_cached('qflive' . $inst['id'] . json_encode($inst['config']) . event_rev((int) $inst['event_id']), 4, function () use ($inst) {
        $iid = (int) $inst['id'];
        $bySide = array_fill_keys(array_keys(QF_SIDES), 0);
        foreach (q('SELECT side, COUNT(*) n FROM qf_participants WHERE instance_id = ? GROUP BY side', [$iid])->fetchAll() as $r) {
            $bySide[$r['side']] = (int) $r['n'];
        }
        $total = (int) q('SELECT COUNT(*) FROM qf_answers WHERE instance_id = ?', [$iid])->fetchColumn();
        $lastMin = (int) q('SELECT COUNT(*) FROM qf_answers WHERE instance_id = ? AND created >= UTC_TIMESTAMP() - INTERVAL 60 SECOND', [$iid])->fetchColumn();
        $st = qf_statements($inst);
        $per = [];
        foreach (q("SELECT statement_id, SUM(answer <> 'D') yn, COUNT(*) n FROM qf_answers WHERE instance_id = ? GROUP BY statement_id", [$iid])->fetchAll() as $r) {
            $per[(int) $r['statement_id']] = [(int) $r['yn'], (int) $r['n']];
        }
        $active = $reached = 0;
        $stages = array_fill_keys(QF_STAGES, 0);
        foreach ($st as $sid => $s) {
            if (!$s['in_set']) {
                continue;
            }
            $active++;
            if (($per[$sid][0] ?? 0) >= (int) $inst['config']['min_n']) {
                $reached++;
            }
            foreach (QF_STAGES as $stage) {
                if (strcasecmp($stage, $s['stage']) === 0) {
                    $stages[$stage] += $per[$sid][1] ?? 0;
                }
            }
        }
        return ['by_side' => $bySide, 'people' => array_sum($bySide), 'answers' => $total, 'last_minute' => $lastMin,
                'active' => $active, 'reached' => $reached, 'stages' => $stages];
    });
}

/** Page B numbers: all four tabs, so the screen can switch tabs instantly. */
function qf_results(array $inst): array
{
    return qf_cached('res' . $inst['id'] . json_encode($inst['config']) . event_rev((int) $inst['event_id']), 3, function () use ($inst) {
        [$st, $people, $answers, $latest] = qf_load($inst);
        $cfg = $inst['config'];
        $opt = qf_options($cfg);
        $r = qf_compute($st, $people, $answers, $opt);
        $cmp = qf_compare($st, $people, $answers, $opt, 8, 1000);
        $round = function ($rows) {
            return array_map(function ($x) {
                foreach (['yes_pct', 'lift', 'dk_share'] as $k) {
                    if (isset($x[$k])) {
                        $x[$k] = round((float) $x[$k], 4);
                    }
                }
                return $x;
            }, $rows);
        };
        return [
            'side' => $opt['side'], 'side_label' => qf_side_label($opt['side']),
            'included' => $r['included'], 'excluded' => $r['excluded'], 'half' => $r['half'], 'flag_mode' => $cfg['flag_mode'],
            'room_yes' => $r['room_yes'], 'min_n' => (int) $cfg['min_n'],
            'filter' => trim(($cfg['theme'] !== '' ? 'Theme: ' . $cfg['theme'] : '') . ($cfg['stage'] !== '' ? ' Stage: ' . $cfg['stage'] : '')),
            'as_of' => $latest,
            'top' => $round($r['top']), 'blind' => $round($r['blind']),
            'stages' => array_map(function ($s) use ($round, $r, $cfg) {
                // Presentation only: how many of the stage's statements (with min n) have lift of +20 or more.
                $s['strong'] = count(array_filter($r['rows'], function ($x) use ($s, $cfg) {
                    return strcasecmp($x['stage'], $s['stage']) === 0 && $x['n'] >= (int) $cfg['min_n'] && $x['lift'] !== null && $x['lift'] >= 20;
                }));
                if (!empty($s['top'])) { $s['top'] = $round([$s['top']])[0]; }
                return $s;
            }, $r['stages']),
            'compare' => $cmp,
        ];
    });
}

// ---------------------------------------------------------------- phone side

/** What a phone needs: its profile, the prompt, statement texts (only when changed), counts, its answers. */
function qf_phone_view(array $inst, int $pid, string $clientHash): array
{
    // The statement list and the answer counts are the same for every phone: read once per 3 s for all.
    $shared = qf_cached('phone' . $inst['id'] . json_encode($inst['config']) . event_rev((int) $inst['event_id']), 3, function () use ($inst) {
        $st = qf_statements($inst);
        $list = [];
        foreach ($st as $s) {
            if ($s['in_set'] || ($s['is_check'] && $s['active'])) {
                $list[] = ['id' => $s['id'], 'text' => $s['statement'], 'check' => $s['is_check']];
            }
        }
        $counts = [];
        foreach (q('SELECT statement_id, COUNT(*) n FROM qf_answers WHERE instance_id = ? GROUP BY statement_id', [$inst['id']])->fetchAll(PDO::FETCH_NUM) as $r) {
            if (isset($st[(int) $r[0]]) && $st[(int) $r[0]]['in_set']) {
                $counts[(string) (int) $r[0]] = (int) $r[1];
            }
        }
        return ['list' => $list, 'hash' => substr(md5(json_encode($list)), 0, 10), 'counts' => $counts];
    });
    $list = $shared['list'];
    $hash = $shared['hash'];
    $counts = $shared['counts'];
    $prof = q('SELECT side, segment FROM qf_participants WHERE instance_id = ? AND participant_id = ?', [$inst['id'], $pid])->fetch();
    $mine = array_map('intval', q('SELECT statement_id FROM qf_answers WHERE participant_id = ? AND instance_id = ?', [$pid, $inst['id']])->fetchAll(PDO::FETCH_COLUMN));
    $v = [
        'prompt' => $inst['config']['prompt'],
        'profile' => $prof ?: null,
        'set_hash' => $hash,
        'counts' => (object) $counts,
        'answered' => $mine,
        'sides' => array_map(function ($k) { return ['value' => $k, 'label' => QF_SIDES[$k][0]]; }, array_keys(QF_SIDES)),
        'segments' => QF_SEGMENTS,
    ];
    if ($clientHash !== $hash) {
        $v['statements'] = $list;
    }
    return $v;
}

function qf_save_profile(array $event, int $pid, array $in): bool
{
    $inst = instance_by_id((int) ($in['instance_id'] ?? 0));
    $side = (string) ($in['side'] ?? '');
    if (!$inst || $inst['module'] !== 'm5' || (int) $inst['event_id'] !== (int) $event['id'] || !isset(QF_SIDES[$side])) {
        return false;
    }
    $segment = in_array((string) ($in['segment'] ?? ''), QF_SEGMENTS, true) ? (string) $in['segment'] : '';
    q('INSERT INTO qf_participants (instance_id, participant_id, side, segment, created) VALUES (?,?,?,?,UTC_TIMESTAMP())
       ON DUPLICATE KEY UPDATE side = VALUES(side), segment = VALUES(segment)', [$inst['id'], $pid, $side, $segment]);
    return true;
}

/** One write per answer; the statement must belong to this open module of this event. Answering twice overwrites. */
function qf_save_answers(int $eventId, int $instanceId, int $pid, array $answers): int
{
    // One query for the whole batch (a phone sends what it collected since its last check).
    $byId = [];
    foreach (array_slice($answers, 0, 100) as $a) {
        $ans = strtoupper((string) ($a['answer'] ?? ''));
        $sid = (int) ($a['statement_id'] ?? 0);
        if ($sid > 0 && in_array($ans, ['Y', 'N', 'D'], true)) {
            $byId[$sid] = $ans;   // answered twice in one batch: the last answer counts
        }
    }
    if (!$byId) {
        return 0;
    }
    $case = 'CASE s.id' . str_repeat(' WHEN ? THEN ?', count($byId)) . ' END';
    $params = [$pid];
    foreach ($byId as $sid => $ans) {
        $params[] = $sid;
        $params[] = $ans;
    }
    $params = array_merge($params, array_keys($byId), [$instanceId, $eventId]);
    return q("INSERT INTO qf_answers (instance_id, participant_id, statement_id, answer, created)
              SELECT s.instance_id, ?, s.id, $case, UTC_TIMESTAMP()
              FROM qf_statements s JOIN instances i ON i.id = s.instance_id
              WHERE s.id IN (" . implode(',', array_fill(0, count($byId), '?')) . ") AND s.active = 1
                AND i.id = ? AND i.event_id = ? AND i.module = 'm5' AND i.is_open = 1
              ON DUPLICATE KEY UPDATE answer = VALUES(answer), created = VALUES(created)", $params)->rowCount();
}

// ---------------------------------------------------------------- admin helpers

/** Demo data for a rehearsal: 40 people (30 hiring, 6 candidate, 4 education), one straight-liner. */
function qf_seed_demo(array $inst): int
{
    $st = array_values(array_filter(qf_statements($inst), function ($s) { return $s['in_set']; }));
    $checks = array_values(array_filter(qf_statements($inst), function ($s) { return $s['is_check']; }));
    if (!$st) {
        return 0;
    }
    $event = event_by_id((int) $inst['event_id']);
    mt_srand(42);
    // Each statement gets a "true" problem rate; people differ in how often they say Yes.
    $truth = [];
    foreach ($st as $i => $s) {
        $truth[$s['id']] = 0.25 + 0.6 * (($i * 37) % 100) / 100;
    }
    $sides = array_merge(array_fill(0, 30, 'hiring'), array_fill(0, 6, 'candidate'), array_fill(0, 4, 'education'));
    foreach ($sides as $k => $side) {
        $p = participant_for($event, null);
        q('UPDATE participants SET last_seen = UTC_TIMESTAMP() - INTERVAL 1 DAY WHERE id = ?', [$p['id']]);
        q("INSERT INTO qf_participants (instance_id, participant_id, side, segment, created) VALUES (?,?,?,?,UTC_TIMESTAMP())",
            [$inst['id'], $p['id'], $side, $side === 'candidate' ? 'Veteran or service member' : '']);
        $straight = $k === 0;
        $bias = (mt_rand(0, 100) / 100 - 0.5) * 0.4;   // some people agree more than others
        $mine = $st;
        shuffle($mine);
        foreach (array_slice($mine, 0, min(count($mine), 20 + mt_rand(0, 15))) as $s) {
            $roll = mt_rand(0, 1000) / 1000;
            $ans = $straight ? 'Y' : ($roll < 0.08 ? 'D' : ($roll < $truth[$s['id']] + $bias ? 'Y' : 'N'));
            q("INSERT IGNORE INTO qf_answers (instance_id, participant_id, statement_id, answer, created) VALUES (?,?,?,?,UTC_TIMESTAMP())",
                [$inst['id'], $p['id'], $s['id'], $ans]);
        }
        foreach (array_slice($checks, 0, 2) as $c) {
            q("INSERT IGNORE INTO qf_answers (instance_id, participant_id, statement_id, answer, created) VALUES (?,?,?,?,UTC_TIMESTAMP())",
                [$inst['id'], $p['id'], $c['id'], $straight ? 'Y' : 'N']);
        }
    }
    return count($sides);
}

function qf_clear(int $instanceId): void
{
    q('DELETE FROM qf_answers WHERE instance_id = ?', [$instanceId]);
    q('DELETE FROM qf_participants WHERE instance_id = ?', [$instanceId]);
}
