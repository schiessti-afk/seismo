# ARCHITECTURE — Seismo

Intended system design for **Seismo**. Locked product decisions live in [IDEA.md](./IDEA.md); this doc covers runtime topology, data, ingest, and UI wiring.

---

## 1. System Context

```text
+------------------+         HTTPS          +---------------------------+
| USGS Earthquake  | <--------------------- | Seismo (scheduler →       |
| GeoJSON feeds    |                        | Horizon jobs)             |
+------------------+                        +-------------+-------------+
                                                          |
                    +-------------------------------------+-------------------------------------+
                    |                                     |                                     |
                    v                                     v                                     v
           +----------------+                    +----------------+                    +----------------+
           | PostgreSQL 16  |                    | Redis          |                    | Reverb         |
           | + PostGIS      |                    | Horizon+cache  |                    | WebSockets     |
           +----------------+                    +--------+-------+                    +--------+-------+
                                                          |                                     |
                                                          +------------------+------------------+
                                                                             |
                                                                             v
                                                                  +------------------+
                                                                  | Any browser      |
                                                                  | (public, no auth)|
                                                                  | Livewire/Leaflet |
                                                                  +------------------+
```

**Actors**

| Actor | Role |
|-------|------|
| Scheduler | Dispatches ingest jobs on an interval |
| Horizon | Runs queue workers (ingest, future exports) |
| HTTP app | Public Livewire dashboard + explorer |
| Reverb | Public WebSocket broadcasts |
| Operator | Docker / `.env` only |

---

## 2. Container Topology

Sail-style Compose with PostGIS image override.

| Service | Image / build | Role | Ports |
|---------|---------------|------|-------|
| `laravel.test` | Sail PHP 8.3 | App + (via Supervisor) Horizon, Reverb, `schedule:work` | `80`, Vite `5173`, Reverb `8080` |
| `pgsql` | `postgis/postgis:16-3.4` | DB + spatial | `5432` (optional forward) |
| `redis` | `redis:alpine` | Horizon + cache + broadcast driver | `6379` (optional forward) |

### 2.1 Recommended process layout (v1)

Inside the app container (Sail Supervisor):

| Process | Command | Why |
|---------|---------|-----|
| Web | default Sail PHP server | HTTP / Livewire |
| Horizon | `php artisan horizon` | Queues from day one |
| Scheduler | `php artisan schedule:work` | No host cron required in Docker |
| Reverb | `php artisan reverb:start --host=0.0.0.0 --port=8080` | Browser WS |

Horizon dashboard: available at `/horizon`. **Recommendation:** allow in `local` only (`Horizon::auth` gate), even though the map is public — do not expose queue metrics to the open internet in production without auth.

### 2.2 Compose sketch (illustrative)

```yaml
services:
  laravel.test:
    build:
      context: ./vendor/laravel/sail/runtimes/8.3
      dockerfile: Dockerfile
    image: sail-8.3/app
    ports:
      - '${APP_PORT:-80}:80'
      - '${VITE_PORT:-5173}:${VITE_PORT:-5173}'
      - '${REVERB_SERVER_PORT:-8080}:8080'
    environment:
      LARAVEL_SAIL: 1
    volumes:
      - '.:/var/www/html'
    networks: [sail]
    depends_on: [pgsql, redis]

  pgsql:
    image: 'postgis/postgis:16-3.4'
    environment:
      POSTGRES_DB: '${DB_DATABASE}'
      POSTGRES_USER: '${DB_USERNAME}'
      POSTGRES_PASSWORD: '${DB_PASSWORD}'
    volumes:
      - 'sail-pgsql:/var/lib/postgresql/data'
    networks: [sail]
    healthcheck:
      test: ["CMD-SHELL", "pg_isready -U ${DB_USERNAME} -d ${DB_DATABASE}"]
      retries: 5
      timeout: 5s

  redis:
    image: 'redis:alpine'
    networks: [sail]
    healthcheck:
      test: ["CMD", "redis-cli", "ping"]
      retries: 5
      timeout: 5s

networks:
  sail:
    driver: bridge

volumes:
  sail-pgsql:
  sail-redis:
```

