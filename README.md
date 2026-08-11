# Seismo

Self-hosted real-time seismic monitoring: USGS GeoJSON → PostgreSQL/PostGIS → Laravel Horizon → Reverb WebSockets → public Livewire + Leaflet map.

> **Status:** Greenfield. Spec under [`docs/`](./docs/). App code not scaffolded yet.

---

## Docs

| Doc | Purpose |
|-----|---------|
| [docs/IDEA.md](./docs/IDEA.md) | Product intent, locked decisions, roadmap |
| [docs/ARCHITECTURE.md](./docs/ARCHITECTURE.md) | Containers, ingest/backfill, schema, CI |
| [docs/UI.md](./docs/UI.md) | **Desktop UI spec** (must match mockup) |
| [docs/SPRINT.md](./docs/SPRINT.md) | Sprint plan (0–9) with exit criteria |
| [docs/mockups/seismo-desktop-mockup.png](./docs/mockups/seismo-desktop-mockup.png) | Canonical Live desktop mockup |
| [docs/mockups/seismo-architecture.png](./docs/mockups/seismo-architecture.png) | Architecture flowchart graphic |

---

## What it does

1. **Backfill** on first boot (auto-retries): USGS `all_month` (~30 days) + `raw` jsonb.
2. **Live ingest** every 60s: USGS `all_hour` via Horizon (store all magnitudes).
3. **Public Live desktop** as in the mockup: SEISMO header, Live/History pill, Activity sidebar (15/page), dark map, Live Window presets `1h…7d` (default 24h).
4. Ripples / Activity pushes for **M≥2.5**; markers scale red/size with magnitude; popup = Local + small UTC.
5. **History:** same shell, bottom time scrubber (drag only).

---

## Tech stack

| Layer | Technology | Role |
|-------|------------|------|
| Runtime | PHP 8.3+, Docker / Laravel Sail | Local multi-container app (web, Horizon, scheduler, Reverb) |
| Backend | Laravel 11 | HTTP, scheduling, jobs, broadcasting, i18n (`en`) |
| Queues | Redis + Laravel Horizon | Ingest / backfill workers from day one |
| Database | PostgreSQL 16 + PostGIS | Events + `geography(Point, 4326)` spatial queries |
| Realtime | Laravel Reverb + Redis | Public WebSocket channel `earthquakes` |
| Frontend | Livewire 3, Alpine.js, Leaflet | Public Live/History UI (dark + red); no SPA framework |
| Map tiles | CartoDB Dark (or equivalent) | Basemap under epicenter markers |
| Quality | Pest, Pint, Larastan 5 | GitHub Actions CI in v1 |
| Data source | USGS GeoJSON summary feeds | `all_month` backfill · `all_hour` live poll (no API key) |

Details: [docs/ARCHITECTURE.md](./docs/ARCHITECTURE.md).

---

## Architecture (summary)

USGS feeds are pulled on a schedule into Horizon jobs, upserted into PostGIS (idempotent by USGS id), and — for new or materially changed **M≥2.5** events — broadcast over Reverb to the public browser UI.

![Seismo architecture: USGS → Sail (Scheduler, Horizon) → PostGIS / Redis → Reverb → Livewire + Leaflet browser](docs/mockups/seismo-architecture.png)

```text
  USGS GeoJSON                 Docker / Laravel Sail
  (all_month / all_hour)              │
           │                    ┌─────┴──────┐
           └──────────────────► │ Scheduler  │
                                └─────┬──────┘
                                      ▼
                                ┌────────────┐     ┌─────────────────────┐
                                │  Horizon   │────►│ PostgreSQL + PostGIS│
                                │  workers   │     └──────────┬──────────┘
                                └─────┬──────┘                │
                                      │                       │ queries
                                      ▼                       ▼
                                ┌────────────┐     ┌─────────────────────┐
                                │   Redis    │     │ Public browser UI   │
                                └─────┬──────┘     │ Livewire + Leaflet  │
                                      │            └──────────▲──────────┘
                                      ▼                       │
                                ┌────────────┐                │
                                │   Reverb   │────────────────┘
                                │  (WS :8080)│   M≥2.5 events
                                └────────────┘
```

| Path | Behavior |
|------|----------|
| First boot | `all_month` backfill (~30 days), auto-retry until complete; no WS storm |
| Steady state | `all_hour` every 60s; store all magnitudes |
| Live UI | Query last window (default 24h); ripples + Activity for M≥2.5 |
| History UI | Same shell; bottom time scrubber (drag only) |

Full write-up: [docs/ARCHITECTURE.md](./docs/ARCHITECTURE.md).

---

## Quick start (target — not wired yet)

Prerequisites: Docker Desktop.

```bash
cp .env.example .env
./vendor/bin/sail up -d
./vendor/bin/sail artisan migrate
# Supervisor (planned): horizon + schedule:work + reverb:start
```

Open `http://localhost`. UI acceptance: [docs/UI.md](./docs/UI.md) checklist vs mockup.

---

## Locked decisions (summary)

| Topic | Choice |
|-------|--------|
| Name / license | Seismo · MIT © Micha Schiess |
| UI | Match [docs/UI.md](./docs/UI.md) + mockup |
| Live presets | 1h 3h 6h 12h 24h 48h 7d (default 24h) |
| Activity | 15/page; `Updates every 10s`; `Showing x–y of z` |
| Default filter | Magnitude ≥ 2.5 |
| Marker / list | Popup-only on marker; row click pans + popup |
| Accent | ≈ `#E31A22` on dark charcoal |
| Data / queues | USGS only, `raw` jsonb, Horizon, Sail local, CI in v1 |

---

## License

MIT © Micha Schiess
