# IDEA — Seismo

> **Seismo** is a self-hosted, real-time seismic monitoring and historical analysis platform. It ingests USGS GeoJSON summary feeds via Laravel Horizon workers, persists spatial events in PostgreSQL + PostGIS, backfills up to 30 days on first boot, and streams new events to a **public** Livewire + Leaflet dashboard over Laravel Reverb — no page refresh, no login.

---

## 1. Problem Statement & Motivation

Public earthquake monitors are often ad-heavy or static (manual refresh). Many Laravel portfolio apps stop at CRUD. Seismo shows an event-driven Laravel stack for spatial data, scheduled ingestion, Horizon queues, and WebSocket updates — fully containerized with Docker / Sail.

---

## 2. Decisions (locked)

| Topic | Decision |
|-------|----------|
| Product name | **Seismo** (repo / folder `seismo`) |
| Auth | **Public** read-only map and explorer (no login in v1) |
| Queues | **Laravel Horizon** from day one (not plain `queue:work`) |
| Live poll | USGS `all_hour` every **60s** (feed itself updates ~every minute) |
| First-run backfill | One-shot ingest of USGS `all_month` (**past 30 days**, all magnitudes) — max window of the public summary feeds |
| UI source of truth | Desktop must match [UI.md](./UI.md) + [mockups/seismo-desktop-mockup.png](./mockups/seismo-desktop-mockup.png) |
| Map modes | **Live** ↔ **History** center pill (see §5.6 / UI.md) |
| Live map window | Default **24h**; presets **1h · 3h · 6h · 12h · 24h · 48h · 7d** (header chip + bottom bar) |
| History UX | Bottom **time scrubber** — smooth drag only (no play/pause) |
| Event feed | Left **Activity** sidebar (mag badge, place, local time) |
| Activity pagination | **15** per page; footer `Showing 1–15 of N` |
| Activity status | Footer copy **`Updates every 10s`** (UI); USGS ingest still **60s** |
| Marker click | **Popup only** (no list highlight sync) |
| Activity row click | Pan to event + open popup |
| Default mag filter | Header **`Magnitude ≥ 2.5`**; hide lower until user changes filter |
| Map default | World view; zoom ±, locate, layers; scale bar |
| Visual | Dark charcoal + accent **`#E31A22`**; mag-scaled red circles/rings |
| UI filters | Mag + window in header; depth/radius/tsunami/place inside filter control (see §5.5) |
| Persist `raw` jsonb | **Yes** — full USGS feature per row |
| Broadcast rule | Insert or **material field change** only (no no-op upsert noise) |
| Broadcast magnitude gate | WebSocket ripples / activity push for **M2.5+** only; **store all** magnitudes |
| Marker styling | Circle radius + redness scale with magnitude |
| Backfill failure | **Auto-retry** on boot until complete (idempotent) |
| Deploy docs (v1) | **Sail / Docker Desktop** local path only |
| CI | GitHub Actions in v1: Pint, Pest, Larastan level 5 |
| Demo data | **USGS only** (no offline fixture seed) |
| Language | English UI strings; **Laravel i18n** (`lang/en`) so locales can be added later |
| Timestamps | Show **browser-local** time; small UTC secondary |
| Broadcast channel | Public `earthquakes` channel (no private channel auth in v1) |
| External alert SaaS | Slack / Bugsnag **deferred**; log failures to Laravel log in v1 |
| License | MIT © Micha Schiess |

---

## 3. Product Goals

| Goal | Description |
|------|-------------|
| Live map | Last-N-hours view (default 24h) with WS updates and activity feed |
| History map | Scrub through stored time range; filters still apply |
| Spatial queries | PostGIS points; radius + magnitude + time + depth filters |
| Self-hosted | Docker Compose / Sail; no cloud lock-in for core runtime |
| Portfolio signal | Horizon, Reverb, PostGIS, Livewire, Pest |
| Visual identity | Dark + red, modern operations-center feel |

### Non-goals (v1)

- Official emergency / seismology certification
- Multi-tenant SaaS or paid alerting
- User accounts / admin panel
- Mobile native apps
- History beyond USGS summary feeds (30 days) — FDSN / multi-provider is v2+
- Claiming “sub-second earthquake detection” (USGS cadence dominates; UI updates after local ingest)

---

## 4. Core Architecture (Summary)

```text
USGS GeoJSON summary feeds
       |
  first boot: all_month (backfill)
  then every 60s: all_hour (live)
       v
Laravel 11 (PHP 8.3) + Horizon — parse, upsert, dispatch events
       |                    |
       v                    v
PostgreSQL 16 + PostGIS   Redis (Horizon queues + broadcast)
                              |
                              v
                     Laravel Reverb (WebSockets :8080)
                              |
                              v
              Public browser — Livewire 3 + Alpine + Leaflet
```

Details: [ARCHITECTURE.md](./ARCHITECTURE.md).

---

## 5. Key Technical Capabilities

### 5.1 USGS feeds (what we use)

