<?php

declare(strict_types=1);

namespace App\Services\Usgs;

use App\Models\Earthquake;
use Illuminate\Support\Facades\Log;

final class IngestUsgsFeed
{
    public function __construct(
        private readonly UsgsFeedClient $client,
        private readonly UsgsFeatureParser $parser,
    ) {}

    public function ingest(string $url, int $timeoutSeconds = 30): IngestResult
    {
        $payload = $this->client->fetch($url, $timeoutSeconds);

        if ($payload === null) {
            return new IngestResult(successful: false, upserted: 0, skipped: 0);
        }

        /** @var list<array<string, mixed>> $features */
        $features = is_array($payload['features'] ?? null) ? $payload['features'] : [];

        $upserted = 0;
        $skipped = 0;

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

            $this->upsertEarthquake($attributes);
            $upserted++;
        }

        Log::info('USGS feed ingested', [
            'url' => $url,
            'upserted' => $upserted,
            'skipped' => $skipped,
        ]);

        return new IngestResult(successful: true, upserted: $upserted, skipped: $skipped);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function upsertEarthquake(array $attributes): void
    {
        /** @var Earthquake $earthquake */
        $earthquake = Earthquake::query()
            ->withoutGlobalScope('withCoordinates')
            ->firstOrNew([
                'usgs_id' => $attributes['usgs_id'],
            ]);

        if (! $earthquake->exists) {
            $attributes['recorded_at'] = now();
        }

        $earthquake->fill($attributes);
        $earthquake->save();
    }
}
