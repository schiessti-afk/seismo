# Sprint 6 — History mode (implementation)

**Status:** complete  
**Plan:** [SPRINT.md](./SPRINT.md) · **UI spec:** [UI.md](./UI.md) §3 · **Mockup:** [mockups/seismo-desktop-mockup.png](./mockups/seismo-desktop-mockup.png) (Live canonical; History reuses shell)  
**Depends on:** Sprint 5 (Live interactivity), Sprint 1 (filter scopes)  
**Unlocks:** Sprint 7 hardening / v1.0 freeze

---

## Goal

Enable History mode in the same public shell: mode pill toggle, bottom time scrubber bound to ± `SEISMO_HISTORY_SLICE_HOURS` queries, WS gated to a “N new — Live” chip (no History map append), plus Pest coverage.

---

## Exit criteria (met)

| Criterion | Evidence |
|-----------|----------|
| Live ↔ History without full page reload | `LiveMonitor::setMode()` + mode pill buttons |
| Scrubber drag updates markers + list for slice | `setScrubberAt()` + `windowFrom()`/`windowTo()` branch on mode |
| Live WS continues; History stable (counter chip only) | `onLiveEarthquake()` gates ripple; `pendingLiveCount` + chip |
| Default mag ≥ 2.5 unless user changes filter | Shared `ApplyEarthquakeFilters` + filter panel unchanged |
| Pest covers slice bounds + WS History gate | `tests/Feature/HomePageTest.php` History tests |

---

## What shipped

| Path | Role |
|------|------|
| `app/Livewire/LiveMonitor.php` | `$mode`, `$scrubberAt`, `$pendingLiveCount`; slice query bounds; WS gate; `goLiveFromChip()` |
| `resources/views/livewire/live-monitor.blade.php` | Mode pill, History scrubber, disabled Window chip, N new chip, conditional poll |
| `resources/css/app.css` | Mode buttons, scrubber track/thumb, new-live chip |
| `resources/js/app.js` | `localStorage` for `seismo.mode` + `seismo.historyScrubAt` |
| `lang/en/seismo.php` | History status, slice chip, scrubber, new-live strings |
| `tests/Feature/HomePageTest.php` | Mode switch, slice bounds, scrubber move, History WS counter |

---

## Architecture

```text
Mode pill (Live | History)
        │
        ├─► Live: windowFrom/To = now − windowHours .. now
        │       wire:poll.10s → refreshLive()
        │       onLiveEarthquake → ripple + prepend
        │
        └─► History: windowFrom/To = scrubCenter ± history_slice_hours (clamped)
                no poll
                onLiveEarthquake → pendingLiveCount++ (live-window filter match)
                "N new — Live" chip → goLiveFromChip()

ApplyEarthquakeFilters (shared mag/depth/radius/tsunami/place/sort)
        └─► Activity paginate(15) + seismo-map-refresh
```

---

## LiveMonitor additions

### Mode / scrubber state

| Property | Default | Notes |
|----------|---------|-------|
| `mode` | `'live'` | `'live'` \| `'history'` |
| `scrubberAt` | `now − sliceHours` ISO | Scrubber center; right edge ≈ now on first enter |
| `pendingLiveCount` | `0` | WS events while in History (live-window match) |

### Scrubber track

| Bound | Value |
|-------|-------|
| Track min | `now − 30 days` |
| Track max | `now` |
| Slice half-width | `config('seismo.history_slice_hours')` (default 6) |
| Thumb clamp | `[trackMin + H, trackMax − H]` |

### Key methods

| Method | Behavior |
|--------|----------|
| `setMode('live'\|'history')` | Reset page; init scrubber on History; dispatch map refresh + `seismo-mode-changed` |
| `setScrubberAt(iso)` | Clamp center; reset page; map refresh + `seismo-scrubber-changed` |
| `goLiveFromChip()` | Clear counter; switch to Live |
| `windowFrom()` / `windowTo()` | Live window or History slice (clamped to track) |
| `onLiveEarthquake()` | History: count only (live-window time match); Live: ripple as before |

---

## Client behavior

- **localStorage keys:** `seismo.mode`, `seismo.historyScrubAt` (restored on mount); `seismo.liveWindowHours` unchanged.
- **Poll:** `wire:poll.10s` only when `$mode === 'live'`.
- **Scrubber:** Alpine debounced drag → `$wire.setScrubberAt()`; status `Idle` / `Scrubbing…`.
- **Window chip:** disabled in History; shows `Slice ±6h` (from config).
- **Echo:** still subscribed; ripple only via Livewire `seismo-map-ripple` (Live-gated server-side).

---

## Manual smoke

1. `.\sail.ps1 up -d` — open `http://localhost`.
2. Click **History** — scrubber appears; Window chip disabled; status **Idle**.
3. Drag scrubber — map + Activity update for ±6h slice; UTC range updates.
4. Run `.\sail.ps1 artisan seismo:broadcast-test` while in History — no ripple; **N new — Live** increments.
5. Click chip → returns to Live; ripples and Activity prepend resume.

---

## Explicitly deferred (Sprint 7+)

| Item | Sprint |
|------|--------|
| Full Pest/CI hardening | 7 |
| M5.0+ / tsunami visual polish | 8 |
| CSV / GeoJSON exports | 9 |
