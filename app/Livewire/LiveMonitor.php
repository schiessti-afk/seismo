<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Actions\ApplyEarthquakeFilters;
use App\Models\Earthquake;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('layouts.seismo')]
class LiveMonitor extends Component
{
    use WithPagination;

    public int $windowHours;

    public float $minMagnitude;

    public ?float $maxMagnitude = null;

    public ?float $minDepth = null;

    public ?float $maxDepth = null;

    public ?float $radiusKm = null;

    public ?float $centerLat = null;

    public ?float $centerLon = null;

    public string $tsunami = 'all';

    public string $place = '';

    public string $sort = 'occurred';

    public function mount(): void
    {
        $this->windowHours = (int) config('seismo.live_window_hours');
        $this->minMagnitude = (float) config('seismo.default_filter_min_magnitude');
    }

    public function setWindowHours(int $hours): void
    {
        $presets = config('seismo.live_window_presets', []);

        if (! in_array($hours, $presets, true)) {
            return;
        }

        $this->windowHours = $hours;
        $this->resetPage();
        $this->dispatchMapRefresh();
        $this->dispatch('seismo-window-changed', hours: $hours);
    }

    public function applyFilters(): void
    {
        $this->normalizeFilterInputs();
        $this->resetPage();
        $this->dispatchMapRefresh();
    }

    public function resetFilters(): void
    {
        $this->minMagnitude = (float) config('seismo.default_filter_min_magnitude');
        $this->maxMagnitude = null;
        $this->minDepth = null;
        $this->maxDepth = null;
        $this->radiusKm = null;
        $this->centerLat = null;
        $this->centerLon = null;
        $this->tsunami = 'all';
        $this->place = '';
        $this->sort = 'occurred';
        $this->resetPage();
        $this->dispatchMapRefresh();
    }

    public function refreshLive(): void
    {
        $this->dispatchMapRefresh();
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function onLiveEarthquake(array $payload): bool
    {
        $filters = app(ApplyEarthquakeFilters::class);

        if (! $filters->payloadMatches(
            $payload,
            $this->minMagnitude,
            $this->maxMagnitude,
            $this->minDepth,
            $this->maxDepth,
            $this->centerLat,
            $this->centerLon,
            $this->radiusKm,
            $this->tsunami,
            $this->place !== '' ? $this->place : null,
            $this->windowFrom(),
            $this->windowTo(),
        )) {
            return false;
        }

        $this->resetPage();
        $this->dispatch('seismo-map-ripple', mapEvent: $this->normalizeBroadcastPayload($payload));

        return true;
    }

    public function setMapCenter(float $lat, float $lon): void
    {
        $this->centerLat = $lat;
        $this->centerLon = $lon;
    }

    /**
     * @return Builder<Earthquake>
     */
    protected function baseQuery(): Builder
    {
        return app(ApplyEarthquakeFilters::class)(
            minMagnitude: $this->minMagnitude,
            maxMagnitude: $this->maxMagnitude,
            minDepth: $this->minDepth,
            maxDepth: $this->maxDepth,
            centerLat: $this->centerLat,
            centerLon: $this->centerLon,
            radiusKm: $this->radiusKm,
            tsunami: $this->tsunami,
            place: $this->place !== '' ? $this->place : null,
            occurredFrom: $this->windowFrom(),
            occurredTo: $this->windowTo(),
            sort: $this->sort,
        );
    }

    /**
     * @return list<array{id: int, magnitude: float|null, latitude: float|null, longitude: float|null, place: string|null, occurred_at: string}>
     */
    public function mapEvents(): array
    {
        return $this->baseQuery()
            ->get()
            ->map(fn (Earthquake $earthquake): array => [
                'id' => $earthquake->id,
                'magnitude' => $earthquake->magnitude !== null ? (float) $earthquake->magnitude : null,
                'latitude' => $earthquake->latitude,
                'longitude' => $earthquake->longitude,
                'place' => $earthquake->place,
                'occurred_at' => $earthquake->occurred_at->toIso8601String(),
            ])
            ->all();
    }

    public function windowFrom(): Carbon
    {
        return now()->subHours($this->windowHours);
    }

    public function windowTo(): Carbon
    {
        return now();
    }

    public function presetLabel(int $hours): string
    {
        if ($hours >= 168) {
            return __('seismo.preset_7d');
        }

        return __('seismo.preset_hours', ['hours' => $hours]);
    }

    public function windowChipLabel(): string
    {
        if ($this->windowHours >= 168) {
            return __('seismo.window_chip_7d');
        }

        return __('seismo.window_chip_hours', ['hours' => $this->windowHours]);
    }

    public function magnitudeChipLabel(): string
    {
        if ($this->maxMagnitude !== null) {
            return __('seismo.magnitude_chip_range', [
                'min' => $this->minMagnitude,
                'max' => $this->maxMagnitude,
            ]);
        }

        return __('seismo.magnitude_chip', ['min' => $this->minMagnitude]);
    }

    public function render(): View
    {
        return view('livewire.live-monitor', [
            'earthquakes' => $this->baseQuery()->paginate((int) config('seismo.list_page_size')),
            'presets' => config('seismo.live_window_presets', []),
            'mapEvents' => $this->mapEvents(),
        ]);
    }

    private function normalizeFilterInputs(): void
    {
        if ($this->maxMagnitude !== null && $this->maxMagnitude <= 0) {
            $this->maxMagnitude = null;
        }

        if ($this->radiusKm !== null && $this->radiusKm <= 0) {
            $this->radiusKm = null;
        }
    }

    private function dispatchMapRefresh(): void
    {
        $this->dispatch('seismo-map-refresh', events: $this->mapEvents());
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function normalizeBroadcastPayload(array $payload): array
    {
        return [
            'id' => (int) ($payload['id'] ?? 0),
            'magnitude' => isset($payload['magnitude']) ? (float) $payload['magnitude'] : null,
            'latitude' => isset($payload['lat']) ? (float) $payload['lat'] : (isset($payload['latitude']) ? (float) $payload['latitude'] : null),
            'longitude' => isset($payload['lon']) ? (float) $payload['lon'] : (isset($payload['longitude']) ? (float) $payload['longitude'] : null),
            'place' => $payload['place'] ?? null,
            'occurred_at' => (string) ($payload['occurred_at'] ?? now()->toIso8601String()),
        ];
    }
}
