<?php
// Loads server-only configuration and provides a shared PDO connection.
// Layout on the server: ~/imasons_config.php
//                       ~/romaintranchant.co.uk/imasons_session_running_platform/{src,public}

declare(strict_types=1);

function app_config(): array
{
    static $config = null;
    if ($config === null) {
        // Checked in order: home directory, then the app folder (next to public/).
        $candidates = [
            dirname(__DIR__, 3) . '/imasons_config.php', // src -> app -> site -> home
            dirname(__DIR__) . '/imasons_config.php',    // app root
        ];
        foreach ($candidates as $path) {
            if (is_readable($path)) {
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
        $pdo = new PDO($dsn, $c['user'], $c['password'], [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
    }
    return $pdo;
}
