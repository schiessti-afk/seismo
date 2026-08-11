<?php

declare(strict_types=1);

use App\Jobs\BackfillSeismicData;
use App\Models\Earthquake;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;

it('persists an earthquake from the factory with raw json and coordinates', function (): void {
    $earthquake = Earthquake::factory()->create([
        'usgs_id' => 'us7000abc12',
        'magnitude' => 4.2,
    ]);

    $earthquake->refresh();

    expect($earthquake->usgs_id)->toBe('us7000abc12')
        ->and($earthquake->magnitude)->toBe('4.20')
        ->and($earthquake->raw)->toBeArray()
        ->and($earthquake->raw['type'])->toBe('Feature')
        ->and($earthquake->latitude)->toBeFloat()
        ->and($earthquake->longitude)->toBeFloat();

    $this->assertDatabaseHas('earthquakes', [
        'usgs_id' => 'us7000abc12',
    ]);
});

it('enforces unique usgs_id', function (): void {
    Earthquake::factory()->create(['usgs_id' => 'us7000dup01']);

    expect(fn () => Earthquake::factory()->create(['usgs_id' => 'us7000dup01']))
        ->toThrow(\Illuminate\Database\QueryException::class);
});

it('filters earthquakes within a spatial radius', function (): void {
    $near = Earthquake::factory()->create([
        'usgs_id' => 'us7000near01',
        'latitude' => 34.0522,
        'longitude' => -118.2437,
        'place' => 'Los Angeles, CA',
    ]);

    Earthquake::factory()->create([
        'usgs_id' => 'us7000far01',
        'latitude' => 51.5074,
        'longitude' => -0.1278,
        'place' => 'London, UK',
    ]);

    $results = Earthquake::query()
        ->withinRadius(34.0522, -118.2437, 100)
        ->get();

    expect($results)->toHaveCount(1)
        ->and($results->first()?->is($near))->toBeTrue();
});

it('applies magnitude and occurred_at filter scopes', function (): void {
    Earthquake::factory()->create([
        'usgs_id' => 'us7000low01',
        'magnitude' => 2.1,
        'occurred_at' => Carbon::parse('2026-01-01 12:00:00'),
    ]);

    Earthquake::factory()->create([
        'usgs_id' => 'us7000hit01',
        'magnitude' => 4.5,
        'occurred_at' => Carbon::parse('2026-01-02 12:00:00'),
    ]);

    $results = Earthquake::query()
        ->magnitudeBetween(2.5, null)
        ->occurredBetween(
            Carbon::parse('2026-01-02 00:00:00'),
            Carbon::parse('2026-01-02 23:59:59'),
        )
        ->orderByOccurredDesc()
        ->get();

    expect($results)->toHaveCount(1)
        ->and($results->first()?->usgs_id)->toBe('us7000hit01');
});

it('dispatches the backfill job from artisan command', function (): void {
    Bus::fake();

    $this->artisan('seismo:backfill')
        ->expectsOutputToContain('Backfill job dispatched')
        ->assertSuccessful();

    Bus::assertDispatched(BackfillSeismicData::class);
});
