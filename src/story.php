<?php
// Impact story: everything an event collected, summarized for a press-release style write-up by Claude.
// Only aggregate results and texts attendees chose to share on screen are used: never names or emails.
//
// Config (imasons_config.php):  'anthropic_api_key' => 'sk-ant-...'   (without it, the page offers the
// material to copy into Claude by hand)    optional: 'anthropic_model' => 'claude-sonnet-5-5'

declare(strict_types=1);

const STORY_DEFAULT_MODEL = 'claude-sonnet-5-5';
const STORY_MAX_IMAGES = 20;

function story_row(int $eventId): ?array
{
    $r = q('SELECT * FROM event_stories WHERE event_id = ?', [$eventId])->fetch();
    return $r ?: null;
}

function story_save(int $eventId, array $fields): void
{
    $cur = story_row($eventId) ?? ['notes' => '', 'story' => '', 'status' => 'none', 'error' => '', 'model' => ''];
    $row = array_merge($cur, $fields);
    q('INSERT INTO event_stories (event_id, notes, story, status, error, model, updated_at) VALUES (?,?,?,?,?,?,UTC_TIMESTAMP())
       ON DUPLICATE KEY UPDATE notes = VALUES(notes), story = VALUES(story), status = VALUES(status), error = VALUES(error),
           model = VALUES(model), updated_at = UTC_TIMESTAMP()',
        [$eventId, (string) $row['notes'], (string) $row['story'], (string) $row['status'], (string) $row['error'], (string) $row['model']]);
}

function story_fmt($x, int $d = 1): string
{
    return $x === null ? 'n/a' : sv_fmt((float) $x, $d);
}

