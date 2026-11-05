<?php
// YouTube videos in the run of show: shown full screen like an uploaded slide, playing from a start time
// to a stop time. A video belongs to an event; each one has one slide (kind 'video').

declare(strict_types=1);

/** The 11-character video id from any usual YouTube link (watch, youtu.be, embed, shorts, live), or null. */
function yt_parse_id(string $url): ?string
{
    $url = trim($url);
    if (preg_match('/^[A-Za-z0-9_-]{11}$/', $url)) {
        return $url;
    }
    if (preg_match('~(?:youtube(?:-nocookie)?\.com/(?:watch\?(?:.*&)?v=|embed/|shorts/|live/|v/)|youtu\.be/)([A-Za-z0-9_-]{11})~i', $url, $m)) {
        return $m[1];
    }
    return null;
}

/** "1:30", "01:02:03", "90", "90s", "1m30s" → seconds; blank → null; anything else → false. */
function yt_parse_time(string $s)
{
    $s = strtolower(trim($s));
    if ($s === '') {
        return null;
    }
    if (preg_match('/^\d+$/', $s)) {
        return (int) $s;
    }
    if (preg_match('/^(\d+):([0-5]?\d)$/', $s, $m)) {
        return (int) $m[1] * 60 + (int) $m[2];
    }
    if (preg_match('/^(\d+):([0-5]?\d):([0-5]?\d)$/', $s, $m)) {
        return (int) $m[1] * 3600 + (int) $m[2] * 60 + (int) $m[3];
    }
    if (preg_match('/^(?:(\d+)h)?\s*(?:(\d+)m)?\s*(?:(\d+)s)?$/', $s, $m) && $s !== '') {
        return (int) ($m[1] ?? 0) * 3600 + (int) ($m[2] ?? 0) * 60 + (int) ($m[3] ?? 0);
    }
    return false;
}

/** A start time written in the link itself (?t=90, &t=1m30s, &start=90). */
function yt_time_in_url(string $url): ?int
{
    if (preg_match('/[?&#](?:t|start)=([0-9hms:]+)/i', $url, $m)) {
        $t = yt_parse_time($m[1]);
        return is_int($t) ? $t : null;
    }
    return null;
}

function yt_fmt_time(?int $s): string
{
    if ($s === null) {
        return '';
    }
    return $s >= 3600 ? sprintf('%d:%02d:%02d', intdiv($s, 3600), intdiv($s % 3600, 60), $s % 60) : sprintf('%d:%02d', intdiv($s, 60), $s % 60);
}

/** Check the admin's form. Returns [fields, null] or [null, message]. */
function video_from_post(array $p): array
{
    $url = trim((string) ($p['url'] ?? ''));
    $id = yt_parse_id($url);
    if (!$id) {
        return [null, 'That does not look like a YouTube link. Paste the address of the video, e.g. https://www.youtube.com/watch?v=…'];
    }
    $start = yt_parse_time((string) ($p['start'] ?? ''));
    $end = yt_parse_time((string) ($p['end'] ?? ''));
    if ($start === false || $end === false) {
        return [null, 'Write times as minutes:seconds (e.g. 1:30) or hours:minutes:seconds.'];
    }
    if ($start === null) {
        $start = yt_time_in_url($url);
    }
    if ($start !== null && $end !== null && $end <= $start) {
        return [null, 'The stop time must be after the start time.'];
    }
    return [['youtube_id' => $id, 'url' => str_limit($url, 500), 'title' => str_limit((string) ($p['title'] ?? ''), 200),
             'start_s' => $start ?: null, 'end_s' => $end], null];
}

/** A local copy of the video's thumbnail (for the remote, admin and the PDF export); the YouTube address if that fails. */
function video_thumb_url(string $youtubeId): string
{
    $name = 'yt-' . $youtubeId . '.jpg';
    return is_file(media_dir() . '/' . $name) ? 'media/' . $name : 'https://img.youtube.com/vi/' . $youtubeId . '/hqdefault.jpg';
}

function video_fetch_thumb(string $youtubeId): void
{
    $file = media_dir() . '/yt-' . $youtubeId . '.jpg';
    if (is_file($file) || !function_exists('curl_init') || (!is_dir(media_dir()) && !@mkdir(media_dir(), 0755))) {
        return;
    }
    foreach (['maxresdefault', 'hqdefault'] as $size) {
        $ch = curl_init('https://img.youtube.com/vi/' . $youtubeId . '/' . $size . '.jpg');
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 4, CURLOPT_TIMEOUT => 8, CURLOPT_FOLLOWLOCATION => true]);
        $img = curl_exec($ch);
        $ok = curl_getinfo($ch, CURLINFO_RESPONSE_CODE) === 200 && is_string($img) && strlen($img) > 2000;
        curl_close($ch);
        if ($ok && @file_put_contents($file . '.tmp', $img) !== false) {
            @rename($file . '.tmp', $file);
            return;
        }
    }
}

function video_label(array $v): string
{
    $s = $v['start_s'] !== null ? (int) $v['start_s'] : null;
    $e = $v['end_s'] !== null ? (int) $v['end_s'] : null;
    if ($s === null && $e === null) {
        return 'Video';
    }
    return 'Video ' . ($s !== null ? yt_fmt_time($s) : '0:00') . '–' . ($e !== null ? yt_fmt_time($e) : 'end');
}

function event_videos(int $eventId): array
{
    return q('SELECT v.*, s.id AS slide_id FROM videos v LEFT JOIN slides s ON s.video_id = v.id WHERE v.event_id = ? ORDER BY s.position, v.id', [$eventId])->fetchAll();
}