---

## 3. Application Layers

```text
HTTP (Livewire — public map + explorer)
        |
Actions / Services (IngestUsgsFeed, ApplyEarthquakeFilters)
        |
Jobs (BackfillSeismicData, FetchLatestSeismicData)
        |
Model Earthquake + spatial / filter scopes
        |
Http client · PostGIS · Redis · Reverb · Horizon
```

---

## 4. Ingestion

### 4.1 Feed URLs

Base: `https://earthquake.usgs.gov/earthquakes/feed/v1.0/summary/`

| Mode | Feed | When |
|------|------|------|
| Backfill | `all_month.geojson` | First successful empty-DB (or explicit artisan) run |
| Live | `all_hour.geojson` | Every 60 seconds via scheduler → Horizon |

Full public summary matrix (reference; Seismo does not poll every cell):

| | significant | 4.5+ | 2.5+ | 1.0+ | all |
|--|-------------|------|------|-----|-----|
| hour | ✓ | ✓ | ✓ | ✓ | **live** |
| day | ✓ | ✓ | ✓ | ✓ | |
| week | ✓ | ✓ | ✓ | ✓ | |
| month | ✓ | ✓ | ✓ | ✓ | **backfill** |

Using `all_*` avoids missing small events; explorer filters handle magnitude bands.

### 4.2 Pipelines

**Backfill (auto-retry)**

```text
boot / schedule tick
  -> if backfill_completed marker missing AND lock free:
       dispatch BackfillSeismicData
         -> GET all_month.geojson
         -> upsert each feature + raw jsonb (no broadcast)
         -> on full success: set backfill_completed
         -> on failure: leave marker unset; next boot/tick retries
```

**Live**

```text
schedule every minute
  -> dispatch FetchLatestSeismicData
       -> GET all_hour.geojson
       -> upsert each feature + raw jsonb (all magnitudes)
       -> if insert or material change AND magnitude >= 2.5:
            broadcast EarthquakeDetected
       -> on HTTP failure: log + return (Horizon stays healthy)
```

### 4.3 Idempotency & timestamps

| Field | Meaning |
|-------|---------|
| `usgs_id` | Unique; upsert key |
| `occurred_at` | USGS `properties.time` |
| `usgs_updated_at` | USGS `properties.updated` (detect revisions) |
| `recorded_at` | First local insert (unchanged on later upserts) |
| `updated_at` | Local row touch time |

**Material change** (broadcast candidate): magnitude, lat/lon, depth, place, or tsunami differs after upsert.

**Broadcast gate:** candidate **and** `magnitude >= 2.5` (null magnitude → do not broadcast). Storage is ungated.

### 4.4 Parsing rules

- Geometry: GeoJSON `Point` → lon, lat, depth (km).
- Skip features with missing/invalid coordinates; log and continue.
- **`raw` jsonb required** — persist full GeoJSON feature for export/debug.
- `tsunami`: USGS `0` / `1` → boolean.

---

## 5. Broadcasting

| Item | Value |
|------|--------|
| Event | `EarthquakeDetected` (`ShouldBroadcast`) |
| Channel | Public `earthquakes` |
| When | Insert or material change, **and** M≥2.5 |
| Payload | `usgs_id`, `magnitude`, `lat`, `lon`, `depth_km`, `place`, `occurred_at`, `tsunami` |
| Client | Echo → map ripple + activity panel prepend |
| Backfill | No per-event broadcast |

Auth: none on the map channel in v1. Horizon UI remains gated (local/admin).

---

## 6. Spatial & filter query API

### 6.1 PostGIS

- Column: `location geography(Point, 4326)`.
- Index: GiST on `location`.
- Radius: `ST_DWithin(location, ST_SetSRID(ST_MakePoint(:lon, :lat), 4326)::geography, :meters)`.

