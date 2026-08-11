<?php

declare(strict_types=1);

use App\Livewire\LiveMonitor;
use App\Models\Earthquake;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

function exportQueryParams(Carbon $from, Carbon $to, array $overrides = []): array
{
    return array_merge([
        'min_magnitude' => 2.5,
        'occurred_from' => $from->toIso8601String(),
        'occurred_to' => $to->toIso8601String(),
    ], $overrides);
}

it('exports csv with expected headers and row values', function (): void {
    $occurredAt = now()->subHours(2);

    Earthquake::factory()->create([
        'usgs_id' => 'us7000exportcsv',
        'magnitude' => 3.4,
        'mag_type' => 'ml',
        'place' => 'Export CSV Test',
        'depth_km' => 12.5,
        'latitude' => 35.1,
        'longitude' => -118.2,
        'tsunami' => false,
        'occurred_at' => $occurredAt,
        'recorded_at' => now(),
    ]);

    $response = $this->get(route('export.csv', exportQueryParams(now()->subDay(), now())));

    $response->assertOk();
    $response->assertHeader('content-type', 'text/csv; charset=UTF-8');
    $response->assertHeaderMissing('X-Seismo-Export-Truncated');

    $content = $response->streamedContent();

    expect($content)->toContain('usgs_id,magnitude,mag_type,place,latitude,longitude,depth_km,tsunami,occurred_at,recorded_at');
    expect($content)->toContain('us7000exportcsv');
    expect($content)->toContain('Export CSV Test');
    expect($content)->toContain('3.4');
});

it('exports geojson feature collection preferring stored raw features', function (): void {
    $occurredAt = now()->subHours(3);
    $raw = [
        'type' => 'Feature',
        'id' => 'us7000exportgeo',
        'properties' => ['mag' => 4.1, 'place' => 'Export GeoJSON Test'],
        'geometry' => [
            'type' => 'Point',
            'coordinates' => [-120.5, 37.8, 8.0],
        ],
    ];

    Earthquake::factory()->create([
        'usgs_id' => 'us7000exportgeo',
        'magnitude' => 4.1,
        'place' => 'Export GeoJSON Test',
        'occurred_at' => $occurredAt,
        'raw' => $raw,
    ]);

    $response = $this->get(route('export.geojson', exportQueryParams(now()->subDay(), now())));

    $response->assertOk();
    $response->assertJsonPath('type', 'FeatureCollection');
    $response->assertJsonCount(1, 'features');
    $response->assertJsonPath('features.0.id', 'us7000exportgeo');
    $response->assertJsonPath('features.0.geometry.type', 'Point');
});

it('synthesizes geojson features when raw is not a valid feature', function (): void {
    Earthquake::factory()->create([
        'usgs_id' => 'us7000synthetic',
        'magnitude' => 2.8,
        'place' => 'Synthetic Feature',
        'latitude' => 10.0,
        'longitude' => 20.0,
        'depth_km' => 5.0,
        'occurred_at' => now()->subHours(1),
        'raw' => ['invalid' => true],
    ]);

    $response = $this->get(route('export.geojson', exportQueryParams(now()->subDay(), now())));

    $response->assertOk();
    $response->assertJsonPath('features.0.type', 'Feature');
    $response->assertJsonPath('features.0.id', 'us7000synthetic');
    $response->assertJsonPath('features.0.geometry.coordinates.0', 20);
    $response->assertJsonPath('features.0.geometry.coordinates.1', 10);
});

it('caps export rows and sets truncated header', function (): void {
    config(['seismo.export_max_rows' => 2]);

    $from = now()->subDay();
    $to = now();

    Earthquake::factory()->count(4)->create([
        'occurred_at' => now()->subHours(2),
    ]);

    $csvResponse = $this->get(route('export.csv', exportQueryParams($from, $to)));
    $csvResponse->assertOk();
    $csvResponse->assertHeader('X-Seismo-Export-Truncated', '1');

    $csvLines = array_values(array_filter(explode("\n", trim($csvResponse->streamedContent()))));
    expect($csvLines)->toHaveCount(3);

    $geoResponse = $this->get(route('export.geojson', exportQueryParams($from, $to)));
    $geoResponse->assertOk();
    $geoResponse->assertHeader('X-Seismo-Export-Truncated', '1');
    $geoResponse->assertJsonCount(2, 'features');
});

it('requires bounded occurred_from and occurred_to', function (): void {
    $this->getJson(route('export.csv', ['min_magnitude' => 2.5]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['occurred_from', 'occurred_to']);
});

it('rate limits export routes', function (): void {
    config(['seismo.export_rate_per_minute' => 2]);

    $params = exportQueryParams(now()->subDay(), now());

    $this->get(route('export.csv', $params))->assertOk();
    $this->get(route('export.csv', $params))->assertOk();
    $this->get(route('export.csv', $params))->assertTooManyRequests();
});

it('shows export links in the filter panel', function (): void {
    Livewire::test(LiveMonitor::class)
        ->assertSee(__('seismo.export_csv'), false)
        ->assertSee(__('seismo.export_geojson'), false)
        ->assertSee('/export/csv', false)
        ->assertSee('/export/geojson', false);
});
