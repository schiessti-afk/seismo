# SPRINT — Seismo

Sprint plan for delivering Seismo against [IDEA.md](./IDEA.md), [ARCHITECTURE.md](./ARCHITECTURE.md), and [UI.md](./UI.md).  
No durations — each sprint ends when its **exit criteria** are met.

**Canonical UI:** [mockups/seismo-desktop-mockup.png](./mockups/seismo-desktop-mockup.png)

---

## Overview

| Sprint | Focus | Outcome | As-built |
|--------|--------|---------|----------|
| 0 | Scaffold & repo | Runnable Sail stack, empty app shell, CI skeleton | — |
| 1 | Data layer | PostGIS schema, `Earthquake` model, spatial/filter scopes | [SPRINT-1.md](./SPRINT-1.md) |
| 2 | Ingestion | Backfill + live poll via Horizon, idempotent upserts | [SPRINT-2.md](./SPRINT-2.md) |
| 3 | Realtime | Reverb broadcasts for M≥2.5 material changes | [SPRINT-3.md](./SPRINT-3.md) |
| 4 | Live UI shell | Desktop chrome matches mockup; map + Activity from DB | — |
| 5 | Live interactivity | Window presets, filters, WS ripples, popups, pagination | [SPRINT-5.md](./SPRINT-5.md) |
| 6 | History mode | Time scrubber + slice queries in same shell | [SPRINT-6.md](./SPRINT-6.md) |
| 7 | Hardening | Full Pest suite, CI green, i18n/times polish | [SPRINT-7.md](./SPRINT-7.md) |
| 8 | Alerts polish | M5.0+ / tsunami emphasis, optional sound (roadmap v1.2) | [SPRINT-8.md](./SPRINT-8.md) |
| 9 | Exports | CSV / GeoJSON of stored data, rate-limited (roadmap v1.3) | [SPRINT-9.md](./SPRINT-9.md) |

Sprints **0–7** = v1.0 shippable public monitor. **8–9** = near-term roadmap. Multi-provider (v2.0) is out of this plan.

---

## Sprint 0 — Scaffold & repository prep

Bootstrap the Laravel application and engineering baseline so later sprints only add product code.

> **Implemented with Laravel 12 + PHP 8.4 Sail** (Laravel 11 is security-EOL; Composer blocks all 11.x installs. Current framework deps require PHP ≥ 8.4).

### Work

- [x] Create Laravel app (PHP 8.4 Sail) in repo root; keep `docs/` as-is
- [x] Add Laravel Sail; override DB image to `postgis/postgis:16-3.4`
- [x] Wire Redis; install **Horizon**, **Reverb**, **Livewire**, Alpine/Vite baseline
- [x] Supervisor (or Sail equivalents) for: web, `horizon`, `schedule:work`, `reverb:start`
- [x] Publish `.env.example` with Seismo keys from Architecture (no secrets); document Sail-only local path in README
- [x] Repo hygiene: `.gitignore`, MIT license © Micha Schiess, README links to docs
- [x] GitHub Actions skeleton: checkout, Composer, Pint `--test` (tests can be noop/smoke)
- [x] `declare(strict_types=1);` convention; Pint + Larastan level 5 config present
- [x] Public `/` route renders a minimal branded placeholder (SEISMO) proving the stack boots
- [x] Gate `/horizon` to local only

### Exit criteria

- `sail up` brings app + PostGIS + Redis healthy
- `sail artisan migrate` succeeds (even if only default Laravel tables)
- Horizon, scheduler, and Reverb processes are defined and start with the stack
- CI workflow exists and runs Pint on the scaffold
- No credentials committed; `.env.example` is complete for local Sail

---

## Sprint 1 — Data layer (PostGIS + domain)

> **Implementation:** [SPRINT-1.md](./SPRINT-1.md)

Persist earthquakes with spatial indexes and query scopes the UI/ingest will share.

### Work

- [x] Migration: `CREATE EXTENSION IF NOT EXISTS postgis`
- [x] `earthquakes` table per Architecture (`usgs_id` unique, `location` geography, `raw` jsonb required, timestamps, tsunami, etc.)
- [x] GiST index on `location`
- [x] Eloquent `Earthquake` model + casts/factories
- [x] Scopes: magnitude/depth/occurred/radius/tsunami/place/order
- [x] Pest tests for factory + at least one spatial scope against PostGIS in CI/Compose
- [x] Artisan `seismo:backfill` command stub (no HTTP yet) for ops surface

