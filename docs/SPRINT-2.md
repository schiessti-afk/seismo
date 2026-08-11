# Sprint 2 — Ingestion & backfill (implementation)

**Status:** complete  
**Plan:** [SPRINT.md](./SPRINT.md) · **Architecture:** [ARCHITECTURE.md](./ARCHITECTURE.md) §4  
**Depends on:** [Sprint 1](./SPRINT-1.md)  
**Unlocks:** [Sprint 3](./SPRINT-3.md) broadcasts on the live path

---

## Goal

Fill PostGIS from USGS GeoJSON: one-shot month backfill with auto-retry, then live hour polls every minute. Upserts are idempotent; HTTP failures must not kill Horizon.

---

## Exit criteria (met)

| Criterion | Evidence |
|-----------|----------|
| Empty DB → backfill can complete | `BackfillSeismicData` + `BackfillState` marker |
| Partial / HTTP failure leaves marker unset | Pest: marker unset on 500 |
| Live job upserts without duplicates | Idempotency Pest (same fixture twice → one row set) |
| All magnitudes stored; `raw` on every row | Parser requires `raw`; no mag filter on ingest |
| Soft-fail on timeout/5xx | Client returns `null`; live job catches + logs |

---

## What shipped

| Path | Role |
|------|------|
| `app/Services/Usgs/UsgsFeedClient.php` | HTTP GET; soft-fail → `null` |
| `app/Services/Usgs/UsgsFeatureParser.php` | Feature → attributes + `raw`; skip invalid |
| `app/Services/Usgs/IngestUsgsFeed.php` | Fetch → parse → upsert loop |
| `app/Services/Usgs/IngestResult.php` | DTO: `successful`, `upserted`, `skipped`, `broadcasts` |
| `app/Jobs/BackfillSeismicData.php` | Month feed + lock + marker |
| `app/Jobs/FetchLatestSeismicData.php` | Hour feed; enables broadcast flag |
| `app/Support/BackfillState.php` | Cache marker + lock |
| `bootstrap/app.php` | Schedule: live + backfill retry |
| `config/seismo.php` | Feed URLs, `backfill_on_boot`, ingest settings |
| `tests/Feature/IngestUsgsFeedTest.php` | Idempotency, soft-fail, marker |
| `tests/Fixtures/usgs_all_hour_sample.geojson` | 2 valid Points + 1 invalid geometry |

---

## Pipeline

```text
UsgsFeedClient::fetch(url)
        │
        ▼ null → IngestResult(successful: false)
  GeoJSON features[]
        │
        ▼
UsgsFeatureParser::toAttributes  ──null──► skipped++ (log, continue)
        │
        ▼
IngestUsgsFeed::upsertEarthquake
  firstOrNew(usgs_id)
  recorded_at = now() only if new
  fill + save (lat/lon → geography)
  optional broadcast (Sprint 3 gate)
```

### Jobs

| Job | Feed | Broadcast | Tries / timeout |
|-----|------|-----------|-----------------|
| `BackfillSeismicData` | `USGS_BACKFILL_FEED_URL` (`all_month`) | **off** | 3 / 180s |
| `FetchLatestSeismicData` | `USGS_LIVE_FEED_URL` (`all_hour`) | **on** (gated in Sprint 3) | 1 |

### Schedule (`bootstrap/app.php`)

- Every minute: `FetchLatestSeismicData`
- Every minute: if `SEISMO_BACKFILL_ON_BOOT` and marker missing → dispatch `BackfillSeismicData`

Horizon runs the queue; Supervisor starts Horizon + `schedule:work` with Sail.

---

## Idempotency

Not SQL `ON CONFLICT` — Eloquent upsert:

1. Key: `usgs_id` via `firstOrNew(['usgs_id' => …])`.
2. On insert: set `recorded_at` to now.
3. On update: refresh fillable fields from the feed; **leave `recorded_at` unchanged**.
4. Invalid features are skipped; the batch continues.

Re-running the same feed never duplicates rows. Material field updates (mag, place, etc.) revise the row in place.

---

## Backfill state

`App\Support\BackfillState`:

| Key | Purpose |
|-----|---------|
| `seismo.backfill_completed` | Cache forever when backfill HTTP succeeds |
| `seismo.backfill` | Cache lock (600s) so only one backfill runs |

Rules:

- Marker set only when `IngestResult::successful` (feed downloaded and parsed as GeoJSON). Empty / all-skipped features still count as success once HTTP/JSON is OK.
- HTTP failure → marker stays unset → next schedule tick retries.
- If marker already set → job no-ops (no HTTP).
- `seismo:backfill` always dispatches the job; it does **not** clear the marker. To force a re-backfill, clear the cache key (or wipe marker via code/ops).

---

## Parsing rules

From `UsgsFeatureParser`:

- Require string `id`, `Point` geometry, numeric lon/lat in range, and `properties.time`.
- Map mag, magType, place, depth (from coordinates[2]), tsunami (`0`/`1` → bool), status, url, updated.
- USGS times are **milliseconds** → UTC `Carbon`.
- Persist the **entire Feature** as `raw`.
- Non-Point / bad coords → log warning, return `null` (skipped).

---

## Soft-fail

| Layer | Behavior |
|-------|----------|
| `UsgsFeedClient` | Non-2xx, invalid JSON, or throwable → log warning, return `null` (no throw) |
| `FetchLatestSeismicData` | try/catch around ingest; log; `$tries = 1` |
| Horizon | Stays healthy; next minute retries live poll |

---

## Configuration

| Env | Default | Purpose |
|-----|---------|---------|
| `USGS_BACKFILL_FEED_URL` | `…/all_month.geojson` | Backfill feed |
| `USGS_LIVE_FEED_URL` | `…/all_hour.geojson` | Live feed |
| `SEISMO_BACKFILL_ON_BOOT` | `true` | Auto-dispatch backfill when marker missing |
| `SEISMO_INGEST_SECONDS` | `60` | Documented interval; **schedule is hard-coded `everyMinute()`** |
| `QUEUE_CONNECTION` | `redis` | Horizon |
| `CACHE_STORE` | `redis` | Marker + lock |

See `.env.example` and `config/seismo.php`.

---

## Ops commands

```powershell
# Manual backfill (queued)
.\sail.ps1 artisan seismo:backfill

# Ensure workers are up (Sail Supervisor normally starts Horizon + schedule:work)
.\sail.ps1 up -d
```

After a successful first boot, expect ~30 days of USGS `all_month` rows (volume depends on feed). Live polls keep the last hour fresh.

---

## Tests

`tests/Feature/IngestUsgsFeedTest.php` + fixture `tests/Fixtures/usgs_all_hour_sample.geojson`:

- Two ingest runs → same row count; `recorded_at` stable; invalid geometry skipped
- Material update (mag/place) keeps `recorded_at`
- HTTP 500 → `successful=false`, zero rows
- Backfill job sets marker on success; leaves unset on failure
- Live job soft-fails without throwing
- Marker already set → backfill skips work

```powershell
.\sail.ps1 artisan test --filter=IngestUsgsFeedTest
```

---

## Notes

- Live job passes `broadcast: true` into `IngestUsgsFeed`; the **gate** (M≥2.5 + material change) is Sprint 3. Backfill always uses `broadcast: false`.
- Storage is ungated: M&lt;2.5 and null magnitudes are still upserted.
- Success for backfill means “feed fetched,” not “N rows written.”
