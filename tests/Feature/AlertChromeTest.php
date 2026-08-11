<?php

declare(strict_types=1);

use App\Livewire\LiveMonitor;
use App\Models\Earthquake;
use Livewire\Livewire;

it('shows strong magnitude badge for M5 or greater in activity', function (): void {
    Earthquake::factory()->create([
        'usgs_id' => 'us7000strong',
        'magnitude' => 5.2,
        'occurred_at' => now()->subHours(1),
        'place' => 'Strong Magnitude Event',
        'tsunami' => false,
    ]);

    Livewire::test(LiveMonitor::class)
        ->assertSee('Strong Magnitude Event', false)
        ->assertSee('seismo-mag-badge--strong', false);
});

it('does not show strong magnitude badge below alert threshold', function (): void {
    Earthquake::factory()->create([
        'usgs_id' => 'us7000moderate',
        'magnitude' => 4.8,
        'occurred_at' => now()->subHours(1),
        'place' => 'Moderate Magnitude Event',
        'tsunami' => false,
    ]);

    Livewire::test(LiveMonitor::class)
        ->assertSee('Moderate Magnitude Event', false)
        ->assertDontSee('seismo-mag-badge--strong', false);
});

it('shows tsunami banner and row badge when tsunami event is in filtered window', function (): void {
    Earthquake::factory()->create([
        'usgs_id' => 'us7000tsunami',
        'magnitude' => 4.0,
        'occurred_at' => now()->subHours(2),
        'place' => 'Tsunami Flagged Region',
        'tsunami' => true,
    ]);

    Livewire::test(LiveMonitor::class)
        ->assertSee(__('seismo.tsunami_banner'), false)
        ->assertSee(__('seismo.tsunami_badge'), false)
        ->assertSee('Tsunami Flagged Region', false);
});

it('hides tsunami banner when no tsunami events are in filtered window', function (): void {
    Earthquake::factory()->create([
        'usgs_id' => 'us7000notsunami',
        'magnitude' => 4.0,
        'occurred_at' => now()->subHours(2),
        'place' => 'No Tsunami Event',
        'tsunami' => false,
    ]);

    Livewire::test(LiveMonitor::class)
        ->assertSee('No Tsunami Event', false)
        ->assertDontSee(__('seismo.tsunami_banner'), false);
});

it('hides tsunami banner when tsunami event is outside the live window', function (): void {
    Earthquake::factory()->create([
        'usgs_id' => 'us7000oldtsunami',
        'magnitude' => 4.0,
        'occurred_at' => now()->subDays(5),
        'place' => 'Old Tsunami Event',
        'tsunami' => true,
    ]);

    Livewire::test(LiveMonitor::class)
        ->assertDontSee('Old Tsunami Event', false)
        ->assertDontSee(__('seismo.tsunami_banner'), false);
});

it('hides tsunami banner when tsunami filter excludes flagged events', function (): void {
    Earthquake::factory()->create([
        'usgs_id' => 'us7000filteredtsunami',
        'magnitude' => 4.0,
        'occurred_at' => now()->subHours(1),
        'place' => 'Filtered Out Tsunami',
        'tsunami' => true,
    ]);

    Livewire::test(LiveMonitor::class)
        ->set('tsunami', 'no')
        ->assertDontSee('Filtered Out Tsunami', false)
        ->assertDontSee(__('seismo.tsunami_banner'), false);
});

it('shows sound toggle default off in live mode', function (): void {
    Livewire::test(LiveMonitor::class)
        ->assertSee(__('seismo.sound_off'), false)
        ->assertSee(__('seismo.sound_toggle_aria'), false);
});

it('does not show sound toggle in history mode', function (): void {
    Livewire::test(LiveMonitor::class)
        ->call('setMode', 'history')
        ->assertDontSee(__('seismo.sound_off'), false)
        ->assertDontSee(__('seismo.sound_on'), false);
});

it('includes tsunami in map event payload', function (): void {
    Earthquake::factory()->create([
        'usgs_id' => 'us7000maptsunami',
        'magnitude' => 4.5,
        'occurred_at' => now()->subHours(1),
        'place' => 'Map Tsunami Event',
        'tsunami' => true,
    ]);

    $events = Livewire::test(LiveMonitor::class)->instance()->mapEvents();

    expect($events)->toHaveCount(1)
        ->and($events[0]['tsunami'])->toBeTrue();
});
