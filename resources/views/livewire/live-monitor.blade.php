<div
    class="seismo-shell"
    data-mode="{{ $mode }}"
    data-alert-min-magnitude="{{ $alertMinMagnitude }}"
    @if ($mode === 'live') wire:poll.10s="refreshLive" @endif
    x-data="{
        filterOpen: false,
        windowOpen: false,
        scrubbing: false,
        debounceTimer: null,
        soundEnabled: false,
        init() {
            const stored = localStorage.getItem('seismo.liveWindowHours');
            if (stored) {
                const hours = parseInt(stored, 10);
                if (!isNaN(hours)) {
                    $wire.setWindowHours(hours);
                }
            }
            const storedMode = localStorage.getItem('seismo.mode');
            if (storedMode === 'history' || storedMode === 'live') {
                $wire.setMode(storedMode);
            }
            const storedScrub = localStorage.getItem('seismo.historyScrubAt');
            if (storedScrub) {
                $wire.setScrubberAt(storedScrub);
            }
            this.soundEnabled = localStorage.getItem('seismo.soundEnabled') === 'true';
        },
        toggleSound() {
            this.soundEnabled = !this.soundEnabled;
            localStorage.setItem('seismo.soundEnabled', this.soundEnabled ? 'true' : 'false');
        },
        onScrub(value) {
            this.scrubbing = true;
            clearTimeout(this.debounceTimer);
            this.debounceTimer = setTimeout(() => {
                $wire.setScrubberAt(new Date(parseInt(value, 10) * 1000).toISOString());
                this.scrubbing = false;
            }, 150);
        }
    }"
    @click.outside="filterOpen = false; windowOpen = false"