### 6.2 Eloquent scopes (target)

```php
Earthquake::query()
    ->magnitudeBetween($min, $max)
    ->depthBetween($minKm, $maxKm)
    ->occurredBetween($from, $to)
    ->withinRadius($lat, $lon, $km)
    ->tsunami($enum)      // all|yes|no
    ->placeLike($q)
    ->orderByOccurredDesc();
```

Livewire explorer binds all of the above; empty filter = no constraint.

---

## 7. Data Model (Draft)

### 7.1 `earthquakes`

| Column | Type | Notes |
|--------|------|--------|
| `id` | bigint PK | Local |
| `usgs_id` | string, unique | Upstream id |
| `magnitude` | decimal, nullable | |
| `mag_type` | string, nullable | e.g. `ml`, `mw` |
| `place` | string, nullable | |
| `depth_km` | float, nullable | |
| `location` | geography(Point, 4326) | |
| `tsunami` | boolean, default false | |
| `status` | string, nullable | automatic / reviewed |
| `url` | string, nullable | USGS event page |
| `occurred_at` | timestamptz | |
| `usgs_updated_at` | timestamptz, nullable | |
| `recorded_at` | timestamptz | First local sighting |
| `raw` | jsonb | Full USGS feature (required) |
| `created_at` / `updated_at` | timestamptz | |

### 7.2 Extensions & seed path

Migration runs `CREATE EXTENSION IF NOT EXISTS postgis;`.

Optional: `php artisan seismo:backfill` for re-run / ops (still idempotent).

---

## 8. Frontend

**UI source of truth:** [UI.md](./UI.md)  
**Visual reference:** [mockups/seismo-desktop-mockup.png](./mockups/seismo-desktop-mockup.png)

| Piece | Role |
|-------|------|
| Livewire 3 | Shell, Activity query/pagination, filter state (no auth) |
| Alpine.js | Mode switch, Live Window presets, History scrubber, localStorage |
| Leaflet + dark tiles | World map, mag-scaled markers/rings, popup, controls |
| Echo + Reverb | Live: map ripple + Activity prepend (M≥2.5) |

### 8.1 Layout — must match mockup

```text
+------------------------------------------------------------------+
| SEISMO     ( Live | History )    [Magnitude ≥ 2.5 ▾] [Window 24h ▾] |
+-------------------+----------------------------------------------+
| Activity          |  Dark world map · red circles/rings          |
| mag · place       |  popup: M · place · Local · UTC · ×          |
| Local time        |                    [+][-][locate][layers]    |
| Updates every 10s |                    scale                     |
| Showing 1–15 of N |                                              |
+-------------------+----------------------------------------------+
| Live Window [1h][3h][6h][12h][24h][48h][7d]     UTC range        |
+------------------------------------------------------------------+
```

History: same shell; bottom bar = smooth-drag scrubber (see UI.md §3).

### 8.2 Mode behavior

| Mode | Query | WS | List |
|------|-------|----|------|
| Live | window presets (default 24h) + filters; default mag ≥ 2.5 | Ripple + Activity prepend | Page size **15** |
| History | Scrubber ± `history_slice_hours` + filters | No map append; optional “N new — Live” | Page size **15** |

- Marker click → **popup only** (no Activity sync).
- Activity row click → pan + open popup.
- Header Window chip ↔ bottom Live Window presets stay synced.

### 8.3 Activity sidebar

- Title **Activity** + red pulse icon.
- Row: red mag square, place, date, `{time} Local`.
- Footer: **`Updates every 10s`** · **`Showing {from}–{to} of {total}`**.
- In-app only (no OS notifications in v1).

### 8.4 Magnitude → marker scale

Accent ≈ `#E31A22`. Circles grow and redden with M; high-M events use concentric rings (as in mockup).

