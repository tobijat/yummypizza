<?php

declare(strict_types=1);

namespace YummyPizza;

use PDO;

final class GooglePlaces
{
    private const ENDPOINT = 'https://places.googleapis.com/v1/places:searchText';

    /** @var array<string, true> */
    private const STATUSES = [
        'OPERATIONAL' => true,
        'CLOSED_TEMPORARILY' => true,
        'CLOSED_PERMANENTLY' => true,
    ];

    public function __construct(private readonly string $apiKey)
    {
    }

    public function attach(PDO $pdo, ParseResult $parsed): ParseResult
    {
        /** @var array<string, array{id: string, status: ?string, checked: ?string}> $known */
        $known = [];
        foreach ($pdo->query(
            'SELECT unique_location, google_place_id, google_business_status, google_status_at
             FROM location
             WHERE google_place_id IS NOT NULL'
        ) as $row) {
            $known[(string) $row['unique_location']] = [
                'id' => (string) $row['google_place_id'],
                'status' => is_string($row['google_business_status']) ? $row['google_business_status'] : null,
                'checked' => is_string($row['google_status_at']) ? $row['google_status_at'] : null,
            ];
        }

        $matched = 0;
        $unmatched = 0;
        $kept = 0;
        $checked = 0;
        $closed = 0;
        $lookupFailed = false;
        $statusFailed = false;
        $places = [];
        $today = date('Y-m-d');

        foreach ($parsed->places as $place) {
            if (array_key_exists($place->uniqueLocation, $known)) {
                $stored = $known[$place->uniqueLocation];
                $status = null;
                $checkedAt = null;
                if ($stored['id'] !== '' && $this->statusIsStale($stored['checked']) && !$statusFailed) {
                    $status = $this->businessStatus($stored['id']);
                    if ($status === false) {
                        $statusFailed = true;
                        $status = null;
                    } else {
                        $checked++;
                        $checkedAt = $today;
                        usleep(50000);
                    }
                }
                if (($status ?? $stored['status']) === 'CLOSED_PERMANENTLY') {
                    $closed++;
                }
                $places[] = $place->withGooglePlaceId($stored['id'], $status, $checkedAt);
                $kept++;
                continue;
            }
            if ($lookupFailed) {
                $places[] = $place;
                continue;
            }

            $found = $this->find($place);
            if ($found === false) {
                $lookupFailed = true;
                $places[] = $place;
                continue;
            }

            $placeId = $found['id'];
            $status = $placeId === null ? null : $found['status'];
            $checkedAt = $placeId === null ? null : $today;
            $places[] = $place->withGooglePlaceId($placeId ?? '', $status, $checkedAt);
            if ($placeId === null) {
                $unmatched++;
            } else {
                $matched++;
                if ($status === 'CLOSED_PERMANENTLY') {
                    $closed++;
                }
            }
            usleep(50000);
        }

        echo 'Google places matched: ' . $matched . PHP_EOL;
        echo 'Google places unmatched: ' . $unmatched . PHP_EOL;
        echo 'Google places already known: ' . $kept . PHP_EOL;
        echo 'Google place statuses checked: ' . $checked . PHP_EOL;
        echo 'Google places permanently closed: ' . $closed . PHP_EOL;

        return new ParseResult($places, $parsed->skipped);
    }

