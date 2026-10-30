<?php

declare(strict_types=1);

namespace YummyPizza;

use PDO;
use PDOStatement;
use RuntimeException;

final class SyncReport
{
    public function __construct(
        public readonly int $newLocations,
        public readonly int $refreshedLocations,
        public readonly int $newVisits,
        public readonly int $refreshedVisits,
        public readonly int $removedVisits,
        public readonly int $removedLocations,
    ) {
    }

    public function summary(): string
    {
        return implode(PHP_EOL, [
            'New locations: ' . $this->newLocations,
            'Refreshed locations: ' . $this->refreshedLocations,
            'New visits: ' . $this->newVisits,
            'Refreshed visits: ' . $this->refreshedVisits,
            'Removed visits: ' . $this->removedVisits,
            'Removed locations: ' . $this->removedLocations,
        ]) . PHP_EOL;
    }
}

final class Importer
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function sync(ParseResult $parsed): SyncReport
    {
        if (count($parsed->places) < 50) {
            throw new RuntimeException(
                'Refusing to update the database because only ' . count($parsed->places) . ' places were parsed.'
            );
        }

        $this->pdo->beginTransaction();
        try {
            $report = $this->write($parsed->places);
            $this->pdo->commit();

            return $report;
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * @param list<Place> $places
     */
    private function write(array $places): SyncReport
    {
        /** @var array<string, int> $locationIds */
        $locationIds = [];
        foreach ($this->pdo->query('SELECT id, unique_location FROM location') as $row) {
            $locationIds[(string) $row['unique_location']] = (int) $row['id'];
        }

        /** @var array<string, true> $existingVisits */
        $existingVisits = [];
        foreach ($this->pdo->query('SELECT unique_visit FROM visit') as $row) {
            $existingVisits[(string) $row['unique_visit']] = true;
        }

        $insertLocation = $this->pdo->prepare(
            'INSERT INTO location (unique_location, name, grade, latitude, longitude, google_place_id, google_business_status, google_status_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $updateLocation = $this->pdo->prepare(
            'UPDATE location
             SET name = ?, grade = ?, latitude = ?, longitude = ?,
                 google_place_id = COALESCE(?, google_place_id),
                 google_business_status = COALESCE(?, google_business_status),
                 google_status_at = COALESCE(?, google_status_at)
             WHERE id = ?'
        );
        $insertVisit = $this->pdo->prepare(
            'INSERT INTO visit (location, `date`, description, quality, price, unique_visit) VALUES (?, ?, ?, ?, ?, ?)'
        );
        $updateVisit = $this->pdo->prepare(
            'UPDATE visit SET location = ?, `date` = ?, description = ?, quality = ?, price = ? WHERE unique_visit = ?'
        );

        $newLocations = 0;
        $refreshedLocations = 0;
        $newVisits = 0;
        $refreshedVisits = 0;
        /** @var array<string, true> $seenLocations */
        $seenLocations = [];
        /** @var array<string, true> $seenVisits */
        $seenVisits = [];

        foreach ($places as $place) {
            $seenLocations[$place->uniqueLocation] = true;
            if (isset($locationIds[$place->uniqueLocation])) {
                $locationId = $locationIds[$place->uniqueLocation];
                $updateLocation->bindValue(1, $place->name);
                $updateLocation->bindValue(2, $place->grade, PDO::PARAM_INT);
                $updateLocation->bindValue(3, $place->latitude);
                $updateLocation->bindValue(4, $place->longitude);
                $this->bindPlaceId($updateLocation, 5, $place->googlePlaceId);
                $this->bindPlaceId($updateLocation, 6, $place->googleBusinessStatus);
                $this->bindPlaceId($updateLocation, 7, $place->googleStatusAt);
                $updateLocation->bindValue(8, $locationId, PDO::PARAM_INT);
                $updateLocation->execute();
                $refreshedLocations++;
            } else {
                $insertLocation->bindValue(1, $place->uniqueLocation);
                $insertLocation->bindValue(2, $place->name);
                $insertLocation->bindValue(3, $place->grade, PDO::PARAM_INT);
                $insertLocation->bindValue(4, $place->latitude);
                $insertLocation->bindValue(5, $place->longitude);
                $this->bindPlaceId($insertLocation, 6, $place->googlePlaceId);
                $this->bindPlaceId($insertLocation, 7, $place->googleBusinessStatus);
                $this->bindPlaceId($insertLocation, 8, $place->googleStatusAt);
                $insertLocation->execute();
                $locationId = (int) $this->pdo->lastInsertId();
                $locationIds[$place->uniqueLocation] = $locationId;
                $newLocations++;
            }

            foreach ($place->visits as $visit) {
                $seenVisits[$visit->uniqueVisit] = true;
                if (isset($existingVisits[$visit->uniqueVisit])) {
                    $this->executeVisit($updateVisit, $locationId, $visit);
                    $refreshedVisits++;
                    continue;
                }
                $this->executeVisit($insertVisit, $locationId, $visit);
                $existingVisits[$visit->uniqueVisit] = true;
                $newVisits++;
            }
        }

        if ($seenLocations === [] || $seenVisits === []) {
            throw new RuntimeException('Refusing to update because the import produced no visits.');
        }

        $removedVisits = $this->deleteMissing('visit', 'unique_visit', array_keys($seenVisits));
        $removedLocations = $this->deleteMissing('location', 'unique_location', array_keys($seenLocations));

        return new SyncReport(
            $newLocations,
            $refreshedLocations,
            $newVisits,
            $refreshedVisits,
            $removedVisits,
            $removedLocations,
        );
    }

    private function bindPlaceId(PDOStatement $statement, int $index, ?string $placeId): void
    {
        if ($placeId === null) {
            $statement->bindValue($index, null, PDO::PARAM_NULL);
            return;
        }

        $statement->bindValue($index, $placeId);
    }

    private function executeVisit(PDOStatement $statement, int $locationId, Visit $visit): void
    {
        $statement->bindValue(1, $locationId, PDO::PARAM_INT);
        if ($visit->date === null) {
            $statement->bindValue(2, null, PDO::PARAM_NULL);
        } else {
            $statement->bindValue(2, $visit->date);
        }
        $statement->bindValue(3, $visit->description);
        $statement->bindValue(4, $visit->quality, PDO::PARAM_INT);
        $statement->bindValue(5, $visit->price, PDO::PARAM_INT);
        $statement->bindValue(6, $visit->uniqueVisit);
        $statement->execute();
    }

    /**
     * @param list<string> $keep
     */
    private function deleteMissing(string $table, string $column, array $keep): int
    {
        $placeholders = implode(', ', array_fill(0, count($keep), '?'));
        $statement = $this->pdo->prepare(
            'DELETE FROM ' . $table . ' WHERE ' . $column . ' NOT IN (' . $placeholders . ')'
        );
        $statement->execute($keep);

        return $statement->rowCount();
    }
}