### Exit criteria

- Migrations create PostGIS-enabled schema cleanly on fresh Sail DB
- Unique `usgs_id` enforced
- Spatial radius query works in a Pest test
- Model is the single write/read API for later ingest/UI sprints

---

## Sprint 2 — Ingestion & backfill

> **Implementation:** [SPRINT-2.md](./SPRINT-2.md)

Fill the database from USGS; never duplicate; recover from failed backfill.

### Work

- [x] USGS HTTP client for summary GeoJSON (`all_month`, `all_hour` URLs from env)
- [x] Parser: Feature → attributes + `raw` jsonb; skip invalid geometry with logs
- [x] Job `BackfillSeismicData`: upsert batch; set `backfill_completed` only on full success
- [x] Job `FetchLatestSeismicData`: upsert `all_hour`; no broadcast yet (or no-op dispatch)
- [x] Auto-retry: on boot/schedule, dispatch backfill if marker missing + lock free
- [x] Schedule live job every 60s; run through Horizon
- [x] Idempotency Pest: same fixture twice → one row; `recorded_at` stable on update
- [x] Soft-fail on USGS timeout/5xx (log, don’t kill Horizon)

### Exit criteria

- Empty DB → backfill completes with ~30 days of data (or fixture in tests)
- Partial backfill failure leaves marker unset and retries
- Live job upserts without duplicates under repeated runs
- All magnitudes stored; `raw` present on every row

---

## Sprint 3 — Realtime broadcasting

> **Implementation:** [SPRINT-3.md](./SPRINT-3.md)

Push live updates to browsers for qualifying events only.

### Work

- [x] `EarthquakeDetected` event (`ShouldBroadcast`) on public channel `earthquakes`
- [x] Emit only on insert or material field change **and** magnitude ≥ `SEISMO_BROADCAST_MIN_MAGNITUDE` (2.5)
- [x] Payload: map-ready DTO (id, mag, lat, lon, depth, place, occurred_at, tsunami)
- [x] Backfill path never broadcasts
- [x] Echo + Reverb client config in Vite/Laravel
- [x] Pest: assert broadcast / `Event::fake` for M≥2.5 insert; assert no broadcast for M&lt;2.5 and no-op upsert
- [x] Manual smoke: two browsers receive the same test broadcast

### Exit criteria

- Reverb reachable from browser on Sail port 8080
- Only material M≥2.5 live changes hit the channel
- Tests lock the broadcast gate and backfill silence

---

## Sprint 4 — Live UI shell (mockup chrome)

Build the desktop composition so it looks like the canonical mockup, fed from DB (polling or initial load OK).

### Work

- [x] Dark + red theme tokens (accent ≈ `#E31A22`); CartoDB dark (or equivalent) tiles
- [x] Top bar: **SEISMO**, Live | History pill (History can be disabled/stub), Magnitude chip, Window chip
- [x] Left **Activity** list: mag square, place, Local time; page size 15; `Showing x–y of z`
- [x] Footer status copy `Updates every 10s` (UI)
- [x] World Leaflet map; mag-scaled circle markers (+ rings for higher M)
- [x] Map controls: zoom ±, locate, layers; scale bar
- [x] Bottom **Live Window** presets UI: `1h 3h 6h 12h 24h 48h 7d` (wiring can be partial if query uses default 24h)
- [x] Default filter mag ≥ 2.5; English via lang files
- [x] Compare against [UI.md](./UI.md) checklist / mockup

### Exit criteria

- Desktop Live view visually matches mockup regions and controls
- Activity + map show real PostGIS rows for default 24h / M≥2.5
- No auth on public page; i18n keys used for visible copy
- UI.md Live checklist largely checked (WS polish can wait for Sprint 5)

---

## Sprint 5 — Live interactivity

Make Live mode fully operational: settings, filters, WebSockets, popups.

### Work

