<?php

declare(strict_types=1);

namespace App\Services\Usgs;

use App\Events\EarthquakeDetected;
use App\Models\Earthquake;
use Illuminate\Support\Facades\Log;

final class IngestUsgsFeed
{
    public function __construct(
        private readonly UsgsFeedClient $client,
        private readonly UsgsFeatureParser $parser,
        private readonly EarthquakeBroadcastGate $broadcastGate,
    ) {}

    public function ingest(string $url, int $timeoutSeconds = 30, bool $broadcast = false): IngestResult
    {
        $payload = $this->client->fetch($url, $timeoutSeconds);

        if ($payload === null) {
            return new IngestResult(successful: false, upserted: 0, skipped: 0, broadcasts: 0);
        }

        /** @var list<array<string, mixed>> $features */
        $features = is_array($payload['features'] ?? null) ? $payload['features'] : [];

        $upserted = 0;
        $skipped = 0;
        $broadcasts = 0;

        foreach ($features as $feature) {
            if (! is_array($feature)) {
                $skipped++;

                continue;
            }

            $attributes = $this->parser->toAttributes($feature);

            if ($attributes === null) {
                $skipped++;

                continue;
            }

            if ($this->upsertEarthquake($attributes, $broadcast)) {
                $broadcasts++;
            }

            $upserted++;
        }

        Log::info('USGS feed ingested', [
            'url' => $url,
            'upserted' => $upserted,
            'skipped' => $skipped,
            'broadcasts' => $broadcasts,
            'broadcast' => $broadcast,
        ]);

        return new IngestResult(
            successful: true,
            upserted: $upserted,
            skipped: $skipped,
            broadcasts: $broadcasts,
        );
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return bool True when an EarthquakeDetected event was dispatched
     */
    private function upsertEarthquake(array $attributes, bool $broadcast): bool
    {
        $usgsId = (string) $attributes['usgs_id'];

        /** @var Earthquake|null $existing */
        $existing = Earthquake::query()
            ->where('usgs_id', $usgsId)
            ->first();

        $isNew = $existing === null;

        /** @var Earthquake $earthquake */
        $earthquake = Earthquake::query()
            ->withoutGlobalScope('withCoordinates')
            ->firstOrNew([
                'usgs_id' => $usgsId,
            ]);

        if ($isNew) {
            $attributes['recorded_at'] = now();
        }

        $shouldBroadcast = $broadcast
            && $this->broadcastGate->shouldBroadcast($existing, $attributes, $isNew);

        $earthquake->fill($attributes);
        $earthquake->save();

        if (! $shouldBroadcast) {
            return false;
        }

        /** @var Earthquake $broadcastModel */
        $broadcastModel = Earthquake::query()
            ->where('usgs_id', $usgsId)
            ->firstOrFail();

        EarthquakeDetected::dispatch($broadcastModel);

        return true;
    }
}
