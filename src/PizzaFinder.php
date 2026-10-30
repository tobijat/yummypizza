<?php

declare(strict_types=1);

namespace YummyPizza;

use PDO;

final class PizzaFinder
{
    private const PINS = [
        1 => ['color' => 'blue', 'label' => 'blue'],
        2 => ['color' => 'green', 'label' => 'green'],
        3 => ['color' => 'yellow', 'label' => 'yellow'],
        4 => ['color' => 'red', 'label' => 'red'],
    ];

    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * @return array<string, mixed>
     */
    public function nearby(float $latitude, float $longitude, float $radiusKm): array
    {
        $rows = $this->search(
            'ranked.distance < ?',
            '((ranked.distance + ranked.rating) / 2) ASC',
            [$latitude, $longitude, $latitude, $radiusKm],
            11,
        );

        $truncated = count($rows) > 10;
        $places = array_map($this->place(...), array_slice($rows, 0, 10));
        if ($places !== []) {
            return [
                'error' => false,
                'message' => '',
                'source' => 'nearby',
                'radiusKm' => $radiusKm,
                'truncated' => $truncated,
                'places' => $places,
            ];
        }

        $best = array_map(
            $this->place(...),
            $this->search('ranked.grade = 1', 'ranked.distance ASC', [$latitude, $longitude, $latitude], 10),
        );
        if ($best === []) {
            return [
                'error' => false,
                'message' => 'No pizza places are loaded yet. Import the map with update.php.',
                'source' => 'empty',
                'radiusKm' => $radiusKm,
                'truncated' => false,
                'places' => [],
            ];
        }

        return [
            'error' => false,
            'message' => "Awww.. Seems there is no yummy pizza around you! But I'll just show you the most delicious pizza I have to offer.",
            'source' => 'best',
            'radiusKm' => $radiusKm,
            'truncated' => false,
            'places' => $best,
        ];
    }

    /**
     * @param list<float|int|string> $params
     * @return list<array<string, mixed>>
     */
    private function search(string $where, string $order, array $params, int $limit): array
    {
        $statement = $this->pdo->prepare($this->baseSql() . ' WHERE ' . $where . ' ORDER BY ' . $order . ' LIMIT ' . $limit);
        $statement->execute($params);
        $rows = $statement->fetchAll();

        return is_array($rows) ? $rows : [];
    }

    private function baseSql(): string
    {
        return <<<'SQL'
            SELECT
                ranked.name,
                ranked.grade,
                ranked.latitude,
                ranked.longitude,
                ranked.quality,
                ranked.price,
                ranked.visit_date,
                ranked.description,
                ranked.visit_count,
                ranked.distance,
                ranked.rating,
                ranked.google_place_id,
                ranked.google_business_status
            FROM (
                SELECT
                    l.name,
                    l.grade,
                    l.latitude,
                    l.longitude,
                    l.google_place_id,
                    l.google_business_status,
                    v.quality,
                    v.price,
                    v.`date` AS visit_date,
                    v.description,
                    (SELECT COUNT(*) FROM visit AS counted WHERE counted.location = l.id) AS visit_count,
                    (
                        6371 * ACOS(
                            GREATEST(-1, LEAST(1,
                                COS(RADIANS(?)) * COS(RADIANS(l.latitude))
                                * COS(RADIANS(l.longitude) - RADIANS(?))
                                + SIN(RADIANS(?)) * SIN(RADIANS(l.latitude))
                            ))
                        )
                    ) AS distance,
                    ((v.quality + v.price + l.grade) / 3) AS rating
                FROM location AS l
                INNER JOIN visit AS v ON v.id = (
                    SELECT latest.id
                    FROM visit AS latest
                    WHERE latest.location = l.id
                    ORDER BY (latest.`date` IS NULL), latest.`date` DESC, latest.id DESC
                    LIMIT 1
                )
            ) AS ranked
            SQL;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function place(array $row): array
    {
        $grade = (int) $row['grade'];
        $pin = self::PINS[$grade] ?? ['color' => 'red', 'label' => 'red'];
        $description = trim((string) ($row['description'] ?? ''));
        $visited = $row['visit_date'];
        if (!is_string($visited) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $visited) !== 1 || str_starts_with($visited, '0000')) {
            $visited = null;
        }

        return [
            'name' => (string) $row['name'],
            'grade' => $grade,
            'pinColor' => $pin['color'],
            'pinLabel' => $pin['label'],
            'distanceKm' => round((float) $row['distance'], 3),
            'rating' => round((float) $row['rating'], 1),
            'quality' => (int) $row['quality'],
            'price' => (int) $row['price'],
            'latitude' => (float) $row['latitude'],
            'longitude' => (float) $row['longitude'],
            'googlePlaceId' => $this->placeId($row['google_place_id'] ?? null),
            'permanentlyClosed' => ($row['google_business_status'] ?? '') === 'CLOSED_PERMANENTLY',
            'visitedOn' => $visited,
            'description' => $description === '' ? null : $description,
            'visits' => (int) $row['visit_count'],
        ];
    }

    private function placeId(mixed $value): ?string
    {
        if (!is_string($value) || preg_match('/^ChIJ[\w-]{10,}$/', $value) !== 1) {
            return null;
        }

        return $value;
    }

}
