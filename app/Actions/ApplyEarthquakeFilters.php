<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\Earthquake;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

class ApplyEarthquakeFilters
{
    /**
     * @return Builder<Earthquake>
     */
    public function __invoke(
        float $minMagnitude = 0,
        ?float $maxMagnitude = null,
        ?float $minDepth = null,
        ?float $maxDepth = null,
        ?float $centerLat = null,
        ?float $centerLon = null,
        ?float $radiusKm = null,
        string $tsunami = 'all',
        ?string $place = null,
        ?Carbon $occurredFrom = null,
        ?Carbon $occurredTo = null,
        string $sort = 'occurred',
    ): Builder {
        $query = Earthquake::query();

        $query->magnitudeBetween($minMagnitude, $maxMagnitude);

        if ($minDepth !== null || $maxDepth !== null) {
            $query->depthBetween($minDepth, $maxDepth);
        }

        if ($occurredFrom !== null && $occurredTo !== null) {
            $query->occurredBetween($occurredFrom, $occurredTo);
        }

        if ($centerLat !== null && $centerLon !== null && $radiusKm !== null && $radiusKm > 0) {
            $query->withinRadius($centerLat, $centerLon, $radiusKm);
        }

        $query->tsunami($tsunami);
        $query->placeLike($place);

        if ($sort === 'magnitude') {
            $query->orderByMagnitudeDesc();
        } else {
            $query->orderByOccurredDesc();
        }

        return $query;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function payloadMatches(
        array $payload,
        float $minMagnitude,
        ?float $maxMagnitude,
        ?float $minDepth,
        ?float $maxDepth,
        ?float $centerLat,
        ?float $centerLon,
        ?float $radiusKm,
        string $tsunami,
        ?string $place,
        Carbon $occurredFrom,
        Carbon $occurredTo,
    ): bool {
        $mag = isset($payload['magnitude']) ? (float) $payload['magnitude'] : null;

        if ($mag === null || $mag < $minMagnitude) {
            return false;
        }

        if ($maxMagnitude !== null && $mag > $maxMagnitude) {
            return false;
        }

        $depth = isset($payload['depth_km']) ? (float) $payload['depth_km'] : null;

        if ($minDepth !== null && ($depth === null || $depth < $minDepth)) {
            return false;
        }

        if ($maxDepth !== null && ($depth === null || $depth > $maxDepth)) {
            return false;
        }

        $tsunamiFlag = (bool) ($payload['tsunami'] ?? false);

        if ($tsunami === 'yes' && ! $tsunamiFlag) {
            return false;
        }

        if ($tsunami === 'no' && $tsunamiFlag) {
            return false;
        }

        if ($place !== null && $place !== '') {
            $placeStr = (string) ($payload['place'] ?? '');

            if (stripos($placeStr, $place) === false) {
                return false;
            }
        }

        $occurredAt = Carbon::parse((string) $payload['occurred_at']);

        if (! $occurredAt->between($occurredFrom, $occurredTo)) {
            return false;
        }

        if ($centerLat !== null && $centerLon !== null && $radiusKm !== null && $radiusKm > 0) {
            $lat = $payload['lat'] ?? $payload['latitude'] ?? null;
            $lon = $payload['lon'] ?? $payload['longitude'] ?? null;

            if ($lat === null || $lon === null) {
                return false;
            }

            if ($this->distanceKm($centerLat, $centerLon, (float) $lat, (float) $lon) > $radiusKm) {
                return false;
            }
        }

        return true;
    }

    private function distanceKm(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $earthRadiusKm = 6371.0;
        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);
        $a = sin($dLat / 2) ** 2
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon / 2) ** 2;

        return $earthRadiusKm * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }
}
