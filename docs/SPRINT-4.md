# Sprint 4 — Live UI shell (implementation)

**Status:** complete  
**Plan:** [SPRINT.md](./SPRINT.md) · **UI spec:** [UI.md](./UI.md) · **Mockup:** [mockups/seismo-desktop-mockup.png](./mockups/seismo-desktop-mockup.png)  
**Depends on:** Sprint 1 (model/scopes), Sprint 2 (ingested data), Sprint 3 (Echo/Reverb wiring)  
**Unlocks:** Sprint 5 interactivity (filters, WS ripples, localStorage, popups polish)

---

## Goal

Replace the branded placeholder at `/` with the public Live desktop shell: mockup-matched chrome, Activity sidebar fed from PostGIS, and a Leaflet world map with mag-scaled markers. Default query: last **24h**, magnitude **≥ 2.5**.

---

## Exit criteria (met)

| Criterion | Evidence |
|-----------|----------|
| Desktop Live regions match mockup | `resources/views/livewire/live-monitor.blade.php` + `resources/css/app.css` |
| Activity + map show real PostGIS rows (24h / M≥2.5) | `LiveMonitor::baseQuery()` + Pest filter tests |
| No auth on public page | `routes/web.php` — open `LiveMonitor` route |
| i18n for visible copy | `lang/en/seismo.php` — no hardcoded UI strings in Blade |
| History stub only; WS polish deferred | History pill disabled; Echo logs only (Sprint 3 smoke retained) |
| UI.md Live checklist checked | [UI.md](./UI.md) §5 items marked complete |

---

## What shipped

| Path | Role |
|------|------|
| `app/Livewire/LiveMonitor.php` | Full-page Livewire component: query, pagination, window presets |
| `resources/views/livewire/live-monitor.blade.php` | Shell layout: top bar, Activity, map pane, bottom bar |
| `resources/views/layouts/seismo.blade.php` | App layout: Vite assets, DM Sans, Livewire scripts |
| `resources/js/map.js` | Leaflet map: tiles, markers, rings, popup, controls |
| `resources/js/app.js` | Map init, Livewire refresh hook, Echo smoke, pan-to bridge |
| `resources/css/app.css` | Theme tokens + full desktop grid (top / sidebar / map / bottom) |
| `lang/en/seismo.php` | All visible UI strings |
| `routes/web.php` | `GET /` → `LiveMonitor::class` |
| `tests/Feature/HomePageTest.php` | Shell render, filter, pagination, window preset Livewire tests |
| `package.json` | `leaflet` dependency |

**Removed:** `resources/views/placeholder.blade.php` (Sprint 0 placeholder retired).

---

## Architecture

```text
GET /  →  LiveMonitor (Livewire 4 full-page)
              │
              ├─ baseQuery() ── magnitudeBetween + occurredBetween + orderByOccurredDesc
              │
              ├─ Activity  → paginate(SEISMO_LIST_PAGE_SIZE)  [15 rows]
              │
              └─ mapEvents() → JSON payload for Leaflet
                                    │
                                    ▼
              wire:ignore #seismo-map  ←  resources/js/map.js (SeismoMap)
                                    │
              setWindowHours() ── dispatch('seismo-map-refresh') ──► refresh markers
```

Livewire owns server state and HTML chrome. Leaflet runs as a client island on `#seismo-map` (`wire:ignore`) so Livewire morphs do not destroy the map instance. Window preset changes re-query on the server and push fresh marker data via a Livewire browser event.

Echo remains subscribed (Sprint 3 smoke) but does **not** mutate the map or Activity list in Sprint 4 — that lands in Sprint 5.

---

## LiveMonitor component

### State

| Property | Default | Source |
|----------|---------|--------|
| `windowHours` | `24` | `config('seismo.live_window_hours')` |
| `minMagnitude` | `2.5` | `config('seismo.default_filter_min_magnitude')` |
| `$page` | `1` | Livewire `WithPagination` |

