<?php

declare(strict_types=1);

use App\Actions\ApplyEarthquakeFilters;
use App\Livewire\LiveMonitor;
use App\Models\Earthquake;
use Livewire\Livewire;

it('renders the live monitor shell', function (): void {
    $response = $this->get('/');

    $response->assertSuccessful();
    $response->assertSee('SEISMO', false);
    $response->assertSee(__('seismo.activity_title'), false);
    $response->assertSee(__('seismo.live_window'), false);
    $response->assertSee(__('seismo.magnitude_chip', ['min' => 2.5]), false);
    $response->assertSee(__('seismo.updates_every'), false);
    $response->assertSee('24h', false);
    $response->assertSee('7d', false);
    $response->assertSee(__('seismo.filter_apply'), false);
});

it('shows earthquakes within the default window and magnitude filter', function (): void {
    Earthquake::factory()->create([
        'usgs_id' => 'us7000visible',
        'magnitude' => 4.5,
        'occurred_at' => now()->subHours(2),
        'place' => 'Kuril Islands, Russia',
    ]);

    Earthquake::factory()->create([
        'usgs_id' => 'us7000lowmag',
        'magnitude' => 2.0,
        'occurred_at' => now()->subHours(2),
        'place' => 'Too Small Magnitude',
    ]);

    Earthquake::factory()->create([
        'usgs_id' => 'us7000old',
        'magnitude' => 5.0,
        'occurred_at' => now()->subDays(3),
        'place' => 'Outside Window Range',
    ]);

    $this->get('/')
        ->assertSuccessful()
        ->assertSee('Kuril Islands, Russia', false)
        ->assertDontSee('Too Small Magnitude', false)
        ->assertDontSee('Outside Window Range', false);
});

it('paginates activity rows', function (): void {
    Earthquake::factory()->count(16)->create([
        'magnitude' => 3.5,
        'occurred_at' => now()->subHours(1),
    ]);

    $this->get('/')
        ->assertSuccessful()
        ->assertSee(__('seismo.showing_range', ['from' => 1, 'to' => 15, 'total' => 16]), false);
});

it('changes the live window via livewire', function (): void {
    Livewire::test(LiveMonitor::class)
        ->assertSet('windowHours', 24)
        ->call('setWindowHours', 6)
        ->assertSet('windowHours', 6)
        ->assertSee('6h', false)
        ->assertDispatched('seismo-window-changed');
});

it('rejects invalid window presets', function (): void {
    Livewire::test(LiveMonitor::class)
        ->call('setWindowHours', 99)
        ->assertSet('windowHours', 24);
});

it('applies place and magnitude filters via livewire', function (): void {
    Earthquake::factory()->create([
        'usgs_id' => 'us7000japan',
        'magnitude' => 4.0,
        'occurred_at' => now()->subHours(1),
        'place' => 'Near coast of Japan',
    ]);

    Earthquake::factory()->create([
        'usgs_id' => 'us7000calif',
        'magnitude' => 4.0,
        'occurred_at' => now()->subHours(1),
        'place' => 'Northern California',
    ]);

    Livewire::test(LiveMonitor::class)
        ->set('place', 'Japan')
        ->call('applyFilters')
        ->assertSee('Near coast of Japan', false)
        ->assertDontSee('Northern California', false);
});

it('applies depth and tsunami filters via livewire', function (): void {
    Earthquake::factory()->create([
        'usgs_id' => 'us7000shallow',
        'magnitude' => 4.0,
        'depth_km' => 5.0,
        'tsunami' => false,
        'occurred_at' => now()->subHours(1),
        'place' => 'Shallow Pacific Event',
    ]);

    Earthquake::factory()->create([
        'usgs_id' => 'us7000deep',
        'magnitude' => 4.0,
        'depth_km' => 50.0,
        'tsunami' => false,
        'occurred_at' => now()->subHours(1),
        'place' => 'Deep Pacific Event',
    ]);

    Livewire::test(LiveMonitor::class)
        ->set('maxDepth', 10.0)
        ->call('applyFilters')
        ->assertSee('Shallow Pacific Event', false)
        ->assertDontSee('Deep Pacific Event', false);

    Earthquake::factory()->create([
        'usgs_id' => 'us7000tsu',
        'magnitude' => 4.0,
        'depth_km' => 10.0,
        'tsunami' => true,
        'occurred_at' => now()->subHours(1),
        'place' => 'Tsunami Warning Region',
    ]);

    Earthquake::factory()->create([
        'usgs_id' => 'us7000notsu',
        'magnitude' => 4.0,
        'depth_km' => 10.0,
        'tsunami' => false,
        'occurred_at' => now()->subHours(1),
        'place' => 'No Tsunami Region',
    ]);

    Livewire::test(LiveMonitor::class)
        ->set('tsunami', 'yes')
        ->call('applyFilters')
        ->assertSee('Tsunami Warning Region', false)
        ->assertDontSee('No Tsunami Region', false);
});

