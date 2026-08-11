# Sprint 5 — Live interactivity (implementation)

**Status:** complete  
**Plan:** [SPRINT.md](./SPRINT.md) · **UI spec:** [UI.md](./UI.md) · **Mockup:** [mockups/seismo-desktop-mockup.png](./mockups/seismo-desktop-mockup.png)  
**Depends on:** Sprint 4 (Live shell), Sprint 3 (Echo/Reverb), Sprint 1 (filter scopes)  
**Unlocks:** Sprint 6 History mode (scrubber in same shell)

---

## Goal

Make Live mode fully operational: window chip sync + localStorage, full filter panel, Activity pagination controls, Echo ripples + Activity prepend (respecting filters), and 10s client poll refresh.

---

## Exit criteria (met)

| Criterion | Evidence |
|-----------|----------|
| Presets/filters update map + Activity without full reload | `LiveMonitor::applyFilters()`, `setWindowHours()`, `wire:poll.10s` |
| WS events → ripples + Activity when filters match | `onLiveEarthquake()` + `seismo-map-ripple` + Echo handler in `app.js` |
| Popup/interaction rules match UI.md | Sprint 4 popup/pan retained; no reverse list sync |
| Default: world, 24h, M≥2.5 | Config defaults + `resetFilters()` |
| Pest/Livewire smoke for filter + window | `tests/Feature/HomePageTest.php` |

---

## What shipped

| Path | Role |
|------|------|
| `app/Actions/ApplyEarthquakeFilters.php` | Shared query binder + `payloadMatches()` for WS gate |
| `app/Livewire/LiveMonitor.php` | Filter state, apply/reset, poll, WS handler, window sync event |
| `app/Models/Earthquake.php` | Added `orderByMagnitudeDesc` scope |
| `app/Events/EarthquakeDetected.php` | Broadcast payload includes local `id` |
| `resources/views/livewire/live-monitor.blade.php` | Filter panel, window dropdown, pagination, poll |
| `resources/js/map.js` | `upsertAndRipple`, `getCenter`, ripple animation |
| `resources/js/app.js` | Echo → Livewire + ripple; localStorage window persistence |
| `resources/css/app.css` | Filter panel, pagination, ripple keyframes |
| `lang/en/seismo.php` | Filter/pagination i18n strings |
| `tests/Feature/HomePageTest.php` | Filter, pagination, WS payload smoke |

---

## Architecture

```text
Echo EarthquakeDetected
        │
        ├─► Livewire.onLiveEarthquake(payload)
        │       └─ ApplyEarthquakeFilters::payloadMatches()
        │               ├─ match → resetPage() + dispatch seismo-map-ripple
        │               └─ no match → ignore
        │
LiveMonitor (wire:poll.10s)
        ├─ baseQuery() via ApplyEarthquakeFilters
        ├─ Activity paginate(15)
        └─ dispatch seismo-map-refresh → Leaflet setEvents()

Window chip ↔ bottom presets → setWindowHours()
        └─ dispatch seismo-window-changed → localStorage
```

---

## LiveMonitor additions

### Filter state

| Property | Default | Notes |
|----------|---------|-------|
| `minMagnitude` | `2.5` | Header chip label |
| `maxMagnitude` | `null` | Optional upper bound |
| `minDepth` / `maxDepth` | `null` | km |
| `centerLat` / `centerLon` / `radiusKm` | `null` | Radius applied only when all three set |
| `tsunami` | `all` | `all\|yes\|no` |
| `place` | `''` | Case-insensitive substring |
| `sort` | `occurred` | `occurred\|magnitude` |

### Key methods

| Method | Behavior |
|--------|----------|
| `applyFilters()` | Normalize inputs, reset page, refresh map |
| `resetFilters()` | Restore config defaults, refresh map |
| `refreshLive()` | Poll target — refresh map markers only |
| `onLiveEarthquake(array)` | Filter-gate WS prepend; dispatch ripple on match |
| `setMapCenter(lat, lon)` | Fill radius center from Leaflet view |

---

## Client behavior

- **localStorage key:** `seismo.liveWindowHours` — restored on mount via Alpine `x-init`.
- **Filter panel:** Magnitude chip dropdown; Apply/Reset buttons (not live on every keystroke).
- **Use map center:** Reads `SeismoMap.getCenter()` into lat/lon fields.
- **Ripple:** Temporary expanding circle marker; upserts marker if new event passes filters.

---

## Manual smoke

1. `.\sail.ps1 up -d` (or WSL `./vendor/bin/sail up -d`)
2. Open `http://localhost` — change window preset via chip and bottom bar; confirm UTC range updates.
3. Open filter panel — apply place/magnitude filters; map + Activity update without reload.
4. Paginate Activity when >15 rows.
5. Run `.\sail.ps1 artisan seismo:broadcast-test` — matching events ripple on map and appear in Activity.

---

## Explicitly deferred (Sprint 6+)

| Item | Sprint |
|------|--------|
| History mode + scrubber | 6 |
| M5.0+ / tsunami visual polish | 8 |
| CSV / GeoJSON exports | 9 |