### Query

Shared by Activity list and map payload:

```php
Earthquake::query()
    ->magnitudeBetween($this->minMagnitude, null)
    ->occurredBetween(now()->subHours($this->windowHours), now())
    ->orderByOccurredDesc();
```

- **Activity:** `paginate(config('seismo.list_page_size'))` — 15 rows, footer `Showing x–y of z`.
- **Map:** same filters, all matching rows (no pagination cap on map markers).

### Actions

| Method | Behavior |
|--------|----------|
| `setWindowHours(int $hours)` | Validates against `config('seismo.live_window_presets')`; resets pagination; dispatches `seismo-map-refresh` with updated `mapEvents()` |
| `windowFrom()` / `windowTo()` | UTC range readout for bottom bar |
| `presetLabel()` / `windowChipLabel()` | i18n labels for presets and header Window chip |

### Layout

Uses `#[Layout('layouts.seismo')]` — minimal HTML shell with Vite + Livewire assets.

---

## UI regions (mockup match)

| Region | Implementation | Sprint 4 behavior |
|--------|----------------|-----------------|
| Top bar | `.seismo-topbar` | **SEISMO** brand; Live active (red) \| History disabled stub |
| Magnitude chip | `.seismo-chip` | Label `Magnitude ≥ 2.5` only — no filter panel |
| Window chip | `.seismo-chip` | Reflects `$windowHours` (read-only display) |
| Activity sidebar | `.seismo-activity` | Mag badge, place, date, Local time; 15/page; status footer |
| Map | `#seismo-map` | CartoDB dark tiles; mag-scaled red circle markers |
| Bottom bar | `.seismo-bottombar` | Live Window presets `1h 3h 6h 12h 24h 48h 7d`; UTC range |

### Theme tokens

Defined in `resources/css/app.css`:

| Token | Value |
|-------|-------|
| Ground | `#0B0D10` |
| Surface | `#12151A` |
| Border | `#1E2329` |
| Accent | `#E31A22` |
| Text | `#F2F3F5` |
| Muted | `#8B929A` |

Font: **DM Sans** (Google Fonts, loaded in layout).

---

## Leaflet map (`resources/js/map.js`)

### Basemap

CartoDB Dark Matter:

```text
https://{s}.basemaps.cartocdn.com/dark_all/{z}/{x}/{y}{r}.png
```

Default view: world (`center: [20, 0]`, `zoom: 2`).

### Marker scale (Architecture §8.4)

| Magnitude | Radius | Rings |
|-----------|--------|-------|
| 2.5 – 3.9 | 5px | — |
| 4.0 – 4.9 | 8px | — |
| 5.0 – 5.9 | 11px | 18px, 24px |
| ≥ 6.0 | 14px | 22px, 30px |

Markers use `L.circleMarker` filled with accent red; rings are low-opacity concentric circles for higher magnitudes.

### Popup

Dark panel on marker click:

- Large red magnitude
- Place
- `{date} {time} Local` (browser timezone via `Intl`)
- Gray `UTC …` secondary line
- Custom close **×** button

Marker click opens popup only — no Activity list sync (per UI.md).

### Controls (bottom-right stack)

- Zoom **+** / **−** (`L.control.zoom`)
- Locate (browser geolocation via `map.locate`)
- Layers (toggle dark basemap on/off)
- Metric scale bar (`L.control.scale`)

### Client bridges

| Event | Direction | Purpose |
|-------|-----------|---------|
| `seismo-map-refresh` | Livewire → JS | Replace markers after window preset change |
| `seismo-pan-to` | Activity row click → JS | Pan map + open popup for event id |
| `seismo:earthquake-detected` | Echo → window | Sprint 3 smoke only; no UI mutation yet |

Initial marker data is embedded in `<script id="seismo-map-data" type="application/json">` on first render.

---

## Time display