/** All collected results of an event as plain text, module by module, in run-of-show order. */
function story_data(array $event): string
{
    $eid = (int) $event['id'];
    $out = [];
    $first = q('SELECT MIN(created_at) FROM participants WHERE event_id = ?', [$eid])->fetchColumn();
    $out[] = 'EVENT: ' . $event['name'];
    $out[] = 'Date: ' . ($first ? substr((string) $first, 0, 10) : substr((string) $event['created_at'], 0, 10));
    $out[] = 'Phones that joined: ' . (int) q('SELECT COUNT(*) FROM participants WHERE event_id = ?', [$eid])->fetchColumn();

    $slides = event_slides($eid);
    $out[] = '';
    $out[] = 'RUN OF SHOW (in order, as shown on the big screen):';
    $n = 0;
    foreach ($slides as $s) {
        if ($s['visible']) {
            $out[] = '  ' . (++$n) . '. ' . ($s['kind'] === 'image' ? 'Slide "' . $s['title'] . '" ' . $s['label'] : $s['title'] . ' (' . MODULES[$s['module']]['name'] . ', ' . $s['label'] . ')');
        }
    }

    $dots = event_dots($eid);
    foreach (event_instances($eid) as $inst) {
        $cfg = $inst['config'];
        $id = (int) $inst['id'];
        $people = $dots[$id]['done'] ?? 0;
        $out[] = '';
        $out[] = '=== ACTIVITY: "' . $inst['title'] . '" – ' . MODULES[$inst['module']]['name'] . ' – ' . $people . ' people took part';
        switch ($inst['module']) {
            case 'm1':
                $out[] = 'Instruction: ' . $cfg['intro'];
                $out[] = 'Statements (Yes / No / Don\'t know counts; result):';
                foreach (m1_stats($inst) as $st) {
                    $out[] = sprintf('  - "%s": Yes %d, No %d, Don\'t know %d (n=%d) – %s', $st['text'], $st['yes'], $st['no'], $st['dk'], $st['n'],
                        ['open' => 'not enough answers', 'yes' => 'the room agrees', 'no' => 'the room disagrees', 'revise' => 'split opinion'][$st['status']]);
                }
                break;
            case 'm2':
                $out[] = 'Prompt: ' . $cfg['prompt'];
                $total = (int) q('SELECT COUNT(*) FROM submissions WHERE instance_id = ?', [$id])->fetchColumn();
                $texts = approved_statements($id);
                $out[] = $total . ' answers written, ' . count($texts) . ' approved for the screen. Approved answers (verbatim, anonymous):';
                foreach (array_slice($texts, 0, 150) as $t) {
                    $out[] = '  - "' . str_limit((string) $t['body'], 400) . '"';
                }
                foreach ($cfg['questions'] as $qi => $qq) {
                    $counts = array_fill_keys($qq['options'], 0);
                    foreach (q('SELECT answers FROM submissions WHERE instance_id = ?', [$id])->fetchAll(PDO::FETCH_COLUMN) as $a) {
                        $a = json_decode((string) $a, true) ?: [];
                        $c = $a[$qi]['a'] ?? '';
                        if (isset($counts[$c])) {
                            $counts[$c]++;
                        }
                    }
                    $out[] = 'Follow-up question "' . $qq['q'] . '": ' . implode(', ', array_map(function ($k, $v) { return $k . ' ' . $v; }, array_keys($counts), $counts));
                }
                break;
            case 'm3':
                $out[] = 'Offer: ' . $cfg['intro'];
                $out[] = 'People who asked for the session materials: ' . (int) q('SELECT COUNT(*) FROM submissions WHERE instance_id = ?', [$id])->fetchColumn();
                break;
            case 'm4':
                $out[] = 'Pledge: ' . $cfg['pledge'];
                $out[] = 'People who made the pledge (name and email left, not shared here): ' . (int) q('SELECT COUNT(*) FROM submissions WHERE instance_id = ?', [$id])->fetchColumn();
                break;
            case 'm5':
                $r = qf_results($inst);
                $out[] = 'Quick-fire Yes/No/Don\'t know cards; "Yes" always means "this is a problem". Lift = how much more often this side said Yes'
                    . ' to a statement than to statements on average, in points (+20 or more = a strong problem). Results shown for: ' . $r['side_label']
                    . '; ' . $r['included'] . ' people included, ' . $r['excluded'] . ' excluded for answering Yes to almost everything.';
                $out[] = 'Biggest problems (lift, n answers):';
                foreach (array_slice($r['top'], 0, 10) as $x) {
                    $out[] = sprintf('  - %s: lift %s (n=%d)', rtrim($x['statement'], '.'), story_fmt($x['lift'], 0), $x['n']);
                }
                $out[] = 'By stage of the hiring journey (number of strong problems; top statement):';
                foreach ($r['stages'] as $s) {
                    $out[] = '  - ' . $s['stage'] . ': ' . (!$s['enough'] ? 'not enough answers' : ($s['strong'] ?? 0) . ' strong'
                        . (!empty($s['top']) ? '; top: ' . $s['top']['statement'] . ' (lift ' . story_fmt($s['top']['lift'], 0) . ')' : ''));
                }
                $out[] = 'Blind spots (share answering Don\'t know):';
                foreach (array_slice($r['blind'], 0, 5) as $x) {
                    $out[] = sprintf('  - %s: %s%% Don\'t know (n=%d)', rtrim($x['statement'], '.'), story_fmt(100 * $x['dk_share'], 0), $x['total']);
                }
                $c = $r['compare'];
                if (!empty($c['ok'])) {
                    $out[] = 'Employers vs candidates (' . $c['hiring_n'] . ' hiring-side, ' . $c['candidate_n'] . ' candidate-side people; gap = candidate lift minus employer lift):';
                    foreach (array_slice($c['rows'], 0, 8) as $x) {
                        $out[] = sprintf('  - %s: gap %s (candidates %s, employers %s)', rtrim($x['statement'], '.'), story_fmt($x['gap'], 0), story_fmt($x['candidate_lift'], 0), story_fmt($x['hiring_lift'], 0));
                    }
                } else {
                    $out[] = 'Employers vs candidates: not enough answers on both sides to compare.';
                }
                break;
            case 'm6':
            case 'm8':
                $r = sv_results($inst);
                $st = $r['stats'];
                $out[] = 'Question: ' . $cfg['prompt'];
                $out[] = $inst['module'] === 'm6'
                    ? 'Scale from ' . $cfg['min'] . ($cfg['low_label'] ? ' (' . $cfg['low_label'] . ')' : '') . ' to ' . $cfg['max'] . ($cfg['high_label'] ? ' (' . $cfg['high_label'] . ')' : '')
                    : 'Number' . ($cfg['unit'] ? ' in ' . $cfg['unit'] : '');
                $out[] = $st['n'] ? sprintf('Answers: %d. Average %s, lowest %s, 10th percentile %s, 90th percentile %s, highest %s.', $st['n'],
                    story_fmt($st['mean']), story_fmt($st['min']), story_fmt($st['p10']), story_fmt($st['p90']), story_fmt($st['max'])) : 'No answers.';
                break;
            case 'm7':
                $r = sv_results($inst);
                $out[] = 'Question: ' . $cfg['prompt'] . ($cfg['multi'] ? ' (several answers allowed)' : ' (one answer)');
                $out[] = $r['people'] . ' people answered. Options, most chosen first (people, % of those who answered):';
                foreach ($r['choices'] as $c) {
                    $out[] = sprintf('  - %s: %d (%s%%)', $c['label'], $c['count'], $r['people'] ? round(100 * $c['count'] / $r['people']) : 0);
                }
                if ($r['other_texts']) {
                    $out[] = '"' . $cfg['other_label'] . '" answers (verbatim, approved):';
                    foreach ($r['other_texts'] as $t) {
                        $out[] = '  - "' . $t . '"';
                    }
                }
                break;
        }
    }
    return implode("\n", $out);
}

