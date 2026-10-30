<?php

declare(strict_types=1);

namespace YummyPizza;

final class Visit
{
    public function __construct(
        public readonly ?string $date,
        public readonly string $description,
        public readonly int $quality,
        public readonly int $price,
        public readonly string $uniqueVisit,
    ) {
    }
}

final class Place
{
    /**
     * @param list<Visit> $visits
     */
    public function __construct(
        public readonly string $name,
        public readonly int $grade,
        public readonly float $latitude,
        public readonly float $longitude,
        public readonly string $uniqueLocation,
        public readonly array $visits,
        public readonly ?string $googlePlaceId = null,
        public readonly ?string $googleBusinessStatus = null,
        public readonly ?string $googleStatusAt = null,
    ) {
    }

    /**
     * @param list<Visit> $visits
     */
    public function withMoreVisits(array $visits): self
    {
        return new self(
            $this->name,
            $this->grade,
            $this->latitude,
            $this->longitude,
            $this->uniqueLocation,
            array_merge($this->visits, $visits),
            $this->googlePlaceId,
            $this->googleBusinessStatus,
            $this->googleStatusAt,
        );
    }

    public function withGooglePlaceId(?string $googlePlaceId, ?string $googleBusinessStatus = null, ?string $googleStatusAt = null): self
    {
        return new self(
            $this->name,
            $this->grade,
            $this->latitude,
            $this->longitude,
            $this->uniqueLocation,
            $this->visits,
            $googlePlaceId,
            $googleBusinessStatus,
            $googleStatusAt,
        );
    }
}
