<?php

declare(strict_types=1);

namespace YummyPizza;

use RuntimeException;

final class Config
{
    public const DEFAULT_KML_URL = 'https://www.google.com/maps/d/kml?mid=z2Qplwtk3f0Q.kaiyuAkHjF00&forcekml=1';

    public function __construct(
        public readonly string $dbHost,
        public readonly int $dbPort,
        public readonly string $dbUser,
        public readonly string $dbPass,
        public readonly string $dbName,
        public readonly string $kmlUrl,
        public readonly string $googlePlacesKey,
    ) {
    }

    public static function load(string $path): self
    {
        $values = is_file($path) ? self::parseFile((string) file_get_contents($path)) : [];

        foreach ([
            'db_host' => 'DB_HOST',
            'db_user' => 'DB_USER',
            'db_name' => 'DB_NAME',
            'db_port' => 'DB_PORT',
            'klm_url' => 'KML_URL',
            'kml_url' => 'KML_URL',
            'google_places_key' => 'GOOGLE_PLACES_API_KEY',
        ] as $key => $envName) {
            $value = getenv($envName);
            if ($value !== false && $value !== '') {
                $values[$key] = $value;
            }
        }

        $password = getenv('DB_PASS');
        if ($password !== false) {
            $values['db_pass'] = $password;
        }

        $dbName = trim((string) ($values['db_name'] ?? ''));
        if ($dbName === '') {
            $dbName = trim((string) ($values['db_user'] ?? ''));
        }

        $host = trim((string) ($values['db_host'] ?? ''));
        $user = trim((string) ($values['db_user'] ?? ''));
        if ($host === '' || $user === '' || $dbName === '') {
            throw new RuntimeException(
                'Database config is missing. Copy config.yml.sample to config.yml, or set DB_HOST, DB_USER, and DB_NAME.'
            );
        }

        if (preg_match('/[;\s]/', $host . $dbName) === 1) {
            throw new RuntimeException('Database host or name contains invalid characters.');
        }

        $port = (int) ($values['db_port'] ?? 3306);
        if ($port < 1 || $port > 65535) {
            throw new RuntimeException('Database port is invalid.');
        }

        $kmlUrl = trim((string) ($values['kml_url'] ?? $values['klm_url'] ?? self::DEFAULT_KML_URL));
        if ($kmlUrl === '') {
            $kmlUrl = self::DEFAULT_KML_URL;
        }

        return new self(
            $host,
            $port,
            $user,
            (string) ($values['db_pass'] ?? ''),
            $dbName,
            $kmlUrl,
            trim((string) ($values['google_places_key'] ?? '')),
        );
    }

    /**
     * @return array<string, string>
     */
    private static function parseFile(string $contents): array
    {
        $values = [];
        foreach (preg_split("/\r\n|\n|\r/", $contents) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            if (preg_match('/^([A-Za-z0-9_]+)\s*:\s*(.*)$/', $line, $matches) !== 1) {
                continue;
            }
            $values[strtolower($matches[1])] = self::unquote(trim($matches[2]));
        }

        return $values;
    }

    private static function unquote(string $value): string
    {
        if (strlen($value) >= 2) {
            $quote = $value[0];
            if (($quote === '"' || $quote === "'") && str_ends_with($value, $quote)) {
                return substr($value, 1, -1);
            }
        }

        return $value;
    }
}