it('resets filters to defaults', function (): void {
    Livewire::test(LiveMonitor::class)
        ->set('minMagnitude', 1.0)
        ->set('maxMagnitude', 3.0)
        ->set('place', 'Test')
        ->set('sort', 'magnitude')
        ->call('resetFilters')
        ->assertSet('minMagnitude', 2.5)
        ->assertSet('maxMagnitude', null)
        ->assertSet('place', '')
        ->assertSet('sort', 'occurred');
});

it('navigates activity pagination', function (): void {
    Earthquake::factory()->count(16)->create([
        'magnitude' => 3.5,
        'occurred_at' => now()->subHours(1),
    ]);

    Livewire::test(LiveMonitor::class)
        ->assertSee(__('seismo.showing_range', ['from' => 1, 'to' => 15, 'total' => 16]), false)
        ->call('nextPage')
        ->assertSee(__('seismo.showing_range', ['from' => 16, 'to' => 16, 'total' => 16]), false);
});

it('accepts matching live earthquake payloads and dispatches ripple', function (): void {
    $earthquake = Earthquake::factory()->create([
        'usgs_id' => 'us7000live',
        'magnitude' => 4.5,
        'latitude' => 34.0,
        'longitude' => -118.0,
        'occurred_at' => now()->subMinutes(5),
        'place' => 'Los Angeles, CA',
    ]);

    Livewire::test(LiveMonitor::class)
        ->call('onLiveEarthquake', [
            'id' => $earthquake->id,
            'usgs_id' => 'us7000live',
            'magnitude' => 4.5,
            'lat' => 34.0,
            'lon' => -118.0,
            'depth_km' => 10.0,
            'place' => 'Los Angeles, CA',
            'occurred_at' => now()->subMinutes(5)->toIso8601String(),
            'tsunami' => false,
        ])
        ->assertDispatched('seismo-map-ripple');
});

it('rejects live earthquake payloads outside current filters', function (): void {
    Livewire::test(LiveMonitor::class)
        ->call('onLiveEarthquake', [
            'id' => 99,
            'usgs_id' => 'us7000small',
            'magnitude' => 1.5,
            'lat' => 34.0,
            'lon' => -118.0,
            'depth_km' => 10.0,
            'place' => 'Los Angeles, CA',
            'occurred_at' => now()->subMinutes(5)->toIso8601String(),
            'tsunami' => false,
        ])
        ->assertNotDispatched('seismo-map-ripple');
});

it('matches broadcast payloads against filters', function (): void {
    $filters = app(ApplyEarthquakeFilters::class);
    $from = now()->subHours(24);
    $to = now();

    expect($filters->payloadMatches(
        [
            'magnitude' => 4.5,
            'depth_km' => 10.0,
            'tsunami' => false,
            'place' => 'Japan coast',
            'lat' => 35.0,
            'lon' => 139.0,
            'occurred_at' => now()->subHour()->toIso8601String(),
        ],
        minMagnitude: 2.5,
        maxMagnitude: null,
        minDepth: null,
        maxDepth: null,
        centerLat: null,
        centerLon: null,
        radiusKm: null,
        tsunami: 'all',
        place: 'Japan',
        occurredFrom: $from,
        occurredTo: $to,
    ))->toBeTrue();

    expect($filters->payloadMatches(
        [
            'magnitude' => 4.5,
            'depth_km' => 10.0,
            'tsunami' => false,
            'place' => 'California',
            'lat' => 35.0,
            'lon' => -120.0,
            'occurred_at' => now()->subHour()->toIso8601String(),
        ],
        minMagnitude: 2.5,
        maxMagnitude: null,
        minDepth: null,
        maxDepth: null,
        centerLat: null,
        centerLon: null,
        radiusKm: null,
        tsunami: 'all',
        place: 'Japan',
        occurredFrom: $from,
        occurredTo: $to,
    ))->toBeFalse();
});

it('switches between live and history modes', function (): void {
    Livewire::test(LiveMonitor::class)
        ->assertSet('mode', 'live')
        ->call('setMode', 'history')
        ->assertSet('mode', 'history')
        ->assertSee(__('seismo.status_idle'), false)
        ->assertSee(__('seismo.slice_chip', ['hours' => 6]), false)
        ->assertDispatched('seismo-mode-changed')
        ->call('setMode', 'live')
        ->assertSet('mode', 'live')
        ->assertSee(__('seismo.updates_every'), false);
});