function story_system_prompt(): string
{
    return <<<TXT
You write impact stories for Infrastructure Masons (iMasons), the professional association for the people who build and run the digital infrastructure industry.

Write an impact story about one live session, in the style of a press release, using ONLY the material provided: the host's notes, the uploaded slides (images), and the results collected live from attendees' phones.

Structure, in Markdown:
# A headline that states the main finding
A one-sentence subheadline.
A lede paragraph: what happened, who was in the room, when, and why it matters.
## What the session was about
From the slides, the activity titles and the host's notes.
## What the room told us
The key findings, most important first. Every number must come from the data and say which activity it came from and how many people answered (e.g. "Asked to rate their readiness from 0 to 10, the 42 attendees averaged 6.7").
## In their own words
Two to four short quotes taken word for word from the approved attendee answers, attributed only to "one attendee" or "a participant". Leave this section out if there are no approved texts.
## What happens next
Only what the notes or the data support; otherwise suggest next steps and say they are suggestions.
## About the data
One short paragraph: which activities were run, how many people took part in each, and caveats (small samples, self-selected participants). Explain "lift" in plain words if quick-fire results are used.

Rules:
- Never invent facts, names, organizations, numbers or quotes. If something is not in the material, leave it out.
- Round numbers the way they are given. Keep "n" small samples honest ("of the 12 people who answered...").
- No personal data: no attendee names or emails (none are provided).
- US spelling. Warm, confident, factual tone. 600 to 900 words.
TXT;
}

/** Uploaded slide pages, scaled down, as image blocks for the API (up to STORY_MAX_IMAGES). */
function story_slide_images(int $eventId): array
{
    $blocks = [];
    if (!function_exists('imagecreatefromstring')) {
        return $blocks;
    }
    $ids = [];
    foreach (event_slides($eventId) as $s) {
        if ($s['visible'] && $s['kind'] === 'image' && !in_array((int) $s['page_id'], $ids, true)) {
            $ids[] = (int) $s['page_id'];
        }
    }
    foreach (array_slice($ids, 0, STORY_MAX_IMAGES) as $pid) {
        $data = q('SELECT data FROM deck_pages WHERE id = ?', [$pid])->fetchColumn();
        $im = $data ? @imagecreatefromstring((string) $data) : false;
        if (!$im) {
            continue;
        }
        $w = imagesx($im);
        $h = imagesy($im);
        $k = min(1, 1280 / max($w, 1));
        $small = imagecreatetruecolor(max(1, (int) round($w * $k)), max(1, (int) round($h * $k)));
        imagecopyresampled($small, $im, 0, 0, 0, 0, imagesx($small), imagesy($small), $w, $h);
        ob_start();
        imagejpeg($small, null, 72);
        $jpg = (string) ob_get_clean();
        imagedestroy($im);
        imagedestroy($small);
        $blocks[] = ['type' => 'image', 'source' => ['type' => 'base64', 'media_type' => 'image/jpeg', 'data' => base64_encode($jpg)]];
    }
    return $blocks;
}

/** The message sent to Claude (also what the page offers to copy by hand). */
function story_user_text(array $event, string $notes): string
{
    return "HOST'S NOTES (purpose, speakers, partners, anything to emphasize):\n" . ($notes !== '' ? $notes : '(none)')
        . "\n\nCOLLECTED RESULTS:\n" . story_data($event)
        . "\n\nWrite the impact story now.";
}