| Magnitude | Relative radius | Red intensity |
|-----------|-----------------|---------------|
| &lt; 2.5 | Hidden by default filter (still stored) | — |
| 2.5 – 3.9 | Small | Medium red |
| 4.0 – 4.9 | Medium | Strong red |
| 5.0 – 5.9 | Large + rings | Hot red |
| ≥ 6.0 | Largest + rings | Peak crimson |

### 8.5 i18n

All copy via `lang/en`; v1 English only.

---

## 9. Configuration

| Env | Purpose |
|-----|---------|
| `DB_*` | PostGIS |
| `REDIS_*`, `QUEUE_CONNECTION=redis` | Horizon |
| `BROADCAST_CONNECTION=reverb`, Reverb keys/host/port | WS |
| `USGS_BACKFILL_FEED_URL` | default `.../all_month.geojson` |
| `USGS_LIVE_FEED_URL` | default `.../all_hour.geojson` |
| `SEISMO_INGEST_SECONDS` | default `60` |
| `SEISMO_BACKFILL_ON_BOOT` | default `true` |
| `SEISMO_LIVE_WINDOW_HOURS` | default `24` — must be one of `1,3,6,12,24,48,168` (7d) |
| `SEISMO_LIVE_WINDOW_PRESETS` | `1,3,6,12,24,48,168` — UI bottom bar |
| `SEISMO_HISTORY_SLICE_HOURS` | default `6` — half-width of History scrubber slice (± hours) |
| `SEISMO_LIST_PAGE_SIZE` | default `15` (mockup pagination) |
| `SEISMO_BROADCAST_MIN_MAGNITUDE` | default `2.5` |
| `SEISMO_DEFAULT_FILTER_MIN_MAGNITUDE` | default `2.5` (header chip) |
| `APP_LOCALE` / `APP_FALLBACK_LOCALE` | `en` |

Client may override live window via UI (`localStorage`); server env is the default/fallback.

No USGS API key. Do not invent one in `.env.example`.

**Deploy (v1 docs):** local Docker Desktop + Sail only. No Coolify/VPS runbook in v1.

---

## 10. Failure Modes

| Failure | Behavior |
|---------|----------|
| USGS timeout / 5xx | Log; fail job softly; next schedule tick |
| Malformed feature | Skip row; continue batch |
| Redis down | Horizon unhealthy — fix Redis; HTTP may still read DB |
| Reverb down | Map shows DB state; live ripples pause until reconnect |
| Backfill partial failure | Marker stays unset; **auto-retry** on next boot/tick; upserts idempotent |

---

## 11. Testing & CI

| Area | Approach |
|------|----------|
| Live ingest | Pest + HTTP fake `all_hour` → DB; broadcast only M≥2.5 + material change |
| Backfill | Fake `all_month`; no broadcast; `raw` present; retry if incomplete |
| Idempotency | Run twice → one row per `usgs_id` |
| Filters / spatial | PostGIS in CI; scope matrix tests |
| Livewire | Public page renders; filter / mode smoke |

**GitHub Actions (v1):** `pint --test`, `pest`, `phpstan` / Larastan level 5, against Compose PostGIS service (or CI equivalent).

---

## 12. Security & Ops

- Public map and public WS channel by design — treat payload as non-sensitive USGS mirrors.
- Validate GeoJSON before persist (types, ranges for lat/lon/depth/mag).
- Poll politely: one live URL / minute; backfill once when empty.
- Lock down `/horizon` outside local.
- Public exports at `/export/csv` and `/export/geojson`: require bounded `occurred_from` / `occurred_to`, hard cap `SEISMO_EXPORT_MAX_ROWS` (default 5000), IP rate limit `SEISMO_EXPORT_RATE_PER_MINUTE` (default 10/min) via `throttle:exports`.

---

## 13. Related Docs

- [IDEA.md](./IDEA.md) — product decisions & roadmap  
- [UI.md](./UI.md) — desktop UI spec (mockup-locked)  
- [mockups/seismo-architecture.png](./mockups/seismo-architecture.png) — architecture flowchart graphic  
- [../README.md](../README.md) — operator overview  