    /**
     * @return array{id: ?string, status: ?string}|false
     */
    private function searchResult(Place $place): array|false
    {
        $body = json_encode([
            'textQuery' => $place->name,
            'pageSize' => 5,
            'locationBias' => [
                'circle' => [
                    'center' => [
                        'latitude' => $place->latitude,
                        'longitude' => $place->longitude,
                    ],
                    'radius' => 250,
                ],
            ],
        ], JSON_THROW_ON_ERROR);

        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => implode("\r\n", [
                    'Content-Type: application/json',
                    'X-Goog-Api-Key: ' . $this->apiKey,
                    'X-Goog-FieldMask: places.id,places.displayName,places.location,places.businessStatus',
                ]),
                'content' => $body,
                'timeout' => 20,
                'ignore_errors' => true,
            ],
        ]);

        $response = @file_get_contents(self::ENDPOINT, false, $context);
        $status = $this->statusCode($http_response_header ?? []);
        if (!is_string($response) || $status >= 400 || $status === 0) {
            $detail = 'Google Places lookup failed.';
            $decoded = is_string($response) ? json_decode($response, true) : null;
            if (is_array($decoded) && isset($decoded['error']['message']) && is_string($decoded['error']['message'])) {
                $detail = $decoded['error']['message'];
            }
            echo $detail . PHP_EOL;

            return false;
        }

        $decoded = json_decode($response, true);
        $candidates = is_array($decoded) && isset($decoded['places']) && is_array($decoded['places'])
            ? $decoded['places']
            : [];

        $bestId = null;
        $bestStatus = null;
        $bestDistance = INF;
        foreach ($candidates as $candidate) {
            if (!is_array($candidate)) {
                continue;
            }
            $id = $candidate['id'] ?? '';
            $name = $candidate['displayName']['text'] ?? '';
            $latitude = $candidate['location']['latitude'] ?? null;
            $longitude = $candidate['location']['longitude'] ?? null;
            if (!is_string($id) || !is_string($name) || !is_numeric($latitude) || !is_numeric($longitude)) {
                continue;
            }
            if (!$this->namesMatch($place->name, $name)) {
                continue;
            }
            $distance = $this->meters($place->latitude, $place->longitude, (float) $latitude, (float) $longitude);
            if ($distance > 250 || $distance >= $bestDistance) {
                continue;
            }
            $bestId = $id;
            $bestStatus = $this->normalizeStatus($candidate['businessStatus'] ?? null);
            $bestDistance = $distance;
        }

        return ['id' => $bestId, 'status' => $bestStatus];
    }

    /**
     * @return array{id: ?string, status: ?string}|false
     */
    private function find(Place $place): array|false
    {
        try {
            return $this->searchResult($place);
        } catch (\Throwable $e) {
            echo $e->getMessage() . PHP_EOL;

            return false;
        }
    }

    /**
     * @return string|null|false A status, null when Google has none, false when the lookup failed.
     */
    private function businessStatus(string $placeId): string|null|false
    {
        if (preg_match('/^ChIJ[\w-]{10,}$/', $placeId) !== 1) {
            return null;
        }

        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'header' => implode("\r\n", [
                    'X-Goog-Api-Key: ' . $this->apiKey,
                    'X-Goog-FieldMask: businessStatus',
                ]),
                'timeout' => 20,
                'ignore_errors' => true,
            ],
        ]);

        $response = @file_get_contents(
            'https://places.googleapis.com/v1/places/' . rawurlencode($placeId),
            false,
            $context,
        );
        $status = $this->statusCode($http_response_header ?? []);
        if (!is_string($response) || $status >= 400 || $status === 0) {
            $detail = 'Google Places status lookup failed.';
            $decoded = is_string($response) ? json_decode($response, true) : null;
            if (is_array($decoded) && isset($decoded['error']['message']) && is_string($decoded['error']['message'])) {
                $detail = $decoded['error']['message'];
            }
            echo $detail . PHP_EOL;

            return false;
        }

        $decoded = json_decode($response, true);

        return $this->normalizeStatus(is_array($decoded) ? ($decoded['businessStatus'] ?? null) : null);
    }

    private function normalizeStatus(mixed $status): ?string
    {
        if (!is_string($status) || !isset(self::STATUSES[$status])) {
            return null;
        }

        return $status;
    }

    private function statusIsStale(?string $checked): bool
    {
        if ($checked === null || preg_match('/^\d{4}-\d{2}-\d{2}$/', $checked) !== 1) {
            return true;
        }

        $checkedAt = strtotime($checked . ' 00:00:00');

        return $checkedAt === false || $checkedAt <= strtotime('-7 days');
    }

    /**
     * @param list<string> $headers
     */
    private function statusCode(array $headers): int
    {
        $status = 0;
        foreach ($headers as $header) {
            if (preg_match('/^HTTP\/\S+\s+(\d+)/', $header, $matches) === 1) {
                $status = (int) $matches[1];
            }
        }

        return $status;
    }

    private function namesMatch(string $left, string $right): bool
    {
        $shared = array_intersect($this->tokens($left), $this->tokens($right));
        foreach ($shared as $token) {
            if (strlen($token) >= 4) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    private function tokens(string $name): array
    {
        $name = function_exists('mb_strtolower') ? mb_strtolower($name, 'UTF-8') : strtolower($name);
        $name = strtr($name, ['ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss']);
        $name = preg_replace('/[^a-z0-9]+/', ' ', $name) ?? $name;
        $ignored = [
            'ristorante' => true,
            'trattoria' => true,
            'pizzeria' => true,
            'pizza' => true,
            'restaurant' => true,
            'cafe' => true,
            'bar' => true,
            'berlin' => true,
        ];
        $tokens = [];
        foreach (preg_split('/\s+/', trim($name)) ?: [] as $token) {
            if ($token !== '' && !isset($ignored[$token])) {
                $tokens[] = $token;
            }
        }

        return $tokens;
    }

    private function meters(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $earth = 6371000.0;
        $latDelta = deg2rad($lat2 - $lat1);
        $lonDelta = deg2rad($lon2 - $lon1);
        $a = sin($latDelta / 2) ** 2
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($lonDelta / 2) ** 2;

        return 2 * $earth * asin(min(1, sqrt($a)));
    }
}
