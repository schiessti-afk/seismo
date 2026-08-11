<?php

declare(strict_types=1);

namespace App\Services\Usgs;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

final class UsgsFeatureParser
{
    /**
     * @param  array<string, mixed>  $feature
     * @return array<string, mixed>|null
     */
    public function toAttributes(array $feature): ?array
    {
        $usgsId = $feature['id'] ?? null;

        if (! is_string($usgsId) || $usgsId === '') {
            Log::warning('USGS feature missing id', ['feature' => $feature]);

            return null;
        }

        /** @var array<string, mixed>|null $geometry */
        $geometry = $feature['geometry'] ?? null;

        if (! is_array($geometry) || ($geometry['type'] ?? null) !== 'Point') {
            Log::warning('USGS feature has invalid geometry', ['usgs_id' => $usgsId]);

            return null;
        }

        /** @var list<mixed>|null $coordinates */
        $coordinates = $geometry['coordinates'] ?? null;

        if (! is_array($coordinates) || count($coordinates) < 2) {
            Log::warning('USGS feature has missing coordinates', ['usgs_id' => $usgsId]);

            return null;
        }

        $longitude = is_numeric($coordinates[0]) ? (float) $coordinates[0] : null;
        $latitude = is_numeric($coordinates[1]) ? (float) $coordinates[1] : null;
        $depthKm = isset($coordinates[2]) && is_numeric($coordinates[2])
            ? (float) $coordinates[2]
            : null;

        if ($longitude === null || $latitude === null) {
            Log::warning('USGS feature has non-numeric coordinates', ['usgs_id' => $usgsId]);

            return null;
        }

        if ($longitude < -180 || $longitude > 180 || $latitude < -90 || $latitude > 90) {
            Log::warning('USGS feature has out-of-range coordinates', [
                'usgs_id' => $usgsId,
                'longitude' => $longitude,
                'latitude' => $latitude,
            ]);

            return null;
        }

        /** @var array<string, mixed> $properties */
        $properties = is_array($feature['properties'] ?? null) ? $feature['properties'] : [];

        $occurredAt = $this->parseTimestamp($properties['time'] ?? null);
        $usgsUpdatedAt = $this->parseTimestamp($properties['updated'] ?? null);

        if ($occurredAt === null) {
            Log::warning('USGS feature missing occurred_at', ['usgs_id' => $usgsId]);

            return null;
        }

        $magnitude = isset($properties['mag']) && is_numeric($properties['mag'])
            ? (float) $properties['mag']
            : null;

        return [
            'usgs_id' => $usgsId,
            'magnitude' => $magnitude,
            'mag_type' => isset($properties['magType']) ? (string) $properties['magType'] : null,
            'place' => isset($properties['place']) ? (string) $properties['place'] : null,
            'depth_km' => $depthKm,
            'tsunami' => (int) ($properties['tsunami'] ?? 0) === 1,
            'status' => isset($properties['status']) ? (string) $properties['status'] : null,
            'url' => isset($properties['url']) ? (string) $properties['url'] : null,
            'occurred_at' => $occurredAt,
            'usgs_updated_at' => $usgsUpdatedAt,
            'latitude' => $latitude,
            'longitude' => $longitude,
            'raw' => $feature,
        ];
    }

    private function parseTimestamp(mixed $value): ?Carbon
    {
        if (! is_numeric($value)) {
            return null;
        }

        return Carbon::createFromTimestampMs((int) $value)->utc();
    }
}
