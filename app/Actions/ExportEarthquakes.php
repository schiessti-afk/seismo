<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\Earthquake;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class ExportEarthquakes
{
    /**
     * @param  array{
     *     min_magnitude: float,
     *     max_magnitude: float|null,
     *     min_depth: float|null,
     *     max_depth: float|null,
     *     center_lat: float|null,
     *     center_lon: float|null,
     *     radius_km: float|null,
     *     tsunami: string,
     *     place: string|null,
     *     sort: string,
     *     occurred_from: Carbon,
     *     occurred_to: Carbon
     * }  $filters
     * @return array{rows: Collection<int, Earthquake>, truncated: bool}
     */
    public function __invoke(array $filters): array
    {
        $cap = (int) config('seismo.export_max_rows');
        $query = $this->buildQuery($filters);
        $total = (clone $query)->count();
        $truncated = $total > $cap;

        return [
            'rows' => $query->limit($cap)->get(),
            'truncated' => $truncated,
        ];
    }

    /**
     * @param  array{
     *     min_magnitude: float,
     *     max_magnitude: float|null,
     *     min_depth: float|null,
     *     max_depth: float|null,
     *     center_lat: float|null,
     *     center_lon: float|null,
     *     radius_km: float|null,
     *     tsunami: string,
     *     place: string|null,
     *     sort: string,
     *     occurred_from: Carbon,
     *     occurred_to: Carbon
     * }  $filters
     * @return Builder<Earthquake>
     */
    public function buildQuery(array $filters): Builder
    {
        return app(ApplyEarthquakeFilters::class)(
            minMagnitude: $filters['min_magnitude'],
            maxMagnitude: $filters['max_magnitude'],
            minDepth: $filters['min_depth'],
            maxDepth: $filters['max_depth'],
            centerLat: $filters['center_lat'],
            centerLon: $filters['center_lon'],
            radiusKm: $filters['radius_km'],
            tsunami: $filters['tsunami'],
            place: $filters['place'],
            occurredFrom: $filters['occurred_from'],
            occurredTo: $filters['occurred_to'],
            sort: $filters['sort'],
        );
    }

    /**
     * @return list<string>
     */
    public function csvHeaders(): array
    {
        return [
            'usgs_id',
            'magnitude',
            'mag_type',
            'place',
            'latitude',
            'longitude',
            'depth_km',
            'tsunami',
            'occurred_at',
            'recorded_at',
        ];
    }

    /**
     * @return list<int|float|string|null>
     */
    public function csvRow(Earthquake $earthquake): array
    {
        return [
            $earthquake->usgs_id,
            $earthquake->magnitude !== null ? (float) $earthquake->magnitude : null,
            $earthquake->mag_type,
            $earthquake->place,
            $earthquake->latitude,
            $earthquake->longitude,
            $earthquake->depth_km,
            $earthquake->tsunami ? 1 : 0,
            $earthquake->occurred_at->toIso8601String(),
            $earthquake->recorded_at->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function geoJsonFeature(Earthquake $earthquake): array
    {
        $raw = $earthquake->raw;

        if (
            ($raw['type'] ?? null) === 'Feature'
            && isset($raw['geometry'])
            && is_array($raw['geometry'])
        ) {
            return $raw;
        }

        return [
            'type' => 'Feature',
            'id' => $earthquake->usgs_id,
            'properties' => [
                'usgs_id' => $earthquake->usgs_id,
                'mag' => $earthquake->magnitude !== null ? (float) $earthquake->magnitude : null,
                'magType' => $earthquake->mag_type,
                'place' => $earthquake->place,
                'depth_km' => $earthquake->depth_km,
                'tsunami' => (int) $earthquake->tsunami,
                'occurred_at' => $earthquake->occurred_at->toIso8601String(),
                'recorded_at' => $earthquake->recorded_at->toIso8601String(),
            ],
            'geometry' => [
                'type' => 'Point',
                'coordinates' => [
                    $earthquake->longitude,
                    $earthquake->latitude,
                    $earthquake->depth_km,
                ],
            ],
        ];
    }

    /**
     * @param  Collection<int, Earthquake>  $rows
     * @return array<string, mixed>
     */
    public function geoJsonFeatureCollection(Collection $rows): array
    {
        return [
            'type' => 'FeatureCollection',
            'features' => $rows
                ->map(fn (Earthquake $earthquake): array => $this->geoJsonFeature($earthquake))
                ->values()
                ->all(),
        ];
    }
}
