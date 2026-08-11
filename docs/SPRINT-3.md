# Sprint 3 — Realtime broadcasting (implementation)

**Status:** complete  
**Plan:** [SPRINT.md](./SPRINT.md) · **Architecture:** [ARCHITECTURE.md](./ARCHITECTURE.md) §5  
**Depends on:** [Sprint 2](./SPRINT-2.md)  
**Unlocks:** Sprint 5 map ripples + Activity prepend (client hook already fires a `CustomEvent`)

---

## Goal

Push live updates to browsers for qualifying events only: insert or material field change **and** magnitude ≥ `SEISMO_BROADCAST_MIN_MAGNITUDE` (default 2.5). Backfill never storms the channel.

---

## Exit criteria (met)

| Criterion | Evidence |
|-----------|----------|
| Reverb reachable from browser | Sail Supervisor `reverb:start`; host port `${REVERB_SERVER_PORT}` (default **8081** → container **8080**) |
| Only material M≥2.5 live changes broadcast | `EarthquakeBroadcastGate` + Pest suite |
| Backfill silent | Job uses `broadcast: false`; Pest asserts no dispatch |
| Echo + Reverb wired | `resources/js/bootstrap.js` + `app.js` |
| Manual two-browser smoke | `seismo:broadcast-test` (see README) |

---

## What shipped

| Path | Role |
|------|------|
| `app/Events/EarthquakeDetected.php` | `ShouldBroadcast` on public `earthquakes` |
| `app/Services/Usgs/EarthquakeBroadcastGate.php` | Mag + insert/material-change gate |
| `app/Services/Usgs/IngestUsgsFeed.php` | Dispatches event when gate passes |
| `app/Jobs/FetchLatestSeismicData.php` | `broadcast: true` |
| `app/Jobs/BackfillSeismicData.php` | Broadcast off (default) |
| `app/Console/Commands/SeismoBroadcastTestCommand.php` | `seismo:broadcast-test` |
| `resources/js/bootstrap.js` | Echo + Reverb (pusher-js) |
| `resources/js/app.js` | Subscribe + listen `.EarthquakeDetected` |
| `config/broadcasting.php` / `config/reverb.php` | Reverb driver |
| `config/seismo.php` | `broadcast_min_magnitude` |
| `tests/Feature/EarthquakeBroadcastTest.php` | Gate + silence + live job |
| `.env.example` | Reverb + magnitude gate keys |

---

## Broadcast decision

Called from `IngestUsgsFeed::upsertEarthquake` **before** save, using existing row + incoming attributes:

```text
broadcast flag (live job only)
  AND magnitude >= SEISMO_BROADCAST_MIN_MAGNITUDE   (null mag → no)
  AND (isNew OR material change)
        → EarthquakeDetected::dispatch(fresh model with lat/lon)
```

### Material change fields

Compared with float ε `0.0001`:

- `magnitude`
- `latitude` / `longitude`
- `depth_km`
- `place`
- `tsunami`

Changes only to `occurred_at`, `usgs_updated_at`, `status`, `url`, or `raw` do **not** trigger a broadcast by themselves.

### Storage vs broadcast

| Case | Stored | Broadcast |
|------|--------|-----------|
| New M≥2.5 | yes | yes |
| New M&lt;2.5 | yes | no |
| Identical re-upsert M≥2.5 | yes (touch) | no |
| Material revision M≥2.5 | yes | yes |
| Null magnitude | yes | no |
| Any backfill upsert | yes | **never** |

---

## Event payload

Public channel: `earthquakes`  
Event name (Echo): `.EarthquakeDetected` (leading `.` = no namespace prefix)

```json
{
  "usgs_id": "us7000example",
  "magnitude": 4.5,
  "lat": 34.05,
  "lon": -118.25,
  "depth_km": 10.2,
  "place": "10 km W of Los Angeles, CA",
  "occurred_at": "2026-08-11T10:00:00+00:00",
  "tsunami": false
}
```

Field names match Architecture §5 (`usgs_id`, `magnitude`, …) — not the shorter SPRINT.md shorthand (`id`, `mag`).

`EarthquakeDetected` implements `ShouldBroadcast` (queued via the default broadcast queue), not `ShouldBroadcastNow`.

---

## Client wiring

1. Vite builds Echo with `VITE_REVERB_*` from `.env`.
2. `bootstrap.js` configures `window.Echo` (Reverb / pusher protocol).
3. `app.js` joins `earthquakes` and listens for `.EarthquakeDetected`:
   - `console.log('[Seismo] EarthquakeDetected', …)`
   - dispatches `window` `CustomEvent` `seismo:earthquake-detected` with the payload

Sprint 5 consumes that event for ripples + Activity. Sprint 3 only proves the pipe.

---

## Ports

| Where | Port |
|-------|------|
| Reverb inside app container | `8080` (`reverb:start --host=0.0.0.0 --port=8080`) |
| Host forward | `${REVERB_SERVER_PORT}` default **8081** → `8080` |
| Browser / Echo | `REVERB_HOST` + `REVERB_PORT` (typically `localhost` + `8081` locally) |

---

## Configuration

| Env | Purpose |
|-----|---------|
| `BROADCAST_CONNECTION=reverb` | Enable broadcasting |
| `REVERB_APP_ID` / `KEY` / `SECRET` | App credentials |
| `REVERB_HOST` / `PORT` / `SCHEME` | Laravel + client connection |
| `REVERB_SERVER_HOST` / `REVERB_SERVER_PORT` | Server bind / Compose publish |
| `VITE_REVERB_APP_KEY` / `HOST` / `PORT` / `SCHEME` | Echo at build time |
| `SEISMO_BROADCAST_MIN_MAGNITUDE` | Default `2.5` |

CI / Pest use `BROADCAST_CONNECTION=null` and `QUEUE_CONNECTION=sync` with `Event::fake` for assertions.

---

## Manual smoke

From README (assets must be built so Echo loads):

```powershell
.\sail.ps1 npm run build   # or npm run build with Vite
# Open http://localhost in two tabs; DevTools console open
.\sail.ps1 artisan seismo:broadcast-test
```

Both tabs should log `[Seismo] EarthquakeDetected` with the same payload. The command factory-creates a M4.5 row and dispatches the event.

---

## Tests

`tests/Feature/EarthquakeBroadcastTest.php`:

- New M≥2.5 → event dispatched; payload shape
- New M&lt;2.5 → no event
- Identical re-upsert → no event
- Material revision M≥2.5 → event
- Null magnitude → upsert, no event
- Backfill job → never broadcasts
- `FetchLatestSeismicData` → enables broadcast path

```powershell
.\sail.ps1 artisan test --filter=EarthquakeBroadcastTest
```

---

## Notes

- Public channel needs no entry in `routes/channels.php` (that file only has the default private User channel).
- Horizon UI remains local-gated; the map channel is intentionally public.
- UI ripples / Activity prepend are **not** Sprint 3 — only the transport and gate.