| Location | Format |
|----------|--------|
| Activity rows | Browser local via Alpine `Intl` (`toLocaleDateString` / `toLocaleTimeString`) + `Local` suffix |
| Map popup | Local primary; UTC gray secondary |
| Bottom bar range | Server-rendered UTC (`windowFrom()` → `windowTo()`) |

All labels (`Local`, `UTC`, etc.) come from `lang/en/seismo.php`.

---

## i18n

All user-visible strings live in `lang/en/seismo.php`:

```php
'brand', 'mode_live', 'mode_history', 'magnitude_chip', 'window_chip_hours',
'window_chip_7d', 'activity_title', 'updates_every', 'showing_range',
'local_suffix', 'utc_prefix', 'live_window', 'preset_hours', 'preset_7d',
'window_range', 'popup_close'
```

Blade uses `__('seismo.*')` exclusively for chrome copy.

---

## Explicitly deferred (Sprint 5+)

| Feature | Sprint |
|---------|--------|
| History mode + time scrubber | 6 |
| Magnitude filter panel (depth, radius, tsunami, place, max) | 5 |
| Header Window chip dropdown / sync click | 5 |
| `localStorage` window persistence | 5 |
| Echo → map ripple + Activity prepend | 5 |
| 10s client poll / auto-refresh | 5 |
| Activity pagination UI controls | 5 |
| Full marker ↔ list interaction polish | 5 |

Footer copy **`Updates every 10s`** is present as UI status text; server USGS ingest remains **60s** (unchanged).

---

## Tests

`tests/Feature/HomePageTest.php`:

| Test | Asserts |
|------|---------|
| `renders the live monitor shell` | SEISMO, Activity, Live Window, magnitude chip, presets |
| `shows earthquakes within the default window and magnitude filter` | Visible/invisible rows by mag and time window |
| `paginates activity rows` | 16 events → `Showing 1–15 of 16` |
| `changes the live window via livewire` | `setWindowHours(6)` updates state + renders `6h` |
| `rejects invalid window presets` | Invalid hours ignored; default 24h kept |

Full suite: **26 tests, 77 assertions** (all green after Sprint 4).

---

## Verify locally

```powershell
.\sail.ps1 up -d
.\sail.ps1 artisan migrate
.\sail.ps1 npm run build
.\sail.ps1 artisan test --filter=HomePage
```

Open `http://localhost` — expect the Live monitor with header, Activity sidebar, dark map, and bottom preset bar.

With ingested data:

```powershell
.\sail.ps1 artisan seismo:backfill   # if DB empty
```

Events with M ≥ 2.5 in the last 24h appear in Activity and as map markers.

### Manual checks

1. **Layout** — compare against [mockup](./mockups/seismo-desktop-mockup.png): top bar, left Activity, main map, bottom presets.
2. **Presets** — click `6h`, `48h`, `7d`; Activity list and map markers update; bottom UTC range changes; header Window chip label updates on re-render.
3. **Map** — click a marker → popup with mag, place, Local, UTC; zoom/locate/layers/scale controls work.
4. **Activity row** — click a row → map pans to event and opens popup.
5. **Echo smoke** — DevTools console still logs `[Seismo] EarthquakeDetected` when running `.\sail.ps1 artisan seismo:broadcast-test` (no UI change yet).

---

## Notes

- **Livewire 4** full-page component pattern; Alpine comes from `@livewireScripts` (not a separate Alpine boot).
- Map singleton in `map.js` — one Leaflet instance per page load; `refreshSeismoMap()` clears and re-adds markers.
- Activity row pan uses a plain `CustomEvent` bridge rather than Livewire round-trip — sufficient for Sprint 4; Sprint 5 may refine.
- Responsive CSS stacks Activity below map below 768px width.
- Config knobs from Sprint 1 (`SEISMO_LIVE_WINDOW_HOURS`, `SEISMO_LIST_PAGE_SIZE`, etc.) are now consumed by the UI.
