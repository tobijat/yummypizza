<?php

declare(strict_types=1);

require __DIR__ . '/src/bootstrap.php';

use YummyPizza\Config;
use YummyPizza\Database;
use YummyPizza\PizzaFinder;

ini_set('display_errors', '0');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

try {
    $latitude = readCoordinate('lat', 'curlat', -90, 90);
    $longitude = readCoordinate('lon', 'curlon', -180, 180);
    $radius = readRadius();

    $config = Config::load(__DIR__ . '/config.yml');
    $pdo = Database::connect($config);
    Database::ensureSchema($pdo);
    $finder = new PizzaFinder($pdo);
    echo json_encode(
        $finder->nearby($latitude, $longitude, $radius),
        JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR,
    );
} catch (InvalidArgumentException $e) {
    http_response_code(400);
    echo json_encode([
        'error' => true,
        'message' => $e->getMessage(),
        'places' => [],
    ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
} catch (Throwable $e) {
    error_log($e::class . ': ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'error' => true,
        'message' => 'Could not load pizza places.',
        'places' => [],
    ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
}

function readCoordinate(string $name, string $legacy, float $min, float $max): float
{
    $raw = $_GET[$name] ?? $_POST[$name] ?? $_GET[$legacy] ?? $_POST[$legacy] ?? null;
    if ($raw === null || $raw === '' || !is_numeric($raw)) {
        throw new InvalidArgumentException('Request failed: curlat and curlon need to be set!');
    }

    $value = (float) $raw;
    if ($value < $min || $value > $max) {
        throw new InvalidArgumentException('Request failed: curlat and curlon need to be set!');
    }

    return $value;
}

function readRadius(): float
{
    $raw = $_GET['radius'] ?? $_POST['radius'] ?? 5;
    if (!is_numeric($raw)) {
        throw new InvalidArgumentException('Radius must be a number.');
    }

    $radius = (float) $raw;
    if ($radius < 0.5 || $radius > 10) {
        throw new InvalidArgumentException('Radius must be between 0.5 and 10 km.');
    }

    return $radius;
}
