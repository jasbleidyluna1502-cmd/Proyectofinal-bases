<?php
declare(strict_types=1);

/** Singleton de la conexión PDO para reutilizar una sola instancia por ejecución. */
final class Database
{
    private static ?PDO $instance = null;

    private function __construct() {}
    private function __clone() {}

    public static function getInstance(): PDO
    {
        if (self::$instance === null) {
            $config = require __DIR__ . '/config.php';
            $dsn = $config['dsn'] ?? "pgsql:host={$config['host']};port={$config['port']};dbname={$config['dbname']}";
            self::$instance = new PDO(
                $dsn,
                $config['user'],
                $config['password'],
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
            );
        }

        return self::$instance;
    }
}
