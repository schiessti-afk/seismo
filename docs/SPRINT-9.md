# Sprint 9 — Exports (implementation)

**Status:** complete  
**Plan:** [SPRINT.md](./SPRINT.md) · **UI spec:** [UI.md](./UI.md) §2.1 (filter panel)  
**Depends on:** Sprints 0–8 (v1.2 public monitor)  
**Unlocks:** v2.0 multi-provider (out of current sprint plan)

---

## Goal

Public CSV and GeoJSON downloads of stored earthquakes matching the current UI filter set, with hard row caps and IP rate limits — no auth, no unbounded dumps, Parquet deferred.

---

## Locked decisions

| Topic | Choice |
|-------|--------|
| Transport | Sync HTTP downloads (streamed CSV / JSON GeoJSON) |
| Formats | CSV + GeoJSON only; Parquet deferred |
| Filter semantics | Same as UI: mag/depth/radius/tsunami/place/sort + `occurred_from` / `occurred_to` (Live window or History slice) |
| Hard cap | `SEISMO_EXPORT_MAX_ROWS` (default 5000); header `X-Seismo-Export-Truncated: 1` when capped |
| Rate limit | `SEISMO_EXPORT_RATE_PER_MINUTE` (default 10) per IP via `throttle:exports` |
| CSV columns | `usgs_id`, `magnitude`, `mag_type`, `place`, `latitude`, `longitude`, `depth_km`, `tsunami`, `occurred_at`, `recorded_at` |
| GeoJSON | `FeatureCollection`; prefer stored `raw` Feature; synthesize Point Feature when invalid |
| UI | Export CSV / Export GeoJSON links in Magnitude filter panel |

---

## Exit criteria (met)

| Criterion | Evidence |
|-----------|----------|
| Bounded CSV + GeoJSON of recorded events | `/export/csv`, `/export/geojson` + filter-panel links |
| Unbounded dump impossible | Required time window + row cap + rate limit |
| Respects current filters | `LiveMonitor::exportQueryParams()` passes filter + window bounds |
| Tests | `tests/Feature/EarthquakeExportTest.php` (7 tests) |

---

## What shipped

| Path | Role |
|------|------|
| `config/seismo.php` | `export_max_rows`, `export_rate_per_minute` |
| `.env.example` | `SEISMO_EXPORT_MAX_ROWS`, `SEISMO_EXPORT_RATE_PER_MINUTE` |
| `app/Providers/AppServiceProvider.php` | `RateLimiter::for('exports', …)` |
| `app/Http/Requests/EarthquakeExportRequest.php` | Query validation + filter DTO |
| `app/Actions/ExportEarthquakes.php` | Filtered query, cap, CSV/GeoJSON shaping |
| `app/Http/Controllers/EarthquakeExportController.php` | CSV stream + GeoJSON download responses |
| `routes/web.php` | Throttled `/export/csv`, `/export/geojson` |
| `app/Livewire/LiveMonitor.php` | `exportQueryParams()` |
| `resources/views/livewire/live-monitor.blade.php` | Filter-panel export links + cap note |
| `resources/css/app.css` | `.seismo-filter-export*` styles |
| `lang/en/seismo.php` | `export_csv`, `export_geojson`, `export_truncated_hint` |
| `tests/Feature/EarthquakeExportTest.php` | Shape, cap, validation, 429, UI links |
| `docs/UI.md` | Filter-panel export affordance note |
| `docs/IDEA.md` | Roadmap v1.3 → Shipped |
| `docs/ARCHITECTURE.md` | Public export routes + abuse resistance |
| `docs/SPRINT.md` | Sprint 9 checkboxes + as-built link |
| `README.md` | v1.3 status + Exports section |

---

## Export behavior summary

```text
Filter panel links (or direct GET)
  ├─ Query params mirror LiveMonitor filters + occurred_from/to
  ├─ ApplyEarthquakeFilters → limit(export_max_rows)
  ├─ CSV: streamed flat columns (no raw jsonb)
  ├─ GeoJSON: FeatureCollection from raw or synthesized Feature
  └─ throttle:exports (IP/minute) → 429 when exceeded
```

---

## Manual smoke

1. Open filter panel — **Export CSV** and **Export GeoJSON** links visible with cap note.
2. Apply place/magnitude filters — download CSV; rows match filtered Activity set.
3. Seed >5000 matching rows (or lower `SEISMO_EXPORT_MAX_ROWS`) — response capped; `X-Seismo-Export-Truncated: 1`.
4. Hit export route repeatedly — HTTP 429 after rate budget.
5. History mode — export links use scrubber slice bounds in query params.

---

## Explicitly deferred

| Item | Notes |
|------|-------|
| Parquet | Out of scope for v1.3 |
| Auth / signed URLs | Public exports by design |
| Async queued exports | Sync capped downloads sufficient |
| Multi-provider data | v2.0 |
