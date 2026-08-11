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

    public string $mode = 'live';

    public string $scrubberAt;

    public int $pendingLiveCount = 0;

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
        $this->scrubberAt = $this->defaultScrubberCenter()->toIso8601String();
    }

    public function setMode(string $mode): void
    {
        if (! in_array($mode, ['live', 'history'], true)) {
            return;
        }

        $this->mode = $mode;

        if ($mode === 'live') {
            $this->pendingLiveCount = 0;
        } else {
            $this->scrubberAt = $this->defaultScrubberCenter()->toIso8601String();
        }

        $this->resetPage();
        $this->dispatchMapRefresh();
        $this->dispatch('seismo-mode-changed', mode: $mode);
    }

    public function setScrubberAt(string $iso): void
    {
        try {
            $center = Carbon::parse($iso);
        } catch (\Throwable) {
            return;
        }

        $min = $this->scrubberCenterMin();
        $max = $this->scrubberCenterMax();

        if ($center->lt($min)) {
            $center = $min;
        } elseif ($center->gt($max)) {
            $center = $max;
        }

        $this->scrubberAt = $center->toIso8601String();
        $this->resetPage();
        $this->dispatchMapRefresh();
        $this->dispatch('seismo-scrubber-changed', at: $this->scrubberAt);
    }

    public function goLiveFromChip(): void
    {
        $this->pendingLiveCount = 0;
        $this->setMode('live');
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
        if ($this->mode !== 'live') {
            return;
        }

        $this->dispatchMapRefresh();
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function onLiveEarthquake(array $payload): bool
    {
        $filters = app(ApplyEarthquakeFilters::class);

        $occurredFrom = $this->mode === 'history'
            ? now()->subHours($this->windowHours)
            : $this->windowFrom();

        $occurredTo = $this->mode === 'history'
            ? now()
            : $this->windowTo();

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
            $occurredFrom,
            $occurredTo,
        )) {
            return false;
        }

        if ($this->mode === 'history') {
            $this->pendingLiveCount++;

            return true;
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
     * @return list<array{id: int, magnitude: float|null, latitude: float|null, longitude: float|null, place: string|null, occurred_at: string, tsunami: bool}>
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
                'tsunami' => (bool) $earthquake->tsunami,
            ])
            ->all();
    }

    public function windowFrom(): Carbon
    {
        if ($this->mode === 'history') {
            $from = $this->scrubberCenter()->copy()->subHours($this->historySliceHours());
            $trackMin = $this->historyTrackMin();

            return $from->lt($trackMin) ? $trackMin : $from;
        }

        return now()->subHours($this->windowHours);
    }

    public function windowTo(): Carbon
    {
        if ($this->mode === 'history') {
            $to = $this->scrubberCenter()->copy()->addHours($this->historySliceHours());
            $trackMax = $this->historyTrackMax();

            return $to->gt($trackMax) ? $trackMax : $to;
        }

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

    public function sliceChipLabel(): string
    {
        return __('seismo.slice_chip', ['hours' => $this->historySliceHours()]);
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

    public function historyTrackMin(): Carbon
    {
        return now()->subDays(30);
    }

    public function historyTrackMax(): Carbon
    {
        return now();
    }

    public function scrubberCenterMin(): Carbon
    {
        return $this->historyTrackMin()->copy()->addHours($this->historySliceHours());
    }

    public function scrubberCenterMax(): Carbon
    {
        return $this->historyTrackMax()->copy()->subHours($this->historySliceHours());
    }

    public function scrubberCenter(): Carbon
    {
        try {
            $center = Carbon::parse($this->scrubberAt);
        } catch (\Throwable) {
            $center = $this->defaultScrubberCenter();
        }

        $min = $this->scrubberCenterMin();
        $max = $this->scrubberCenterMax();

        if ($center->lt($min)) {
            return $min;
        }

        if ($center->gt($max)) {
            return $max;
        }

        return $center;
    }

    public function historySliceHours(): int
    {
        return (int) config('seismo.history_slice_hours');
    }

    public function alertMinMagnitude(): float
    {
        return (float) config('seismo.alert_min_magnitude');
    }

    public function hasTsunamiInWindow(): bool
    {
        return (clone $this->baseQuery())->where('tsunami', true)->exists();
    }

    /**
     * @return array<string, float|int|string>
     */
    public function exportQueryParams(): array
    {
        $params = [
            'min_magnitude' => $this->minMagnitude,
            'max_magnitude' => $this->maxMagnitude,
            'min_depth' => $this->minDepth,
            'max_depth' => $this->maxDepth,
            'center_lat' => $this->centerLat,
            'center_lon' => $this->centerLon,
            'radius_km' => $this->radiusKm,
            'tsunami' => $this->tsunami,
            'place' => $this->place !== '' ? $this->place : null,
            'sort' => $this->sort,
            'occurred_from' => $this->windowFrom()->toIso8601String(),
            'occurred_to' => $this->windowTo()->toIso8601String(),
        ];

        return array_filter(
            $params,
            static fn (mixed $value): bool => $value !== null && $value !== '',
        );
    }

    public function render(): View
    {
        return view('livewire.live-monitor', [
            'earthquakes' => $this->baseQuery()->paginate((int) config('seismo.list_page_size')),
            'presets' => config('seismo.live_window_presets', []),
            'mapEvents' => $this->mapEvents(),
            'hasTsunamiInWindow' => $this->hasTsunamiInWindow(),
            'alertMinMagnitude' => $this->alertMinMagnitude(),
            'scrubberMinTs' => $this->scrubberCenterMin()->timestamp,
            'scrubberMaxTs' => $this->scrubberCenterMax()->timestamp,
            'scrubberCenterTs' => $this->scrubberCenter()->timestamp,
        ]);
    }

    private function defaultScrubberCenter(): Carbon
    {
        return now()->subHours($this->historySliceHours());
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
            'tsunami' => (bool) ($payload['tsunami'] ?? false),
        ];
    }
}