- [x] Sync header Window chip ↔ bottom Live Window presets; persist in `localStorage`
- [x] Query map/list by selected window; UTC range readout on bottom bar
- [x] Magnitude/filter control: min/max, depth, radius, tsunami, place (as per IDEA)
- [x] Marker click → popup only (large M, place, Local, small UTC, ×)
- [x] Activity row click → pan + open popup (no reverse list sync from marker)
- [x] Echo: on `EarthquakeDetected`, ripple marker + prepend Activity (respect filters)
- [x] Pagination for Activity (15/page)
- [x] Livewire/Pest smoke for filter + window changes

### Exit criteria

- Changing presets/filters updates map + Activity without full page reload
- WS events appear as ripples + Activity rows for M≥2.5
- Popup and interaction rules match UI.md
- Default state: world, 24h, Magnitude ≥ 2.5

---

## Sprint 6 — History mode

Same shell; explore time with a scrubber.

### Work

- [x] Enable History in mode pill; swap bottom bar to smooth-drag scrubber (no play/pause)
- [x] Bind map + Activity to scrubber slice (± `SEISMO_HISTORY_SLICE_HOURS`, default 6) + filters
- [x] Do not append WS events to History map; optional “N new — Live” chip
- [x] History Activity pagination + appropriate status copy
- [x] Pest/Livewire coverage for slice query bounds

### Exit criteria

- User can switch Live ↔ History without leaving the page
- Dragging scrubber updates markers + list for the slice
- Live continues to receive WS; History remains stable until returning to Live
- Default mag ≥ 2.5 still applies unless user changes filter

---

## Sprint 7 — Hardening & v1.0 freeze

Productionize the v1.0 slice: tests, CI, polish, ops docs.

### Work

- [x] Expand Pest: ingest, idempotency, broadcast gate, scopes, Livewire modes
- [x] CI: Pint, Pest (with PostGIS service), Larastan level 5 — all green on main
- [x] Review i18n coverage; Local primary / UTC secondary everywhere events show
- [x] Confirm Horizon auth gate; README quick start matches real commands
- [x] Failure-mode pass: USGS down, Reverb down, Redis restart — app degrades as documented
- [x] UI.md acceptance checklist complete for Live; History behaviors documented
- [x] Tag / declare **v1.0** in README status

### Exit criteria

- CI green on the v1.0 feature set
- Fresh Sail clone path works from README alone
- No known P0 gaps vs locked decisions for sprints 0–6
- Docs (README status, IDEA roadmap checkboxes for v1.0) reflect shipped state

---

## Sprint 8 — Alerts polish (roadmap v1.2)

Stronger operator attention for significant events.

### Work

- [x] Stronger Activity + map treatment for M≥5.0
- [x] Tsunami banner/badge when `tsunami` true (feed flag, not external warning product)
- [x] Optional client sound toggle (default off); still no OS Notification API unless trivial
- [x] i18n strings for new chrome

### Exit criteria

- M≥5.0 and tsunami states are obvious in Live UI without cluttering the mockup layout
- Sound is optional and muted by default
- Tests or manual script cover badge/banner visibility rules

---

## Sprint 9 — Exports (roadmap v1.3)

Download what this instance has stored.

### Work

- [x] Export endpoints or actions: CSV and GeoJSON of filtered/stored set
- [x] Hard caps / rate limits / pagination for public abuse resistance
- [x] Optional Parquet only if low-cost; otherwise defer
- [x] Pest for export shape + cap enforcement
- [x] Document usage in README

### Exit criteria

- User can export a bounded CSV and GeoJSON of app-recorded events
- Unbounded dump is impossible via public UI/API
- Export respects current filters (or documented subset)

---

## Out of scope (later / v2.0+)

- EMSC / JMA / FDSN multi-provider ingest
- Additional locales beyond `en` keys
- Auth, multi-tenant SaaS, mobile apps
- VPS/Coolify production runbook (v1 docs stay Sail-local)

---

## How to use this plan

1. Open a sprint; work the checklist top-to-bottom where dependencies allow.
2. Do not start the next sprint until **exit criteria** pass (tests + manual checks).
3. If a decision conflicts with [UI.md](./UI.md) / mockup, the mockup wins for chrome; Architecture wins for data/ingest.
4. Update IDEA roadmap status when Sprint 7 (v1.0), 8 (v1.2), or 9 (v1.3) completes.
