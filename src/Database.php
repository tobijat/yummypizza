<?php

declare(strict_types=1);

namespace YummyPizza;

use PDO;
use PDOException;
use RuntimeException;

final class Database
{
    public static function connect(Config $config): PDO
    {
        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
            $config->dbHost,
            $config->dbPort,
            $config->dbName,
        );

        try {
            return new PDO($dsn, $config->dbUser, $config->dbPass, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
        } catch (PDOException $e) {
            throw new RuntimeException('Could not connect to the database: ' . $e->getMessage(), 0, $e);
        }
    }

    public static function ensureSchema(PDO $pdo): void
    {
        $sql = file_get_contents(dirname(__DIR__) . '/schema.sql');
        if (!is_string($sql) || trim($sql) === '') {
            throw new RuntimeException('schema.sql is missing.');
        }
        $sql = preg_replace('/--.*$/m', '', $sql) ?? $sql;
        foreach (explode(';', $sql) as $statement) {
            $statement = trim($statement);
            if ($statement !== '') {
                $pdo->exec($statement);
            }
        }

        $date = self::column($pdo, 'date');
        if ($date !== null && strtoupper((string) $date['Null']) === 'NO') {
            $pdo->exec('ALTER TABLE visit MODIFY `date` DATE NULL');
        }

        $description = self::column($pdo, 'description');
        if (
            $description !== null
            && preg_match('/varchar\((\d+)\)/i', (string) $description['Type'], $matches) === 1
            && (int) $matches[1] < 4000
        ) {
            $pdo->exec('ALTER TABLE visit MODIFY description TEXT NULL');
        }

        if (self::column($pdo, 'google_place_id', 'location') === null) {
            $pdo->exec('ALTER TABLE location ADD google_place_id VARCHAR(255) NULL');
        }
        if (self::column($pdo, 'google_business_status', 'location') === null) {
            $pdo->exec('ALTER TABLE location ADD google_business_status VARCHAR(32) NULL');
        }
        if (self::column($pdo, 'google_status_at', 'location') === null) {
            $pdo->exec('ALTER TABLE location ADD google_status_at DATE NULL');
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function column(PDO $pdo, string $name, string $table = 'visit'): ?array
    {
        $statement = $pdo->prepare(
            'SELECT IS_NULLABLE, COLUMN_TYPE
             FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
        );
        $statement->execute([$table, $name]);
        $column = $statement->fetch();
        if ($column === false) {
            return null;
        }

        return [
            'Null' => $column['IS_NULLABLE'],
            'Type' => $column['COLUMN_TYPE'],
        ];
    }
}
