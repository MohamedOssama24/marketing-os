<?php
declare(strict_types=1);

final class Database
{
    public static function connection(): PDO
    {
        static $pdo = null;
        if ($pdo instanceof PDO) return $pdo;
        $host = getenv('DB_HOST') ?: 'db';
        $port = getenv('DB_PORT') ?: '3306';
        $name = getenv('DB_DATABASE') ?: 'marketing_os';
        $user = getenv('DB_USERNAME') ?: 'marketing_app';
        $pass = getenv('DB_PASSWORD') ?: '';
        $dsn = "mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4";
        $pdo = new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        return $pdo;
    }
}
