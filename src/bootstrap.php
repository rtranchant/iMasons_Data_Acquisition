<?php
// Loads server-only configuration and provides a shared PDO connection.
// Layout on the server: ~/imasons_config.php
//                       ~/romaintranchant.co.uk/imasons_session_running_platform/{src,public}

declare(strict_types=1);

function app_config(): array
{
    static $config = null;
    if ($config === null) {
        $path = dirname(__DIR__, 3) /* src -> app -> site -> home */ . '/imasons_config.php';
        if (!is_readable($path)) {
            throw new RuntimeException('Config file not found: ~/imasons_config.php');
        }
        $config = require $path;
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
