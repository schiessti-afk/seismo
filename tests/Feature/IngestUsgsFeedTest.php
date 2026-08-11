<?php

declare(strict_types=1);

use App\Jobs\BackfillSeismicData;
use App\Jobs\FetchLatestSeismicData;
use App\Models\Earthquake;
use App\Services\Usgs\IngestUsgsFeed;
use App\Support\BackfillState;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

function usgsFixturePath(): string
{
    return base_path('tests/Fixtures/usgs_all_hour_sample.geojson');
}

function usgsFixturePayload(): array
{
    /** @var array<string, mixed> $payload */
    $payload = json_decode(
        (string) file_get_contents(usgsFixturePath()),
        true,
        512,
        JSON_THROW_ON_ERROR,
    );

    return $payload;
}

function fakeUsgsFeed(string $url, array $payload, int $status = 200): void
{
    Http::fake([
        $url => Http::response($payload, $status),
    ]);
}

beforeEach(function (): void {
    BackfillState::clearMarker();
    Cache::forget(BackfillState::LOCK_KEY);
});

it('ingests USGS features idempotently with raw json preserved', function (): void {
    $url = 'https://example.test/all_hour.geojson';
    fakeUsgsFeed($url, usgsFixturePayload());

    $ingest = app(IngestUsgsFeed::class);

    $first = $ingest->ingest($url);
    $second = $ingest->ingest($url);

    expect($first->successful)->toBeTrue()
        ->and($first->upserted)->toBe(2)
        ->and($first->skipped)->toBe(1)
        ->and($second->successful)->toBeTrue()
        ->and($second->upserted)->toBe(2)
        ->and(Earthquake::query()->count())->toBe(2);

    $earthquake = Earthquake::query()->where('usgs_id', 'us7000test1')->firstOrFail();
    $originalRecordedAt = $earthquake->recorded_at->toIso8601String();

    expect($earthquake->magnitude)->toBe('4.50')
        ->and($earthquake->raw)->toBeArray()
        ->and($earthquake->raw['id'])->toBe('us7000test1')
        ->and($earthquake->latitude)->toBe(35.65)
        ->and($earthquake->longitude)->toBe(-117.65);

    $ingest->ingest($url);

    $earthquake = Earthquake::query()->where('usgs_id', 'us7000test1')->firstOrFail();

    expect($earthquake->recorded_at->toIso8601String())->toBe($originalRecordedAt);
});

it('updates material fields while keeping recorded_at stable', function (): void {
    $url = 'https://example.test/all_hour.geojson';

    $updatedPayload = usgsFixturePayload();
    $updatedPayload['features'][0]['properties']['mag'] = 4.8;
    $updatedPayload['features'][0]['properties']['place'] = '15 km NE of Ridgecrest, CA';

    Http::fake([
        $url => Http::sequence()
            ->push(usgsFixturePayload())
            ->push($updatedPayload),
    ]);

    $ingest = app(IngestUsgsFeed::class);
    $ingest->ingest($url);

    $earthquake = Earthquake::query()->where('usgs_id', 'us7000test1')->firstOrFail();
    $recordedAt = $earthquake->recorded_at->toIso8601String();

    $ingest->ingest($url);

    $earthquake = Earthquake::query()->where('usgs_id', 'us7000test1')->firstOrFail();

    expect($earthquake->magnitude)->toBe('4.80')
        ->and($earthquake->place)->toBe('15 km NE of Ridgecrest, CA')
        ->and($earthquake->recorded_at->toIso8601String())->toBe($recordedAt);
});

it('soft-fails on USGS HTTP errors without throwing', function (): void {
    $url = 'https://example.test/all_hour.geojson';

    Http::fake([
        $url => Http::response('Server Error', 500),
    ]);

    $result = app(IngestUsgsFeed::class)->ingest($url);

    expect($result->successful)->toBeFalse()
        ->and($result->upserted)->toBe(0)
        ->and(Earthquake::query()->count())->toBe(0);
});

it('sets backfill marker only after successful backfill job', function (): void {
    $backfillUrl = (string) config('seismo.usgs_backfill_feed_url');
    fakeUsgsFeed($backfillUrl, usgsFixturePayload());

    expect(BackfillState::isComplete())->toBeFalse();

    (new BackfillSeismicData)->handle(app(IngestUsgsFeed::class));

    expect(BackfillState::isComplete())->toBeTrue()
        ->and(Earthquake::query()->count())->toBe(2);
});

it('leaves backfill marker unset when USGS request fails', function (): void {
    $backfillUrl = (string) config('seismo.usgs_backfill_feed_url');

    Http::fake([
        $backfillUrl => Http::response('Server Error', 500),
    ]);

    (new BackfillSeismicData)->handle(app(IngestUsgsFeed::class));

    expect(BackfillState::isComplete())->toBeFalse()
        ->and(Earthquake::query()->count())->toBe(0);
});

it('soft-fails live ingest job on HTTP errors', function (): void {
    $liveUrl = (string) config('seismo.usgs_live_feed_url');

    Http::fake([
        $liveUrl => Http::response('Server Error', 500),
    ]);

    expect(fn () => (new FetchLatestSeismicData)->handle(app(IngestUsgsFeed::class)))
        ->not->toThrow(Throwable::class);

    expect(Earthquake::query()->count())->toBe(0);
});

it('skips backfill job when marker is already set', function (): void {
    BackfillState::markComplete();

    $backfillUrl = (string) config('seismo.usgs_backfill_feed_url');
    fakeUsgsFeed($backfillUrl, usgsFixturePayload());

    (new BackfillSeismicData)->handle(app(IngestUsgsFeed::class));

    expect(Earthquake::query()->count())->toBe(0);
});