it('shows earthquakes within the history slice bounds', function (): void {
    $sliceHours = (int) config('seismo.history_slice_hours');
    $center = now()->subDays(5);
    $scrubberAt = $center->toIso8601String();

    Earthquake::factory()->create([
        'usgs_id' => 'us7000inslice',
        'magnitude' => 4.0,
        'occurred_at' => $center,
        'place' => 'Inside History Slice',
    ]);

    Earthquake::factory()->create([
        'usgs_id' => 'us7000outside',
        'magnitude' => 4.0,
        'occurred_at' => $center->copy()->subHours($sliceHours + 2),
        'place' => 'Outside History Slice',
    ]);

    Livewire::test(LiveMonitor::class)
        ->call('setMode', 'history')
        ->call('setScrubberAt', $scrubberAt)
        ->assertSee('Inside History Slice', false)
        ->assertDontSee('Outside History Slice', false);
});

it('updates history results when scrubber moves', function (): void {
    $earlyCenter = now()->subDays(10);
    $lateCenter = now()->subDays(2);

    Earthquake::factory()->create([
        'usgs_id' => 'us7000early',
        'magnitude' => 4.0,
        'occurred_at' => $earlyCenter,
        'place' => 'Early History Event',
    ]);

    Earthquake::factory()->create([
        'usgs_id' => 'us7000late',
        'magnitude' => 4.0,
        'occurred_at' => $lateCenter,
        'place' => 'Late History Event',
    ]);

    Livewire::test(LiveMonitor::class)
        ->call('setMode', 'history')
        ->call('setScrubberAt', $earlyCenter->toIso8601String())
        ->assertSee('Early History Event', false)
        ->assertDontSee('Late History Event', false)
        ->call('setScrubberAt', $lateCenter->toIso8601String())
        ->assertSee('Late History Event', false)
        ->assertDontSee('Early History Event', false);
});

it('applies filters in history mode', function (): void {
    $center = now()->subDays(3);

    Earthquake::factory()->create([
        'usgs_id' => 'us7000histjapan',
        'magnitude' => 4.0,
        'occurred_at' => $center,
        'place' => 'Near coast of Japan',
    ]);

    Earthquake::factory()->create([
        'usgs_id' => 'us7000histcalif',
        'magnitude' => 4.0,
        'occurred_at' => $center,
        'place' => 'Northern California',
    ]);

    Livewire::test(LiveMonitor::class)
        ->call('setMode', 'history')
        ->call('setScrubberAt', $center->toIso8601String())
        ->set('place', 'Japan')
        ->call('applyFilters')
        ->assertSee('Near coast of Japan', false)
        ->assertDontSee('Northern California', false);
});

it('increments pending live count in history mode without ripple', function (): void {
    $earthquake = Earthquake::factory()->create([
        'usgs_id' => 'us7000histws',
        'magnitude' => 4.5,
        'latitude' => 34.0,
        'longitude' => -118.0,
        'occurred_at' => now()->subMinutes(5),
        'place' => 'Los Angeles, CA',
    ]);

    Livewire::test(LiveMonitor::class)
        ->call('setMode', 'history')
        ->call('onLiveEarthquake', [
            'id' => $earthquake->id,
            'usgs_id' => 'us7000histws',
            'magnitude' => 4.5,
            'lat' => 34.0,
            'lon' => -118.0,
            'depth_km' => 10.0,
            'place' => 'Los Angeles, CA',
            'occurred_at' => now()->subMinutes(5)->toIso8601String(),
            'tsunami' => false,
        ])
        ->assertSet('pendingLiveCount', 1)
        ->assertNotDispatched('seismo-map-ripple')
        ->assertSee(__('seismo.new_live', ['count' => 1]), false);
});

it('clears pending count when returning to live via chip', function (): void {
    Livewire::test(LiveMonitor::class)
        ->call('setMode', 'history')
        ->set('pendingLiveCount', 3)
        ->call('goLiveFromChip')
        ->assertSet('mode', 'live')
        ->assertSet('pendingLiveCount', 0);
});

it('still dispatches ripple in live mode', function (): void {
    $earthquake = Earthquake::factory()->create([
        'usgs_id' => 'us7000livemode',
        'magnitude' => 4.5,
        'latitude' => 34.0,
        'longitude' => -118.0,
        'occurred_at' => now()->subMinutes(5),
        'place' => 'Los Angeles, CA',
    ]);

    Livewire::test(LiveMonitor::class)
        ->assertSet('mode', 'live')
        ->call('onLiveEarthquake', [
            'id' => $earthquake->id,
            'usgs_id' => 'us7000livemode',
            'magnitude' => 4.5,
            'lat' => 34.0,
            'lon' => -118.0,
            'depth_km' => 10.0,
            'place' => 'Los Angeles, CA',
            'occurred_at' => now()->subMinutes(5)->toIso8601String(),
            'tsunami' => false,
        ])
        ->assertDispatched('seismo-map-ripple');
});
