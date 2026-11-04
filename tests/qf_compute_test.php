<?php
// Unit tests for the quick-fire calculations. Run: php tests/qf_compute_test.php
declare(strict_types=1);

require __DIR__ . '/../src/quickfire.php';

$fails = 0;
function check(bool $ok, string $what): void
{
    global $fails;
    echo ($ok ? 'PASS ' : 'FAIL ') . $what . "\n";
    if (!$ok) {
        $fails++;
    }
}
function near($a, $b, float $eps = 0.01): bool
{
    return $a !== null && abs($a - $b) < $eps;
}

// Four ranked statements in one stage, and two check statements.
$st = [];
foreach ([1 => 'Awareness', 2 => 'Awareness', 3 => 'Matching', 4 => 'Matching'] as $i => $stage) {
    $st[$i] = ['code' => "S$i", 'theme' => $i <= 2 ? 'Pay' : 'Process', 'topic' => '', 'stage' => $stage,
               'statement' => "Statement $i", 'is_check' => 0, 'in_set' => true];
}
$st[91] = ['code' => 'Q901', 'theme' => 'Check', 'topic' => 'Check', 'stage' => 'Check', 'statement' => 'Check 1', 'is_check' => 1, 'in_set' => false];
$st[92] = ['code' => 'Q902', 'theme' => 'Check', 'topic' => 'Check', 'stage' => 'Check', 'statement' => 'Check 2', 'is_check' => 1, 'in_set' => false];

// A: selective (Yes rate 1/4). B: Yes rate 2/3, Don't know on S4. Z: Yes to everything, incl. both checks.
// C: candidate side, Yes rate 1/2.
$people = ['A' => ['side' => 'hiring', 'segment' => ''], 'B' => ['side' => 'hiring', 'segment' => ''],
           'Z' => ['side' => 'hiring', 'segment' => ''], 'C' => ['side' => 'candidate', 'segment' => 'Student']];
$answers = [
    ['A', 1, 'Y'], ['A', 2, 'N'], ['A', 3, 'N'], ['A', 4, 'N'], ['A', 91, 'N'], ['A', 92, 'N'],
    ['B', 1, 'Y'], ['B', 2, 'Y'], ['B', 3, 'N'], ['B', 4, 'D'],
    ['Z', 1, 'Y'], ['Z', 2, 'Y'], ['Z', 3, 'Y'], ['Z', 4, 'Y'], ['Z', 91, 'Y'], ['Z', 92, 'Y'],
    ['C', 1, 'N'], ['C', 2, 'Y'],
];
$opt = ['side' => 'hiring', 'min_n' => 1, 'flag_min' => 15, 'flag_yes' => 0.9, 'flag_checks' => 2,
        'flag_mode' => 'exclude', 'theme' => '', 'stage' => ''];

// --- straight-liner flags
$ps = qf_person_stats($st, $answers, $opt);
check($ps['Z']['flagged'], 'Z flagged: Yes to 2 check statements');
check(!$ps['A']['flagged'] && !$ps['B']['flagged'], 'A and B not flagged');
check(near($ps['A']['r'], 0.25) && near($ps['B']['r'], 2 / 3), 'personal Yes rates exclude checks and Don\'t know (A 0.25, B 0.67)');