/**
 * Ask Claude for the story, streaming, so the web server sees steady output and never times the request out.
 * $onText is called with each piece of text as it arrives. Returns [story, error].
 */
function story_generate(array $event, string $notes, callable $onText): array
{
    $c = app_config();
    $key = (string) ($c['anthropic_api_key'] ?? '');
    if ($key === '') {
        return ['', 'No API key is set.'];
    }
    if (!function_exists('curl_init')) {
        return ['', 'PHP curl is not available on this server.'];
    }
    $content = story_slide_images((int) $event['id']);
    $content[] = ['type' => 'text', 'text' => story_user_text($event, $notes)];
    $body = json_encode([
        'model' => (string) ($c['anthropic_model'] ?? STORY_DEFAULT_MODEL),
        'max_tokens' => 4000,
        'stream' => true,
        'system' => story_system_prompt(),
        'messages' => [['role' => 'user', 'content' => $content]],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    $text = '';
    $error = '';
    $buffer = '';
    $raw = '';
    $ch = curl_init(rtrim((string) ($c['anthropic_base_url'] ?? 'https://api.anthropic.com'), '/') . '/v1/messages');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $body,
        CURLOPT_HTTPHEADER => ['content-type: application/json', 'x-api-key: ' . $key, 'anthropic-version: 2023-06-01'],
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_TIMEOUT => 300,
        CURLOPT_WRITEFUNCTION => function ($ch, $chunk) use (&$text, &$error, &$buffer, &$raw, $onText) {
            $raw .= strlen($raw) < 4000 ? $chunk : '';
            $buffer .= $chunk;
            while (($pos = strpos($buffer, "\n")) !== false) {
                $line = trim(substr($buffer, 0, $pos));
                $buffer = substr($buffer, $pos + 1);
                if (strpos($line, 'data:') !== 0) {
                    continue;
                }
                $ev = json_decode(trim(substr($line, 5)), true);
                if (!is_array($ev)) {
                    continue;
                }
                if (($ev['type'] ?? '') === 'content_block_delta' && ($ev['delta']['type'] ?? '') === 'text_delta') {
                    $text .= $ev['delta']['text'];
                    $onText($ev['delta']['text']);
                } elseif (($ev['type'] ?? '') === 'error') {
                    $error = (string) ($ev['error']['message'] ?? 'The AI service reported an error.');
                }
            }
            return strlen($chunk);
        },
    ]);
    $ok = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    if ($ok === false) {
        $error = 'Could not reach the AI service: ' . curl_error($ch);
    } elseif ($status !== 200) {
        $j = json_decode($raw, true);
        $error = 'The AI service answered ' . $status . ': ' . (string) ($j['error']['message'] ?? substr($raw, 0, 300));
    }
    curl_close($ch);
    return [trim($text), $error];
}

/** Minimal, safe Markdown for showing the story: headings, bold, italics, lists, paragraphs. */
function story_html(string $md): string
{
    $html = '';
    foreach (preg_split('/\R\R+/', trim($md)) as $block) {
        $lines = preg_split('/\R/', $block);
        if (preg_match('/^\s*[-*] /', $lines[0])) {
            $html .= '<ul>';
            foreach ($lines as $l) {
                $html .= '<li>' . story_inline(preg_replace('/^\s*[-*] /', '', $l)) . '</li>';
            }
            $html .= '</ul>';
        } elseif (preg_match('/^(#{1,3}) (.*)$/', $lines[0], $m)) {
            $lvl = strlen($m[1]);
            $html .= '<h' . ($lvl + 1) . '>' . story_inline($m[2]) . '</h' . ($lvl + 1) . '>';
            if (count($lines) > 1) {
                $html .= '<p>' . implode('<br>', array_map('story_inline', array_slice($lines, 1))) . '</p>';
            }
        } else {
            $html .= '<p>' . implode('<br>', array_map('story_inline', $lines)) . '</p>';
        }
    }
    return $html;
}

function story_inline(string $s): string
{
    $s = h($s);
    $s = preg_replace('/\*\*(.+?)\*\*/', '<b>$1</b>', $s);
    return preg_replace('/(?<![\w*])\*(?!\s)(.+?)(?<!\s)\*(?![\w*])/', '<i>$1</i>', $s);
}
