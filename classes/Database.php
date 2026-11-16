<?php
declare(strict_types=1);

/**
 * classes/Database.php
 * Database connection helper providing access to the shared PDO instance.
 */
class Database
{
    private static ?PDO $pdo = null;

    public static function getConnection(): PDO
    {
        if (self::$pdo === null) {
            require __DIR__ . '/../config/database.php';
            self::$pdo = $pdo;
        }
        return self::$pdo;
    }
}