>
    <header class="seismo-topbar">
        <h1 class="seismo-brand">{{ __('seismo.brand') }}</h1>

        <div class="seismo-mode-pill" role="group" aria-label="{{ __('seismo.mode_live') }} / {{ __('seismo.mode_history') }}">
            <button
                type="button"
                wire:click="setMode('live')"
                @class(['seismo-mode', 'seismo-mode--active' => $mode === 'live'])
            >
                {{ __('seismo.mode_live') }}
            </button>
            <button
                type="button"
                wire:click="setMode('history')"
                @class(['seismo-mode', 'seismo-mode--active' => $mode === 'history'])
            >
                {{ __('seismo.mode_history') }}
            </button>
        </div>

        <div class="seismo-chips">
            <div class="seismo-chip-wrap">
                <button
                    type="button"
                    class="seismo-chip"
                    @click.stop="filterOpen = !filterOpen; windowOpen = false"
                    :aria-expanded="filterOpen"
                >
                    <svg class="seismo-chip-icon" viewBox="0 0 16 16" aria-hidden="true"><path d="M1 3h2.5l2 5 2-8 2 5H14v2H1V3z" fill="currentColor"/></svg>
                    {{ $this->magnitudeChipLabel() }}
                </button>

                <div class="seismo-filter-panel" x-show="filterOpen" x-cloak @click.stop>
                    <h3 class="seismo-filter-title">{{ __('seismo.filter_title') }}</h3>

                    <div class="seismo-filter-grid">
                        <label class="seismo-filter-field">
                            <span>{{ __('seismo.filter_mag_min') }}</span>
                            <input type="number" step="0.1" min="0" wire:model="minMagnitude" class="seismo-filter-input">
                        </label>
                        <label class="seismo-filter-field">
                            <span>{{ __('seismo.filter_mag_max') }}</span>
                            <input type="number" step="0.1" min="0" wire:model="maxMagnitude" class="seismo-filter-input" placeholder="—">
                        </label>
                        <label class="seismo-filter-field">
                            <span>{{ __('seismo.filter_depth_min') }}</span>
                            <input type="number" step="0.1" min="0" wire:model="minDepth" class="seismo-filter-input" placeholder="—">
                        </label>
                        <label class="seismo-filter-field">
                            <span>{{ __('seismo.filter_depth_max') }}</span>
                            <input type="number" step="0.1" min="0" wire:model="maxDepth" class="seismo-filter-input" placeholder="—">
                        </label>
                        <label class="seismo-filter-field">
                            <span>{{ __('seismo.filter_radius_km') }}</span>
                            <input type="number" step="1" min="0" wire:model="radiusKm" class="seismo-filter-input" placeholder="—">
                        </label>
                        <label class="seismo-filter-field">
                            <span>{{ __('seismo.filter_center_lat') }}</span>
                            <input type="number" step="0.0001" wire:model="centerLat" class="seismo-filter-input" placeholder="—">
                        </label>
                        <label class="seismo-filter-field">
                            <span>{{ __('seismo.filter_center_lon') }}</span>
                            <input type="number" step="0.0001" wire:model="centerLon" class="seismo-filter-input" placeholder="—">
                        </label>
                        <div class="seismo-filter-field seismo-filter-field--action">
                            <button
                                type="button"
                                class="seismo-filter-map-center"
                                @click="
                                    const center = window.SeismoMap?.getCenter?.();
                                    if (center) {
                                        $wire.setMapCenter(center.lat, center.lon);
                                    }
                                "
                            >
                                {{ __('seismo.filter_use_map_center') }}
                            </button>
                        </div>
                        <label class="seismo-filter-field">
                            <span>{{ __('seismo.filter_tsunami') }}</span>
                            <select wire:model="tsunami" class="seismo-filter-input">
                                <option value="all">{{ __('seismo.filter_tsunami_all') }}</option>
                                <option value="yes">{{ __('seismo.filter_tsunami_yes') }}</option>
                                <option value="no">{{ __('seismo.filter_tsunami_no') }}</option>
                            </select>
                        </label>
                        <label class="seismo-filter-field seismo-filter-field--wide">
                            <span>{{ __('seismo.filter_place') }}</span>
                            <input type="text" wire:model="place" class="seismo-filter-input" placeholder="">
                        </label>
                        <label class="seismo-filter-field seismo-filter-field--wide">
                            <span>{{ __('seismo.filter_sort') }}</span>
                            <select wire:model="sort" class="seismo-filter-input">
                                <option value="occurred">{{ __('seismo.filter_sort_occurred') }}</option>
                                <option value="magnitude">{{ __('seismo.filter_sort_magnitude') }}</option>
                            </select>
                        </label>
                    </div>

                    <div class="seismo-filter-actions">
                        <button type="button" class="seismo-filter-btn seismo-filter-btn--primary" wire:click="applyFilters" @click="filterOpen = false">
                            {{ __('seismo.filter_apply') }}
                        </button>
                        <button type="button" class="seismo-filter-btn" wire:click="resetFilters">
                            {{ __('seismo.filter_reset') }}
                        </button>
                    </div>
                </div>
            </div>

            @if ($mode === 'live')
                <div class="seismo-chip-wrap">
                    <button
                        type="button"
                        class="seismo-chip"
                        @click.stop="windowOpen = !windowOpen; filterOpen = false"
                        :aria-expanded="windowOpen"
                    >
                        <svg class="seismo-chip-icon" viewBox="0 0 16 16" aria-hidden="true"><rect x="2" y="3" width="12" height="11" rx="1" fill="none" stroke="currentColor" stroke-width="1.5"/><path d="M2 6h12M5 1v3M11 1v3" stroke="currentColor" stroke-width="1.5"/></svg>
                        {{ $this->windowChipLabel() }}
                    </button>

                    <div class="seismo-window-dropdown" x-show="windowOpen" x-cloak @click.stop>
                        @foreach ($presets as $hours)
                            <button
                                type="button"
                                wire:click="setWindowHours({{ $hours }})"
                                @click="windowOpen = false"
                                @class(['seismo-window-option', 'seismo-window-option--active' => $windowHours === $hours])
                            >
                                {{ $this->presetLabel($hours) }}
                            </button>
                        @endforeach
                    </div>
                </div>
            @else
                <span class="seismo-chip seismo-chip--disabled" aria-disabled="true">
                    <svg class="seismo-chip-icon" viewBox="0 0 16 16" aria-hidden="true"><rect x="2" y="3" width="12" height="11" rx="1" fill="none" stroke="currentColor" stroke-width="1.5"/><path d="M2 6h12M5 1v3M11 1v3" stroke="currentColor" stroke-width="1.5"/></svg>
                    {{ $this->sliceChipLabel() }}
                </span>
            @endif

            @if ($mode === 'live')
                <button
                    type="button"
                    class="seismo-chip seismo-chip--sound"
                    @click="toggleSound()"
                    :aria-pressed="soundEnabled"
                    :aria-label="@js(__('seismo.sound_toggle_aria'))"
                >
                    <svg class="seismo-chip-icon" viewBox="0 0 16 16" aria-hidden="true">
                        <path d="M3 5h2l3-2v10l-3-2H3V5z" fill="currentColor"/>
                        <path d="M10 5.5a2.5 2.5 0 010 5" fill="none" stroke="currentColor" stroke-width="1.25" stroke-linecap="round"/>
                        <path x-show="!soundEnabled" x-cloak d="M12.5 4.5L9.5 11.5" fill="none" stroke="currentColor" stroke-width="1.25" stroke-linecap="round"/>
                    </svg>
                    <span x-text="soundEnabled ? @js(__('seismo.sound_on')) : @js(__('seismo.sound_off'))"></span>
                </button>
            @endif
        </div>
    </header>

    @if ($hasTsunamiInWindow)
        <div class="seismo-tsunami-banner" role="status">
            {{ __('seismo.tsunami_banner') }}
        </div>
    @endif

    <div class="seismo-main">
        <aside class="seismo-activity" aria-label="{{ __('seismo.activity_title') }}">
            <div class="seismo-activity-header">
                <h2 class="seismo-activity-title">{{ __('seismo.activity_title') }}</h2>
                @if ($mode === 'history' && $pendingLiveCount > 0)
                    <button type="button" class="seismo-new-live-chip" wire:click="goLiveFromChip">
                        {{ __('seismo.new_live', ['count' => $pendingLiveCount]) }}
                    </button>
                @endif
                <svg class="seismo-pulse-icon" viewBox="0 0 24 12" aria-hidden="true">
                    <polyline points="0,6 4,6 6,2 8,10 10,6 14,6 16,1 18,11 20,6 24,6" fill="none" stroke="currentColor" stroke-width="1.5"/>
                </svg>
            </div>

            <ul class="seismo-activity-list">
                @forelse ($earthquakes as $earthquake)
                    <li wire:key="eq-{{ $earthquake->id }}">
                        <button
                            type="button"
                            class="seismo-activity-row"
                            x-data
                            x-on:click="window.dispatchEvent(new CustomEvent('seismo-pan-to', { detail: { id: {{ $earthquake->id }} } }))"
                        >
                            <span @class([
                                'seismo-mag-badge',
                                'seismo-mag-badge--strong' => (float) $earthquake->magnitude >= $alertMinMagnitude,
                            ])>{{ number_format((float) $earthquake->magnitude, 1) }}</span>
                            <span class="seismo-activity-meta">
                                <span class="seismo-activity-place-row">
                                    <span class="seismo-activity-place">{{ $earthquake->place }}</span>
                                    @if ($earthquake->tsunami)
                                        <span class="seismo-tsunami-badge">{{ __('seismo.tsunami_badge') }}</span>
                                    @endif
                                </span>
                                <span class="seismo-activity-date">
                                    <span x-text="new Date(@js($earthquake->occurred_at->toIso8601String())).toLocaleDateString(undefined, { month: 'short', day: 'numeric', year: 'numeric' })"></span>
                                    <span x-text="new Date(@js($earthquake->occurred_at->toIso8601String())).toLocaleTimeString(undefined, { hour: '2-digit', minute: '2-digit' })"></span>
                                </span>
                            </span>
                            <span class="seismo-activity-local">
                                <span x-text="new Date(@js($earthquake->occurred_at->toIso8601String())).toLocaleTimeString(undefined, { hour: '2-digit', minute: '2-digit' })"></span>
                                {{ __('seismo.local_suffix') }}
                            </span>
                        </button>
                    </li>
                @empty
                    <li class="seismo-activity-empty">—</li>
                @endforelse
            </ul>

            <footer class="seismo-activity-footer">
                <span class="seismo-status">
                    <svg class="seismo-status-icon" viewBox="0 0 16 16" aria-hidden="true"><circle cx="8" cy="8" r="2" fill="currentColor"/><path d="M8 1v2M8 13v2M1 8h2M13 8h2" stroke="currentColor" stroke-width="1.5"/></svg>
                    @if ($mode === 'live')
                        {{ __('seismo.updates_every') }}
                    @else
                        <span x-show="!scrubbing">{{ __('seismo.status_idle') }}</span>
                        <span x-show="scrubbing" x-cloak>{{ __('seismo.status_scrubbing') }}</span>
                    @endif
                </span>
                <div class="seismo-activity-footer-right">
                    @if ($earthquakes->hasPages())
                        <div class="seismo-pagination">
                            <button
                                type="button"
                                class="seismo-page-btn"
                                wire:click="previousPage"
                                @disabled($earthquakes->onFirstPage())
                            >
                                {{ __('seismo.pagination_prev') }}
                            </button>
                            <button
                                type="button"
                                class="seismo-page-btn"
                                wire:click="nextPage"
                                @disabled(! $earthquakes->hasMorePages())
                            >
                                {{ __('seismo.pagination_next') }}
                            </button>
                        </div>
                    @endif
                    <span class="seismo-showing">
                        {{ __('seismo.showing_range', [
                            'from' => $earthquakes->firstItem() ?? 0,
                            'to' => $earthquakes->lastItem() ?? 0,
                            'total' => $earthquakes->total(),
                        ]) }}
                    </span>
                </div>
            </footer>
        </aside>

        <div class="seismo-map-pane">
            <div id="seismo-map" wire:ignore class="seismo-map"></div>
            <script id="seismo-map-data" type="application/json" data-labels="{{ json_encode([
                'local' => __('seismo.local_suffix'),
                'utc' => __('seismo.utc_prefix'),
                'close' => __('seismo.popup_close'),
                'locate' => __('seismo.map_locate'),
                'layers' => __('seismo.map_layers'),
                'tsunami' => __('seismo.popup_tsunami'),
            ]) }}">@json($mapEvents)</script>
        </div>
    </div>

    <footer class="seismo-bottombar">
        @if ($mode === 'live')
            <div class="seismo-bottom-left">
                <svg class="seismo-clock-icon" viewBox="0 0 16 16" aria-hidden="true"><circle cx="8" cy="8" r="6.5" fill="none" stroke="currentColor" stroke-width="1.5"/><path d="M8 4.5V8l2.5 2" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg>
                <span class="seismo-live-window-label">{{ __('seismo.live_window') }}</span>
                <div class="seismo-presets" role="group" aria-label="{{ __('seismo.live_window') }}">
                    @foreach ($presets as $hours)
                        <button
                            type="button"
                            wire:click="setWindowHours({{ $hours }})"
                            @class(['seismo-preset', 'seismo-preset--active' => $windowHours === $hours])
                        >
                            {{ $this->presetLabel($hours) }}
                        </button>
                    @endforeach
                </div>
            </div>
            <div class="seismo-bottom-right">
                <svg class="seismo-chip-icon" viewBox="0 0 16 16" aria-hidden="true"><rect x="2" y="3" width="12" height="11" rx="1" fill="none" stroke="currentColor" stroke-width="1.5"/><path d="M2 6h12M5 1v3M11 1v3" stroke="currentColor" stroke-width="1.5"/></svg>
                <span>
                    {{ __('seismo.window_range', [
                        'from' => $this->windowFrom()->utc()->format('M j, Y H:i'),
                        'to' => $this->windowTo()->utc()->format('M j, Y H:i'),
                    ]) }}
                </span>
            </div>
        @else
            <div class="seismo-bottom-left seismo-bottom-left--history">
                <svg class="seismo-clock-icon" viewBox="0 0 16 16" aria-hidden="true"><circle cx="8" cy="8" r="6.5" fill="none" stroke="currentColor" stroke-width="1.5"/><path d="M8 4.5V8l2.5 2" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg>
                <span class="seismo-scrubber-label">{{ __('seismo.scrubber_label') }}</span>
                <input
                    type="range"
                    class="seismo-scrubber"
                    min="{{ $scrubberMinTs }}"
                    max="{{ $scrubberMaxTs }}"
                    value="{{ $scrubberCenterTs }}"
                    wire:key="scrubber-{{ $scrubberCenterTs }}"
                    @input="onScrub($event.target.value)"
                    aria-label="{{ __('seismo.scrubber_aria') }}"
                >
            </div>
            <div class="seismo-bottom-right">
                <svg class="seismo-chip-icon" viewBox="0 0 16 16" aria-hidden="true"><rect x="2" y="3" width="12" height="11" rx="1" fill="none" stroke="currentColor" stroke-width="1.5"/><path d="M2 6h12M5 1v3M11 1v3" stroke="currentColor" stroke-width="1.5"/></svg>
                <span>
                    {{ __('seismo.history_range', [
                        'from' => $this->windowFrom()->utc()->format('M j, Y H:i'),
                        'to' => $this->windowTo()->utc()->format('M j, Y H:i'),
                    ]) }}
                </span>
            </div>
        @endif
    </footer>
</div>
