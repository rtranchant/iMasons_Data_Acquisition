<?php
// Survey modules: one question per module, answered on phones, shown on the big screen.
//   m6 Scale            a whole number between two ends (e.g. 1–10)
//   m7 Multiple choice  one or several options, plus an optional "Other" with a text box
//   m8 Number           any number, optionally bounded, with decimals and a unit
//
// Answers are stored in `submissions` (one row per person per question; answering again replaces it):
//   answers = {"v": 7}                      scale and number
//   answers = {"c": ["Option A"], "o": 1}   multiple choice (option texts, and whether Other was picked)
//   body    = the "Other" text; status = pending until approved on the remote, like the text module.
// The counts never depend on approval: approval only decides whether an Other text is shown on screen.

declare(strict_types=1);

const SV_MODULES = ['m6', 'm7', 'm8'];
const SV_OTHER_MAX = 200;

function sv_default_config(string $module): array
{
    switch ($module) {
        case 'm6':
            return ['prompt' => 'How ready is your organization for this?', 'min' => 1, 'max' => 10,
                    'low_label' => 'Not at all', 'high_label' => 'Completely'];
        case 'm7':
            return ['prompt' => 'Which of these matters most to you?', 'options' => ['Option A', 'Option B', 'Option C'],
                    'multi' => false, 'max_picks' => 0, 'allow_other' => true, 'other_label' => 'Other'];
        case 'm8':
            return ['prompt' => 'How many people work in your organization?', 'min' => null, 'max' => null,
                    'decimals' => 0, 'unit' => ''];
    }
    throw new InvalidArgumentException('Unknown survey module');
}

/** A number typed by the admin, or null when left blank. */
function sv_num_or_null($v): ?float
{
    $v = str_replace([',', ' '], ['', ''], trim((string) $v));
    return $v === '' || !is_numeric($v) ? null : (float) $v;
}

/** Settings from the admin form. Returns [config, message or null]. */
function sv_config_from_post(string $module, array $cfg, array $p): array
{
    $msg = null;
    $cfg['prompt'] = str_multiline((string) ($p['prompt'] ?? ''), 500) ?: $cfg['prompt'];
    if ($module === 'm6') {
        $min = (int) round(sv_num_or_null($p['min'] ?? '') ?? 1);
        $max = (int) round(sv_num_or_null($p['max'] ?? '') ?? 10);
        $min = max(-100, min(1000, $min));
        $max = max(-100, min(1000, $max));
        if ($max <= $min) {
            $max = $min + 1;
            $msg = 'The top of the scale must be above the bottom; it was set to ' . $max . '.';
        }
        if ($max - $min > 100) {
            $max = $min + 100;
            $msg = 'A scale can have at most 101 points; the top was set to ' . $max . '.';
        }
        $cfg['min'] = $min;
        $cfg['max'] = $max;
        $cfg['low_label'] = str_limit((string) ($p['low_label'] ?? ''), 60);
        $cfg['high_label'] = str_limit((string) ($p['high_label'] ?? ''), 60);
    } elseif ($module === 'm7') {
        $opts = [];
        foreach (preg_split('/\R/', (string) ($p['options'] ?? '')) as $line) {
            $o = str_limit(ltrim($line, "-* \t"), 200);
            if ($o !== '' && !in_array($o, $opts, true)) {
                $opts[] = $o;
            }
        }
        if (count($opts) < 2) {
            $msg = 'Add at least two options, one per line.';
            $opts = $cfg['options'];
        }
        $cfg['options'] = array_slice($opts, 0, 30);
        $cfg['multi'] = !empty($p['multi']);
        $cfg['max_picks'] = $cfg['multi'] ? max(0, min(30, (int) ($p['max_picks'] ?? 0))) : 0;
        $cfg['allow_other'] = !empty($p['allow_other']);
        $cfg['other_label'] = str_limit((string) ($p['other_label'] ?? ''), 60) ?: 'Other';
    } elseif ($module === 'm8') {
        $min = sv_num_or_null($p['min'] ?? '');
        $max = sv_num_or_null($p['max'] ?? '');
        if ($min !== null && $max !== null && $max <= $min) {
            $max = null;
            $msg = 'The maximum must be above the minimum; the maximum was cleared.';
        }
        $cfg['min'] = $min;
        $cfg['max'] = $max;
        $cfg['decimals'] = max(0, min(3, (int) ($p['decimals'] ?? 0)));
        $cfg['unit'] = str_limit((string) ($p['unit'] ?? ''), 30);
    }
    return [$cfg, $msg];
}

