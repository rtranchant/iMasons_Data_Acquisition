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

/** MySQL errors that mean "the database server is full right now, try again in a moment". */
function db_busy_error(Throwable $e): bool
{
    // 1040: the server's connection limit (shared by every site on that database server)
    // 1203 / 1226: this account's own connection limit
    return $e instanceof PDOException && preg_match('/\[(1040|1203|1226)\]/', $e->getMessage()) === 1;
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
        $opts = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => true,
            PDO::ATTR_STRINGIFY_FETCHES  => false,
            PDO::ATTR_TIMEOUT            => 5,
        ];
        // When the database server is full, a slot usually frees up within a second:
        // try again a few times (0.1 + 0.2 + 0.4 + 0.8 s) before giving up.
        $waits = [100, 200, 400, 800];
        for ($try = 0; ; $try++) {
            try {
                $pdo = new PDO($dsn, $c['user'], $c['password'], $opts);
                if ($try > 0) {
                    error_log('[imasons db] connected after ' . $try . ' retr' . ($try === 1 ? 'y' : 'ies') . ' (database server was full)');
                }
                break;
            } catch (PDOException $e) {
                if (!db_busy_error($e) || $try >= count($waits)) {
                    throw $e;
                }
                usleep($waits[$try] * 1000);
            }
        }
    }
    return $pdo;
}

/** Pages (not the JSON API): a clear message instead of a blank error page when the database is full. */
function page_error_handler(Throwable $e): void
{
    error_log('[imasons page] ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    if (!headers_sent()) {
        http_response_code(db_busy_error($e) ? 503 : 500);
        header('Content-Type: text/html; charset=utf-8');
        header('Retry-After: 5');
    }
    $post = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
    $busy = db_busy_error($e);
    echo '<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">';
    if ($busy && !$post) {
        echo '<meta http-equiv="refresh" content="5">';
    }
    echo '<title>One moment</title><body style="font:18px/1.5 system-ui,sans-serif;max-width:560px;margin:15vh auto;padding:0 20px">';
    if ($busy) {
        echo '<h1 style="font-size:26px">The database is busy for a moment</h1>';
        echo $post
            ? '<p>Your change was <b>not saved</b>. Go back and send it again in a few seconds.</p><p><a href="javascript:history.back()">Go back</a></p>'
            : '<p>This page will try again by itself in 5 seconds.</p>';
    } else {
        echo '<h1 style="font-size:26px">Something went wrong</h1><p>Please reload the page. If it keeps happening, check the server error log.</p>';
    }
    echo '</body>';
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

// Admin login is a signed cookie, not a PHP session: the host deletes idle session files after
// about 24 minutes, which logged the admin out in the middle of an event. The cookie stays valid
// until "Log out" on that device, or until the admin password changes (that logs out every device).

const AUTH_COOKIE = 'imasons_auth';
const AUTH_DAYS = 400; // the longest browsers keep a cookie; renewed on every admin visit, so it never runs out in use

function auth_key(): string
{
    $c = app_config();
    return hash('sha256', 'imasons-admin|' . (string) ($c['admin_password'] ?? '') . '|' . (string) ($c['db']['password'] ?? ''));
}

function auth_set_cookie(string $value, int $expires): void
{
    if (headers_sent()) {
        return;
    }
    setcookie(AUTH_COOKIE, $value, [
        'expires'  => $expires,
        'path'     => '/',
        'secure'   => is_https(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

/** Log this device in (after the password was checked). */
function auth_login(): void
{
    $nonce = bin2hex(random_bytes(16));
    $value = $nonce . '.' . hash_hmac('sha256', $nonce, auth_key());
    $_COOKIE[AUTH_COOKIE] = $value;
    $GLOBALS['__auth'] = $nonce;
    auth_set_cookie($value, time() + AUTH_DAYS * 86400);
}

function auth_logout(): void
{
    unset($_COOKIE[AUTH_COOKIE]);
    $GLOBALS['__auth'] = null;
    auth_set_cookie('', time() - 3600);
}

/** The login nonce from a valid cookie, or null. */
function auth_nonce(): ?string
{
    if (array_key_exists('__auth', $GLOBALS)) {
        return $GLOBALS['__auth'];
    }
    $GLOBALS['__auth'] = null;
    $nonce = null;
    $c = app_config();
    if (empty($c['admin_password'])) {
        return null;
    }
    $parts = explode('.', (string) ($_COOKIE[AUTH_COOKIE] ?? ''));
    if (count($parts) === 2 && preg_match('/^[a-f0-9]{32}$/', $parts[0])
        && hash_equals(hash_hmac('sha256', $parts[0], auth_key()), $parts[1])) {
        $nonce = $parts[0];
        auth_set_cookie($parts[0] . '.' . $parts[1], time() + AUTH_DAYS * 86400); // keep it from ever running out
    }
    return $GLOBALS['__auth'] = $nonce;
}

function is_admin(): bool
{
    if (auth_nonce() !== null) {
        return true;
    }
    // Someone logged in with the old session-based login: move them to the cookie once.
    if (!empty($_COOKIE['imasons_admin'])) {
        start_session();
        if (!empty($_SESSION['admin'])) {
            auth_login();
            return true;
        }
    }
    return false; // attendees never get a session or admin cookie
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

/** Form/request token. Tied to the login cookie, so open pages keep working however long they stay open. */
function csrf_token(): string
{
    $nonce = is_admin() ? auth_nonce() : null;
    if ($nonce !== null) {
        return substr(hash_hmac('sha256', 'csrf|' . $nonce, auth_key()), 0, 32);
    }
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
