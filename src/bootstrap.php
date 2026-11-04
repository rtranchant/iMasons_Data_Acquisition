<?php
// Shared setup: configuration, database, migrations, admin auth, helpers.
// Kept compatible with PHP 7.4+ and MariaDB 10.3.
//
// Server layout:  ~/romaintranchant.co.uk/imasons_session_running_platform/{src,public}
// Config file:    imasons_config.php in the home directory OR in the app folder.

declare(strict_types=1);

date_default_timezone_set('UTC');

function app_config(): array
{
    static $config = null;
    if ($config === null) {
        $candidates = [
            getenv('IMASONS_CONFIG') ?: '',
            dirname(__DIR__, 3) . '/imasons_config.php', // src -> app -> site -> home
            dirname(__DIR__) . '/imasons_config.php',    // app root
        ];
        foreach ($candidates as $path) {
            if ($path !== '' && is_readable($path)) {
                $config = require $path;
                break;
            }
        }
        if ($config === null) {
            throw new RuntimeException('Config file imasons_config.php not found');
        }
    }
    return $config;
}

function db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $c = app_config()['db'];
        $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $c['host'], $c['port'], $c['name']);
        // The database is on another server, so every round trip counts:
        // emulated prepares send each query in ONE round trip (native prepares need two or three),
        // and no session setup query is needed (all times use UTC_TIMESTAMP()).
        $pdo = new PDO($dsn, $c['user'], $c['password'], [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => true,
            PDO::ATTR_STRINGIFY_FETCHES  => false,
        ]);
    }
    return $pdo;
}

/** Run a query with parameters and return the statement. */
function q(string $sql, array $params = []): PDOStatement
{
    if (getenv('IMASONS_DEBUG')) {
        $GLOBALS['__q'] = ($GLOBALS['__q'] ?? 0) + 1;
        if (!headers_sent()) {
            header('X-Query-Count: ' . $GLOBALS['__q']);
        }
    }
    $st = db()->prepare($sql);
    $st->execute($params);
    return $st;
}

/** Apply any pending migrations from src/schema.php. Cheap when up to date. */
function migrate(): void
{
    db()->exec('CREATE TABLE IF NOT EXISTS schema_migrations (
        version INT PRIMARY KEY, applied_at DATETIME NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    $done = array_map('intval', q('SELECT version FROM schema_migrations')->fetchAll(PDO::FETCH_COLUMN));
    $migrations = require __DIR__ . '/schema.php';
    ksort($migrations);
    foreach ($migrations as $version => $statements) {
        if (in_array($version, $done, true)) {
            continue;
        }
        foreach ($statements as $sql) {
            is_callable($sql) ? $sql() : db()->exec($sql);
        }
        q('INSERT INTO schema_migrations (version, applied_at) VALUES (?, UTC_TIMESTAMP())', [$version]);
    }
}

// ---------------------------------------------------------------- helpers

function h($s): string
{
    return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
}

function json_out(array $data, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/** Send $data, or just {"same":true} when the caller already has this exact data (?h=<hash>). */
function json_out_unless_same(array $data): void
{
    $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $hash = substr(md5($json), 0, 12);
    if (($_GET['h'] ?? '') === $hash) {
        json_out(['same' => true, 'h' => $hash]);
    }
    $data['h'] = $hash;
    json_out($data);
}

function is_https(): bool
{
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
        || (($_SERVER['SERVER_PORT'] ?? '') === '443');
}

/** Absolute URL of a file in public/, e.g. app_url('e.php?c=AB12'). */
function app_url(string $path): string
{
    $dir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    return (is_https() ? 'https' : 'http') . '://' . $host . $dir . '/' . ltrim($path, '/');
}

function random_code(int $length = 6): string
{
    $alphabet = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789'; // no 0/O, 1/I/L
    $out = '';
    for ($i = 0; $i < $length; $i++) {
        $out .= $alphabet[random_int(0, strlen($alphabet) - 1)];
    }
    return $out;
}

/** Multi-line text: keeps line breaks (max one blank line in a row), tidies spaces, limits length. */
function str_multiline(string $s, int $max): string
{
    $s = str_replace(["\r\n", "\r"], "\n", $s);
    $s = preg_replace('/[ \t\x{00A0}]+/u', ' ', $s);
    $s = preg_replace('/ *\n */', "\n", $s);
    $s = preg_replace('/\n{3,}/', "\n\n", $s);
    return mb_substr(trim($s), 0, $max);
}

function str_limit(string $s, int $max): string
{
    $s = trim(preg_replace('/\s+/u', ' ', $s));
    return mb_substr($s, 0, $max);
}

// ---------------------------------------------------------------- admin auth

function start_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    session_name('imasons_admin');
    session_set_cookie_params([
        'lifetime' => 60 * 60 * 24 * 14,
        'path'     => '/',
        'secure'   => is_https(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

function is_admin(): bool
{
    if (session_status() !== PHP_SESSION_ACTIVE && empty($_COOKIE['imasons_admin'])) {
        return false; // don't hand attendees a session cookie
    }
    start_session();
    return !empty($_SESSION['admin']);
}

function require_admin(bool $json = false): void
{
    if (is_admin()) {
        return;
    }
    if ($json) {
        json_out(['error' => 'not_logged_in'], 401);
    }
    header('Location: login.php?next=' . rawurlencode($_SERVER['REQUEST_URI'] ?? 'admin.php'));
    exit;
}

function csrf_token(): string
{
    start_session();
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(16));
    }
    return $_SESSION['csrf'];
}

function check_csrf(?string $token): void
{
    if (!is_string($token) || !hash_equals(csrf_token(), $token)) {
        http_response_code(400);
        exit('Invalid or expired form. Go back, reload the page and try again.');
    }
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf" value="' . h(csrf_token()) . '">';
}