/** What a phone needs to show the question, and this person's current answer. */
function sv_phone_view(array $inst, ?array $sub): array
{
    $cfg = $inst['config'];
    $v = ['prompt' => $cfg['prompt'], 'submitted' => (bool) $sub, 'mine' => null];
    if ($inst['module'] === 'm7') {
        $v += ['options' => $cfg['options'], 'multi' => (bool) $cfg['multi'], 'max_picks' => (int) $cfg['max_picks'],
               'allow_other' => (bool) $cfg['allow_other'], 'other_label' => $cfg['other_label'], 'other_max' => SV_OTHER_MAX];
    } else {
        foreach (['min', 'max', 'low_label', 'high_label', 'decimals', 'unit'] as $k) {
            if (array_key_exists($k, $cfg)) {
                $v[$k] = $cfg[$k];
            }
        }
    }
    if ($sub) {
        $a = json_decode((string) $sub['answers'], true) ?: [];
        $v['mine'] = $inst['module'] === 'm7'
            ? ['c' => $a['c'] ?? [], 'o' => !empty($a['o']), 'text' => (string) $sub['body']]
            : ['v' => $a['v'] ?? null];
    }
    return $v;
}

/** Check a phone's answer. Returns [answers, body, status] or an error message for the attendee. */
function sv_validate(array $inst, array $in)
{
    $cfg = $inst['config'];
    if ($inst['module'] === 'm7') {
        $picked = array_values(array_unique(array_map('strval', (array) ($in['choices'] ?? []))));
        foreach ($picked as $c) {
            if (!in_array($c, $cfg['options'], true)) {
                return 'Please pick one of the options.';
            }
        }
        $other = !empty($in['other']) && $cfg['allow_other'];
        $text = $other ? str_multiline((string) ($in['other_text'] ?? ''), SV_OTHER_MAX) : '';
        if ($other && $text === '') {
            return 'Please write your "' . $cfg['other_label'] . '" answer, or untick it.';
        }
        $count = count($picked) + ($other ? 1 : 0);
        if ($count === 0) {
            return 'Please pick an answer first.';
        }
        if (!$cfg['multi'] && $count > 1) {
            return 'Please pick just one answer.';
        }
        if ($cfg['multi'] && $cfg['max_picks'] > 0 && $count > $cfg['max_picks']) {
            return 'Please pick up to ' . $cfg['max_picks'] . ' answers.';
        }
        return [['c' => $picked, 'o' => $other ? 1 : 0], $text, $text === '' ? 'approved' : 'pending'];
    }

    $raw = str_replace([',', ' '], ['', ''], trim((string) ($in['value'] ?? '')));
    if ($raw === '' || !is_numeric($raw)) {
        return 'Please enter a number.';
    }
    $v = (float) $raw;
    if ($inst['module'] === 'm6') {
        if ($v != round($v) || $v < $cfg['min'] || $v > $cfg['max']) {
            return 'Please pick a number from ' . $cfg['min'] . ' to ' . $cfg['max'] . '.';
        }
        return [['v' => (int) $v], null, 'approved'];
    }
    $dec = (int) $cfg['decimals'];
    if (round($v, $dec) != $v) {
        return $dec === 0 ? 'Please enter a whole number.' : 'Please use at most ' . $dec . ' decimal place' . ($dec > 1 ? 's' : '') . '.';
    }
    if ($cfg['min'] !== null && $v < $cfg['min']) {
        return 'Please enter a number of at least ' . sv_fmt((float) $cfg['min'], $dec) . '.';
    }
    if ($cfg['max'] !== null && $v > $cfg['max']) {
        return 'Please enter a number no higher than ' . sv_fmt((float) $cfg['max'], $dec) . '.';
    }
    if (abs($v) > 1e15) {
        return 'That number is too large.';
    }
    return [['v' => $dec === 0 ? (int) $v : $v], null, 'approved'];
}

function sv_fmt(float $x, int $dec): string
{
    $s = number_format($x, $dec, '.', ',');
    return strpos($s, '.') !== false ? rtrim(rtrim($s, '0'), '.') : $s;
}