// --- exclude (default)
$r = qf_compute($st, $people, $answers, $opt);
check($r['included'] === 2 && $r['excluded'] === 1, 'exclude: 2 people included, 1 excluded');
// S1: A 1−0.25=0.75, B 1−0.667=0.333 → mean 0.5417 → +54.2
check(near($r['rows'][1]['lift'], 54.17), 'S1 lift +54.2 (' . round($r['rows'][1]['lift'], 2) . ')');
// S2: A −0.25, B +0.333 → +4.2
check(near($r['rows'][2]['lift'], 4.17), 'S2 lift +4.2');
// S3: A −0.25, B −0.667 → −45.8
check(near($r['rows'][3]['lift'], -45.83), 'S3 lift −45.8');
// S4: A −0.25 only (B said Don't know) → −25, n = 1, Don't know share 1/2
check(near($r['rows'][4]['lift'], -25) && $r['rows'][4]['n'] === 1 && near($r['rows'][4]['dk_share'], 0.5), 'S4: lift −25, n 1, Don\'t know 50%');
check(near($r['rows'][1]['yes_pct'], 1.0) && near($r['rows'][2]['yes_pct'], 0.5), '% Yes: S1 100%, S2 50%');
check(!isset($r['rows'][91]) && !isset($r['rows'][92]), 'check statements never ranked');
check(array_column($r['top'], 'code') === ['S1', 'S2', 'S4', 'S3'], 'top ranked by lift: S1, S2, S4, S3');
check(near($r['room_yes'], 3 / 7), 'room average Yes rate 3/7 (Yes/No answers of included people)');
check($r['blind'][0]['code'] === 'S4', 'blind spots: S4 first');

// --- include and half weight
$inc = qf_compute($st, $people, $answers, ['flag_mode' => 'include'] + $opt);
// Z's r = 1, so every Z answer scores 0: S1 = (0.75 + 0.333 + 0) / 3 = 36.1
check($inc['included'] === 3 && near($inc['rows'][1]['lift'], 36.11), 'include: Z counted with weight 1, S1 lift +36.1');
$half = qf_compute($st, $people, $answers, ['flag_mode' => 'half'] + $opt);
// (0.75 + 0.333 + 0.5×0) / 2.5 = 43.3
check($half['half'] === 1 && near($half['rows'][1]['lift'], 43.33), 'half weight: S1 lift +43.3');

// --- minimum n and stages
$min2 = qf_compute($st, $people, $answers, ['min_n' => 2] + $opt);
check(!in_array('S4', array_column($min2['top'], 'code'), true), 'min n 2: S4 (n 1) left out of the ranking');
check(!$min2['stages'][0]['enough'], 'stage with fewer than 3 passing statements marked "not enough answers"');

// --- filters
$pay = qf_compute($st, $people, $answers, ['theme' => 'Pay'] + $opt);
check(array_column($pay['top'], 'code') === ['S1', 'S2'], 'theme filter keeps only that theme');

// --- the 90% rule
$st2 = [];
$ans2 = [];
for ($i = 1; $i <= 15; $i++) {
    $st2[$i] = ['code' => "T$i", 'theme' => '', 'topic' => '', 'stage' => 'Start', 'statement' => "T$i", 'is_check' => 0, 'in_set' => true];
    $ans2[] = ['W', $i, $i === 15 ? 'N' : 'Y'];   // 14 of 15 Yes = 93%
    $ans2[] = ['V', $i, $i >= 14 ? 'N' : 'Y'];    // 13 of 15 Yes = 87%
}
$ps2 = qf_person_stats($st2, $ans2, $opt);
check($ps2['W']['flagged'] && !$ps2['V']['flagged'], '90% rule: 14/15 Yes flagged, 13/15 not');
$ps3 = qf_person_stats($st2, array_slice($ans2, 0, 20), $opt);
check(!$ps3['W']['flagged'], '90% rule needs at least 15 answers');

// --- side by side
$cmp = qf_compare($st, $people, $answers, $opt, 8);
check(!$cmp['ok'] && $cmp['hiring_n'] === 2 && $cmp['candidate_n'] === 1, 'compare refused below 8 people per side, with both counts');
$cmp1 = qf_compare($st, $people, $answers, $opt, 1);
// C's r = 0.5: S1 −50, S2 +50. Gaps: S1 −50 − 54.2 = −104.2, S2 +50 − 4.2 = +45.8
check($cmp1['ok'] && $cmp1['rows'][0]['code'] === 'S1' && near($cmp1['rows'][0]['gap'], -104.17) && near($cmp1['rows'][1]['gap'], 45.83),
    'gap = candidate lift − hiring lift, largest absolute gap first');

echo $fails ? "\n$fails FAILED\n" : "\nALL PASSED\n";
exit($fails ? 1 : 0);
