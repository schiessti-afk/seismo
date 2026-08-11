# Sprint 7 — Hardening & v1.0 freeze (implementation)

**Status:** complete  
**Plan:** [SPRINT.md](./SPRINT.md) · **Architecture:** [ARCHITECTURE.md](./ARCHITECTURE.md) §10–11 · **UI spec:** [UI.md](./UI.md)  
**Depends on:** Sprints 0–6 (full public monitor)  
**Unlocks:** Sprint 8 alerts polish, Sprint 9 exports

---

## Goal

Productionize the v1.0 slice: full CI (Pint + Pest + Larastan), expanded Pest coverage, i18n/time polish, Horizon gate verification, failure-mode documentation, and README/IDEA v1.0 declaration — no new product features.

---

## Exit criteria (met)

| Criterion | Evidence |
|-----------|----------|
| CI green on v1.0 feature set | `.github/workflows/ci.yml` — Pint, Larastan level 5, Pest/PostGIS |
| Fresh Sail clone path works from README | Quick start unchanged; Windows `sail.ps1` path verified |
| No known P0 gaps vs sprints 0–6 | UI.md Live + History checklists complete; scope/filter/broadcast tests expanded |
| Docs reflect shipped v1.0 | README status, IDEA roadmap, SPRINT.md checkboxes |

---

## What shipped

| Path | Role |
|------|------|
| `.github/workflows/ci.yml` | Added Larastan step to quality job |
| `app/Models/Earthquake.php` | `@method` PHPDoc for query scopes (Larastan) |
| `app/Actions/ApplyEarthquakeFilters.php` | Typed `Earthquake::query()` base (Larastan) |
| `app/Jobs/BackfillSeismicData.php` | phpstan ignore for double-check locking |
| `app/Services/Usgs/IngestUsgsFeed.php` | Relaxed features typing for runtime validation |
| `tests/Feature/EarthquakeTest.php` | `depthBetween`, `tsunami`, `orderByMagnitudeDesc` scopes |
| `tests/Feature/HomePageTest.php` | Depth + tsunami Livewire filter tests |
| `tests/Feature/HorizonAccessTest.php` | `/horizon` local-only gate |
| `lang/en/seismo.php` | `map_locate`, `map_layers` keys |
| `resources/js/map.js` | Map control labels from i18n payload |
| `resources/views/livewire/live-monitor.blade.php` | Pass locate/layers labels to map |
| `phpunit.xml` | Feature suite only (removed empty Unit scaffold) |
| `README.md` | v1.0 status, failure modes, SPRINT-7 link |
| `docs/IDEA.md` | Roadmap v1.0 → Shipped |

Removed scaffold-only `tests/Unit/ExampleTest.php` and `tests/Feature/ExampleTest.php`.

---

## CI

```yaml
quality:  pint --test + phpstan analyse --memory-limit=1G
tests:    pest (PostGIS service postgis/postgis:16-3.4)
```

Local equivalents:

```powershell
.\sail.ps1 pint
.\sail.ps1 composer analyse
.\sail.ps1 pest
```

**Sprint 7 result:** 43 Pest tests, 124 assertions — all green.

---

## Pest coverage added

| Area | Tests |
|------|-------|
| Scopes | depth, tsunami yes/no/all, magnitude sort |
| Livewire filters | max depth, tsunami yes |
| Horizon gate | 403 outside `local`; 200 in `local` |
| Prior sprints | ingest, broadcast, Live/History unchanged |

---

## i18n / times audit

| Surface | Rule | Status |
|---------|------|--------|
| Activity rows | Browser local time + `Local` suffix | OK (Alpine `toLocaleTimeString`) |
| Map popup | Local primary; gray `UTC …` secondary | OK (`map.js` + lang keys) |
| Bottom range | UTC readout (Live + History) | OK (`window_range` / `history_range`) |
| Map controls | Locate / Layers via `__('seismo.*')` | Added Sprint 7 |
| Blade chrome | All visible copy via `lang/en/seismo.php` | OK |

---

## Ops & failure modes

Documented in [README.md](../README.md) and [ARCHITECTURE.md](./ARCHITECTURE.md) §10:

| Failure | Behavior | Verified |
|---------|----------|----------|
| USGS timeout / 5xx | Log; soft-fail job; next schedule tick | Pest (`IngestUsgsFeedTest`) |
| Malformed GeoJSON feature | Skip row; continue batch | Parser + ingest tests |
| Redis down | Horizon unhealthy; HTTP may still read DB | Manual: stop `redis` container → `/` loads; Horizon stalls |
| Reverb down | Map shows DB state; live ripples pause | Manual: stop Reverb process → map + Activity from DB OK |
| Backfill partial failure | Marker unset; auto-retry; idempotent upserts | Pest + `BackfillState` |

Horizon dashboard: **`/horizon` local environment only** (`HorizonServiceProvider` + `HorizonAccessTest`).

---

## Manual smoke

1. `.\sail.ps1 up -d` — open `http://localhost`; Live + History both work.
2. `.\sail.ps1 pest` — 43 tests green.
3. `.\sail.ps1 composer analyse` — Larastan level 5 clean.
4. Outside `APP_ENV=local`, `GET /horizon` → 403.
5. Stop Reverb (Supervisor) — page loads; no ripples until Reverb restarts.
6. `docker compose stop redis` — page loads from PostGIS; restart Redis for queues.

---

## Explicitly deferred (Sprint 8+)

| Item | Sprint |
|------|--------|
| M5.0+ / tsunami visual polish | 8 |
| Optional sound toggle | 8 |
| CSV / GeoJSON exports | 9 |
| Coolify/VPS production runbook | post-v1 |