/** Percentile with linear interpolation between the closest ranks (as in Excel's PERCENTILE.INC). */
function sv_percentile(array $sorted, float $p): float
{
    $n = count($sorted);
    if ($n === 1) {
        return (float) $sorted[0];
    }
    $pos = ($n - 1) * $p;
    $lo = (int) floor($pos);
    $hi = (int) ceil($pos);
    return $sorted[$lo] + ($sorted[$hi] - $sorted[$lo]) * ($pos - $lo);
}

/** Summary of numeric answers: n, average, minimum, maximum, 10th and 90th percentiles. */
function sv_number_stats(array $values): array
{
    sort($values, SORT_NUMERIC);
    $n = count($values);
    if (!$n) {
        return ['n' => 0];
    }
    return ['n' => $n, 'mean' => array_sum($values) / $n, 'min' => (float) $values[0], 'max' => (float) $values[$n - 1],
            'p10' => sv_percentile($values, 0.1), 'p90' => sv_percentile($values, 0.9)];
}

/** Results for the big screen. Cached for a second: the screen asks several times a second. */
function sv_results(array $inst): array
{
    return qf_cached('sv' . $inst['id'] . json_encode($inst['config']), 1, function () use ($inst) {
        $cfg = $inst['config'];
        $rows = q('SELECT answers, body, status FROM submissions WHERE instance_id = ? ORDER BY updated_at', [$inst['id']])->fetchAll();
        if ($inst['module'] === 'm7') {
            $counts = array_fill_keys($cfg['options'], 0);
            $other = 0;
            $texts = [];
            foreach ($rows as $r) {
                $a = json_decode((string) $r['answers'], true) ?: [];
                foreach ((array) ($a['c'] ?? []) as $c) {
                    if (isset($counts[$c])) {
                        $counts[$c]++;
                    }
                }
                if (!empty($a['o'])) {
                    $other++;
                    if ($r['status'] === 'approved' && $r['body'] !== '' && $r['body'] !== null) {
                        $texts[] = $r['body'];
                    }
                }
            }
            $list = [];
            foreach ($cfg['options'] as $i => $o) {
                $list[] = ['label' => $o, 'count' => $counts[$o], 'order' => $i, 'other' => false];
            }
            if ($cfg['allow_other'] || $other) {
                $list[] = ['label' => $cfg['other_label'], 'count' => $other, 'order' => 999, 'other' => true];
            }
            // Most chosen first; ties keep the order the options were written in.
            usort($list, function ($a, $b) { return [$b['count'], $a['order']] <=> [$a['count'], $b['order']]; });
            return ['people' => count($rows), 'choices' => array_map(function ($x) {
                unset($x['order']);
                return $x;
            }, $list), 'other_texts' => array_slice(array_reverse($texts), 0, 40), 'multi' => (bool) $cfg['multi']];
        }
        $values = [];
        foreach ($rows as $r) {
            $a = json_decode((string) $r['answers'], true) ?: [];
            if (isset($a['v']) && is_numeric($a['v'])) {
                $values[] = (float) $a['v'];
            }
        }
        $out = ['people' => count($rows), 'stats' => sv_number_stats($values)];
        if ($inst['module'] === 'm6') {
            $out += ['min' => (int) $cfg['min'], 'max' => (int) $cfg['max'], 'low_label' => $cfg['low_label'],
                     'high_label' => $cfg['high_label'], 'decimals' => 1];
        } else {
            $out += ['min' => $cfg['min'], 'max' => $cfg['max'], 'decimals' => (int) $cfg['decimals'], 'unit' => $cfg['unit']];
        }
        return $out;
    });
}

/** One line per answer for the CSV export: [answer, other text, other status]. */
function sv_export_answer(array $inst, array $row): array
{
    $a = json_decode((string) $row['answers'], true) ?: [];
    if ($inst['module'] === 'm7') {
        $parts = (array) ($a['c'] ?? []);
        if (!empty($a['o'])) {
            $parts[] = $inst['config']['other_label'];
        }
        return [implode(' | ', $parts), (string) $row['body'], !empty($a['o']) ? $row['status'] : ''];
    }
    return [isset($a['v']) ? (string) $a['v'] : ''];
}
