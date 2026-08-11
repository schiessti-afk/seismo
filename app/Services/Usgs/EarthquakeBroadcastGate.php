<?php

declare(strict_types=1);

namespace App\Services\Usgs;

use App\Models\Earthquake;

final class EarthquakeBroadcastGate
{
    public function passesMagnitudeGate(?float $magnitude): bool
    {
        if ($magnitude === null) {
            return false;
        }

        return $magnitude >= (float) config('seismo.broadcast_min_magnitude');
    }

    /**
     * @param  array<string, mixed>  $incoming
     */
    public function shouldBroadcast(?Earthquake $existing, array $incoming, bool $isNew): bool
    {
        $magnitude = isset($incoming['magnitude']) && is_numeric($incoming['magnitude'])
            ? (float) $incoming['magnitude']
            : null;

        if (! $this->passesMagnitudeGate($magnitude)) {
            return false;
        }

        if ($isNew || $existing === null) {
            return true;
        }

        return $this->hasMaterialChange($existing, $incoming);
    }

    /**
     * @param  array<string, mixed>  $incoming
     */
    private function hasMaterialChange(Earthquake $existing, array $incoming): bool
    {
        $existingMagnitude = $existing->magnitude !== null ? (float) $existing->magnitude : null;
        $incomingMagnitude = isset($incoming['magnitude']) && is_numeric($incoming['magnitude'])
            ? (float) $incoming['magnitude']
            : null;

        if (! $this->floatsEqual($existingMagnitude, $incomingMagnitude)) {
            return true;
        }

        if (! $this->floatsEqual($existing->latitude, $this->nullableFloat($incoming['latitude'] ?? null))) {
            return true;
        }

        if (! $this->floatsEqual($existing->longitude, $this->nullableFloat($incoming['longitude'] ?? null))) {
            return true;
        }

        if (! $this->floatsEqual($existing->depth_km, $this->nullableFloat($incoming['depth_km'] ?? null))) {
            return true;
        }

        if ((string) ($existing->place ?? '') !== (string) ($incoming['place'] ?? '')) {
            return true;
        }

        if ((bool) $existing->tsunami !== (bool) ($incoming['tsunami'] ?? false)) {
            return true;
        }

        return false;
    }

    private function nullableFloat(mixed $value): ?float
    {
        if ($value === null || ! is_numeric($value)) {
            return null;
        }

        return (float) $value;
    }

    private function floatsEqual(?float $a, ?float $b): bool
    {
        if ($a === null && $b === null) {
            return true;
        }

        if ($a === null || $b === null) {
            return false;
        }

        return abs($a - $b) < 0.0001;
    }
}