Public summary GeoJSON base:

`https://earthquake.usgs.gov/earthquakes/feed/v1.0/summary/{name}.geojson`

| Window | Updates | Seismo usage |
|--------|---------|--------------|
| Past hour (`*_hour`) | ~1 min | **Live poll:** `all_hour` |
| Past day (`*_day`) | ~1 min | Available for manual/ops; not required every tick |
| Past 7 days (`*_week`) | ~1 min | Available for manual/ops |
| Past 30 days (`*_month`) | ~1 min | **First-run backfill:** `all_month` |

Magnitude / significance variants (same windows): `all`, `1.0`, `2.5`, `4.5`, `significant`.

Seismo stores **all magnitudes** via `all_*` feeds. Magnitude filtering is a **query/UI** concern, not an ingest exclusion. Dedupe is always by USGS feature `id`.

### 5.2 Idempotent ingestion + backfill

- Job family: `BackfillSeismicData` + `FetchLatestSeismicData` (scheduled).
- Upsert by `usgs_id`; never duplicate rows.
- Persist `occurred_at`, `recorded_at`, and full feature in **`raw` jsonb**.
- Backfill when not marked complete; **auto-retry on boot** after partial failure (lock + idempotent upserts).
- Backfill never storms the WebSocket channel.

### 5.3 Spatial engine

- `geography(Point, 4326)` + GiST index.
- Example: M5.0+ within 500 km of a point in the last 30 days among stored events.

### 5.4 Real-time telemetry

- `EarthquakeDetected` on public channel `earthquakes` via Reverb.
- Emit only on **first insert** or **material revision** (magnitude, lat/lon, depth, place, tsunami) — not silent no-ops.
- **Magnitude gate:** broadcast (ripple + activity panel push) only if magnitude **≥ 2.5**. Sub-2.5 events are still stored and visible when filters allow.
- Marker / ripple: **larger radius and stronger red** as magnitude increases (client scale; see Architecture).

### 5.5 Filters

**Chrome (as in mockup):** header chips **Magnitude ≥ 2.5** and **Window 24h**.

**Inside magnitude/filter control** (not a second top row):

| Filter | Behavior |
|--------|----------|
| Magnitude min (default 2.5) / max | Inclusive |
| Depth min / max (km) | Inclusive |
| Distance radius | Center lat/lon + km (`ST_DWithin`) |
| Tsunami | Any / yes / no |
| Place search | Case-insensitive substring |
| Sort | Occurred-at or magnitude |

History also uses the scrubber for time; Live uses the window presets. Filters update Activity + map together.

### 5.6 Desktop UX (mockup-locked)

Full layout, copy, and checklist: **[UI.md](./UI.md)**.  
Visual reference: **[mockups/seismo-desktop-mockup.png](./mockups/seismo-desktop-mockup.png)**.

| Mode | Map / list | Bottom bar |
|------|------------|------------|
| **Live** | `occurred_at` within selected window (default 24h) + filters; WS prepends Activity | **Live Window** presets `1h 3h 6h 12h 24h 48h 7d` + UTC range readout |
| **History** | Scrubber slice (±6h default) + filters; no WS map append | Smooth-drag **scrubber** only |

**Why 24h default:** ingest fetches `all_hour`, but PostGIS holds up to ~30 days after backfill — a 24h query keeps the globe populated.

**Activity:** 15/page, `Showing x–y of z`, status `Updates every 10s`. Popup: large red M · place · Local · small UTC · ×.

**i18n:** Laravel lang `en` only in v1.

---

## 6. Non-Functional Requirements

| Requirement | Rule |
|-------------|------|
| Idempotency | Unique `usgs_id`; upsert only |
| Resilience | USGS timeout / 5xx → log, skip cycle; Horizon keeps running |
| Type safety | `declare(strict_types=1);` on app PHP; Larastan level 5 |
| Secrets | Nothing sensitive in Git; `.env` only (USGS needs no API key) |
| Public access | Map, explorer, public WS channel — no auth gate in v1 |
| Courtesy | One live feed URL per minute; backfill once, not on every boot if data exists |

---

## 7. Roadmap

| Version | Status | Scope |
|---------|--------|--------|
| v1.0 | Shipped | Horizon ingest, auto-retry backfill, Live/History, scrubber, activity panel, i18n(`en`), CI, dark+red mag-scaled markers |
| v1.1 | Planned | Reverb end-to-end polish |
| v1.2 | Shipped | Optional sound; stronger M5.0+ / tsunami banner |
| v1.3 | Shipped | Export CSV / GeoJSON (rate-limited) |
| v2.0 | Planned | Multi-provider (EMSC, JMA); optional FDSN; additional locales |

---

## 8. Remaining open items (narrow)

1. Horizon / Reverb / scheduler Supervisor wiring in Sail (see Architecture).
2. History-mode mockup (Live mockup is canonical; History reuses shell per UI.md).

---

## 9. License

MIT © Micha Schiess
