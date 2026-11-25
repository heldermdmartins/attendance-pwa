<?php
declare(strict_types=1);

const APP_VERSION = '1.8.0';
const ROOT = __DIR__ . '/..';

function config(): array
{
    static $config;
    if ($config === null) {
        $config = is_readable(ROOT . '/.env') ? parse_ini_file(ROOT . '/.env', false, INI_SCANNER_RAW) : [];
        if ($config === false) {
            throw new RuntimeException('Invalid configuration');
        }
    }
    return $config;
}

function db(): PDO
{
    static $pdo;
    if ($pdo === null) {
        $config = config();
        foreach (['DB_NAME', 'DB_USER', 'DB_PASSWORD'] as $key) {
            if (empty($config[$key])) {
                throw new RuntimeException('Missing database configuration');
            }
        }
        $host = $config['DB_HOST'] ?? 'localhost';
        $port = (int) ($config['DB_PORT'] ?? 3306);
        $pdo = new PDO(
            "mysql:host=$host;port=$port;dbname={$config['DB_NAME']};charset=utf8mb4",
            $config['DB_USER'],
            $config['DB_PASSWORD'],
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]
        );
    }
    return $pdo;
}

date_default_timezone_set('Europe/Lisbon');
