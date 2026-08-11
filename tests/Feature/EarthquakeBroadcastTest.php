<?php

declare(strict_types=1);

use App\Events\EarthquakeDetected;
use App\Jobs\BackfillSeismicData;
use App\Jobs\FetchLatestSeismicData;
use App\Models\Earthquake;
use App\Services\Usgs\IngestUsgsFeed;
use App\Support\BackfillState;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;

function broadcastFixturePath(): string
{
    return base_path('tests/Fixtures/usgs_all_hour_sample.geojson');
}

/**
 * @return array<string, mixed>
 */
function broadcastFixturePayload(): array
{
    /** @var array<string, mixed> $payload */
    $payload = json_decode(
        (string) file_get_contents(broadcastFixturePath()),
        true,
        512,
        JSON_THROW_ON_ERROR,
    );

    return $payload;
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function singleFeatureCollection(array $overrides = []): array
{
    $base = broadcastFixturePayload()['features'][0];

    /** @var array<string, mixed> $feature */
    $feature = array_replace_recursive($base, $overrides);

    return [
        'type' => 'FeatureCollection',
        'features' => [$feature],
    ];
}

beforeEach(function (): void {
    BackfillState::clearMarker();
    Cache::forget(BackfillState::LOCK_KEY);
    Event::fake([EarthquakeDetected::class]);
});

it('broadcasts on live ingest of a new M>=2.5 event', function (): void {
    $url = (string) config('seismo.usgs_live_feed_url');

    Http::fake([
        $url => Http::response(singleFeatureCollection([
            'id' => 'us7000bcast1',
            'properties' => ['mag' => 4.5],
        ])),
    ]);

    $result = app(IngestUsgsFeed::class)->ingest($url, broadcast: true);

    expect($result->successful)->toBeTrue()
        ->and($result->broadcasts)->toBe(1);

    Event::assertDispatched(EarthquakeDetected::class, function (EarthquakeDetected $event): bool {
        $payload = $event->broadcastWith();

        return $payload['usgs_id'] === 'us7000bcast1'
            && $payload['magnitude'] === 4.5
            && isset($payload['lat'], $payload['lon'], $payload['occurred_at']);
    });
});

it('does not broadcast on live ingest of a new M<2.5 event', function (): void {
    $url = (string) config('seismo.usgs_live_feed_url');

    Http::fake([
        $url => Http::response(singleFeatureCollection([
            'id' => 'us7000small1',
            'properties' => ['mag' => 1.2],
        ])),
    ]);

    $result = app(IngestUsgsFeed::class)->ingest($url, broadcast: true);

    expect($result->broadcasts)->toBe(0);
    Event::assertNotDispatched(EarthquakeDetected::class);
});

it('does not broadcast on no-op upsert of an identical M>=2.5 event', function (): void {
    $url = (string) config('seismo.usgs_live_feed_url');
    $payload = singleFeatureCollection([
        'id' => 'us7000noop1',
        'properties' => ['mag' => 4.5],
    ]);

    Http::fake([
        $url => Http::response($payload),
    ]);

    $ingest = app(IngestUsgsFeed::class);
    $ingest->ingest($url, broadcast: true);

    Event::fake([EarthquakeDetected::class]);

    $second = $ingest->ingest($url, broadcast: true);

    expect($second->upserted)->toBe(1)
        ->and($second->broadcasts)->toBe(0);

    Event::assertNotDispatched(EarthquakeDetected::class);
});

it('broadcasts on material revision of an M>=2.5 event', function (): void {
    $url = (string) config('seismo.usgs_live_feed_url');

    $initial = singleFeatureCollection([
        'id' => 'us7000rev1',
        'properties' => [
            'mag' => 4.5,
            'place' => '10 km NE of Ridgecrest, CA',
        ],
    ]);

    $revised = singleFeatureCollection([
        'id' => 'us7000rev1',
        'properties' => [
            'mag' => 4.8,
            'place' => '15 km NE of Ridgecrest, CA',
        ],
    ]);

    Http::fake([
        $url => Http::sequence()
            ->push($initial)
            ->push($revised),
    ]);

    $ingest = app(IngestUsgsFeed::class);
    $ingest->ingest($url, broadcast: true);

    Event::fake([EarthquakeDetected::class]);

    $result = $ingest->ingest($url, broadcast: true);

    expect($result->broadcasts)->toBe(1);

    Event::assertDispatched(EarthquakeDetected::class, function (EarthquakeDetected $event): bool {
        $payload = $event->broadcastWith();

        return $payload['usgs_id'] === 'us7000rev1'
            && $payload['magnitude'] === 4.8
            && $payload['place'] === '15 km NE of Ridgecrest, CA';
    });
});

it('does not broadcast null magnitude events', function (): void {
    $url = (string) config('seismo.usgs_live_feed_url');

    Http::fake([
        $url => Http::response(singleFeatureCollection([
            'id' => 'us7000nullmag',
            'properties' => ['mag' => null],
        ])),
    ]);

    $result = app(IngestUsgsFeed::class)->ingest($url, broadcast: true);

    expect($result->upserted)->toBe(1)
        ->and($result->broadcasts)->toBe(0);

    Event::assertNotDispatched(EarthquakeDetected::class);
});

it('never broadcasts from the backfill job', function (): void {
    $backfillUrl = (string) config('seismo.usgs_backfill_feed_url');

    Http::fake([
        $backfillUrl => Http::response(broadcastFixturePayload()),
    ]);

    (new BackfillSeismicData)->handle(app(IngestUsgsFeed::class));

    expect(Earthquake::query()->count())->toBe(2);
    Event::assertNotDispatched(EarthquakeDetected::class);
});

it('enables broadcast when the live job runs', function (): void {
    $liveUrl = (string) config('seismo.usgs_live_feed_url');

    Http::fake([
        $liveUrl => Http::response(singleFeatureCollection([
            'id' => 'us7000livejob',
            'properties' => ['mag' => 3.2],
        ])),
    ]);

    (new FetchLatestSeismicData)->handle(app(IngestUsgsFeed::class));

    Event::assertDispatched(EarthquakeDetected::class, function (EarthquakeDetected $event): bool {
        return $event->broadcastWith()['usgs_id'] === 'us7000livejob';
    });
});
