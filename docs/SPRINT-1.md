# Sprint 1 — Data layer (implementation)

**Status:** complete  
**Plan:** [SPRINT.md](./SPRINT.md) · **Architecture:** [ARCHITECTURE.md](./ARCHITECTURE.md) §6–7  
**Depends on:** Sprint 0 (Sail + PostGIS image)  
**Unlocks:** Sprint 2 ingest, Sprint 4+ UI queries

---

## Goal

Persist earthquakes in PostGIS with a single Eloquent API (casts, factories, spatial/filter scopes) shared by ingest and the Live UI.

---

## Exit criteria (met)

| Criterion | Evidence |
|-----------|----------|
| Fresh Sail DB gets PostGIS + `earthquakes` | `database/migrations/2026_08_11_000000_create_earthquakes_table.php` |
| Unique `usgs_id` | Unique index + Pest uniqueness test |
| Spatial radius query works | `withinRadius` Pest against PostGIS in CI |
| Model is the write/read API | `App\Models\Earthquake` + scopes used by later sprints |
| Ops surface for backfill | `php artisan seismo:backfill` |

---

## What shipped

| Path | Role |
|------|------|
| `database/migrations/2026_08_11_000000_create_earthquakes_table.php` | Extension, table, GiST index |
| `app/Models/Earthquake.php` | Model, lat/lon ↔ geography, scopes |
| `database/factories/EarthquakeFactory.php` | USGS-shaped factory + `raw` Feature |
| `app/Console/Commands/SeismoBackfillCommand.php` | `seismo:backfill` (dispatches job; HTTP in Sprint 2) |
| `config/seismo.php` | Env-backed Seismo settings |
| `tests/Feature/EarthquakeTest.php` | Factory, uniqueness, scopes, command |

---

## Schema

Migration order:

1. `CREATE EXTENSION IF NOT EXISTS postgis`
2. Create `earthquakes` columns
3. `ALTER TABLE ... ADD COLUMN location geography(Point, 4326) NOT NULL`
4. `CREATE INDEX earthquakes_location_gist ON earthquakes USING GIST (location)`

| Column | Type | Notes |
|--------|------|--------|
| `id` | bigint PK | Local |
| `usgs_id` | string, **unique** | Upsert key (Sprint 2) |
| `magnitude` | `decimal(4,2)`, nullable | Cast `decimal:2` |
| `mag_type` | string, nullable | |
| `place` | **text**, nullable | Longer USGS place strings |
| `depth_km` | float, nullable | |
| `tsunami` | boolean, default `false` | |
| `status` | string, nullable | |
| `url` | string, nullable | |
| `occurred_at` | timestamptz, required | USGS event time |
| `usgs_updated_at` | timestamptz, nullable | USGS revision time |
| `recorded_at` | timestamptz, required | First local insert |
| `raw` | json, required | Full GeoJSON feature |
| `created_at` / `updated_at` | timestamps | |
| `location` | `geography(Point, 4326) NOT NULL` | Added via raw SQL |

**Indexes:** unique on `usgs_id`; GiST `earthquakes_location_gist` on `location`.  
**Down:** drops the table only (extension left installed).

---

## Model design

### Virtual coordinates

`latitude` / `longitude` are **not** columns. They are:

- **Fillable** for writers (ingest sets them from GeoJSON).
- **Hydrated** on every query via a global scope:

```sql
ST_Y(location::geometry) AS latitude,
ST_X(location::geometry) AS longitude
```

- **Persisted** on `saving` through `ST_MakePoint(lon, lat)` → `geography`.

This keeps PostGIS as the source of truth while giving PHP/JS a simple lat/lon API.

### Query scopes

Laravel 12 `#[Scope]` methods on `Earthquake`:

| Scope | Purpose |
|-------|---------|
| `magnitudeBetween($min, $max)` | Inclusive mag band |
| `depthBetween($minKm, $maxKm)` | Depth band |
| `occurredBetween($from, $to)` | Time window |
| `withinRadius($lat, $lon, $km)` | `ST_DWithin` on `location` |
| `tsunami($enum)` | `all` / `yes` / `no` |
| `placeLike($q)` | Case-insensitive place search |
| `orderByOccurredDesc()` | Newest first |

Example:

```php
Earthquake::query()
    ->magnitudeBetween(2.5, null)
    ->occurredBetween(now()->subHours(24), now())
    ->withinRadius(34.05, -118.25, 500)
    ->orderByOccurredDesc()
    ->get();
```

---

## Factory

`EarthquakeFactory` builds a realistic row including a full USGS-style GeoJSON Feature in `raw`, with matching `usgs_id`, mag, place, and Point geometry. Used by Pest and by `seismo:broadcast-test` (Sprint 3).

---

## Artisan

```bash
php artisan seismo:backfill
# Sail: ./vendor/bin/sail artisan seismo:backfill
# Windows: .\sail.ps1 artisan seismo:backfill
```

Dispatches `BackfillSeismicData` onto the queue. Sprint 1 only needed the ops surface; Sprint 2 filled in HTTP + marker logic.

---

## Tests

`tests/Feature/EarthquakeTest.php` (requires PostGIS — Sail / CI service):

- Factory persists lat/lon/`raw`
- Duplicate `usgs_id` raises `QueryException`
- `withinRadius` keeps nearby events, drops distant ones
- `magnitudeBetween` + `occurredBetween` + ordering
- `seismo:backfill` dispatches `BackfillSeismicData`

---

## Verify locally

```powershell
.\sail.ps1 up -d
.\sail.ps1 artisan migrate:fresh
.\sail.ps1 artisan test --filter=EarthquakeTest
```

---

## Notes

- Scopes are the shared filter API; there is no separate `ApplyEarthquakeFilters` action yet.
- `place` is `text` (Architecture draft said `string`).
- Do not write `location` directly from app code — set `latitude` / `longitude` and let the model apply geography.
