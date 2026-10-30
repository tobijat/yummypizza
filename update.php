<?php

declare(strict_types=1);

require __DIR__ . '/src/bootstrap.php';

use YummyPizza\Config;
use YummyPizza\Database;
use YummyPizza\GooglePlaces;
use YummyPizza\Importer;
use YummyPizza\KmlParser;

$isCli = PHP_SAPI === 'cli';
$dryRun = $isCli && in_array('--dry-run', $argv ?? [], true);

if ($isCli && in_array('--help', $argv ?? [], true)) {
    echo "Usage: php update.php [--dry-run]\n";
    echo "Download the pizza map and refresh the database.\n";
    exit(0);
}

try {
    set_time_limit(300);
    $config = Config::load(__DIR__ . '/config.yml');
    $parser = new KmlParser();
    $parsed = $parser->parse($parser->download($config->kmlUrl));
    echo $parsed->summary();

    if ($dryRun) {
        exit(0);
    }

    $pdo = Database::connect($config);
    Database::ensureSchema($pdo);
    if ($config->googlePlacesKey !== '') {
        $parsed = (new GooglePlaces($config->googlePlacesKey))->attach($pdo, $parsed);
    }
    echo (new Importer($pdo))->sync($parsed)->summary();
} catch (Throwable $e) {
    $message = $e->getMessage() . PHP_EOL;
    if ($isCli) {
        fwrite(STDERR, $message);
    } else {
        if (!headers_sent()) {
            header('Content-Type: text/plain; charset=utf-8');
            http_response_code(500);
        }
        echo $message;
    }
    exit(1);
}
