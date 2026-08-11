# UI — Seismo (Desktop)

**Canonical visual reference:** [mockups/seismo-desktop-mockup.png](./mockups/seismo-desktop-mockup.png)

The Live desktop UI must match that mockup’s structure, chrome, copy patterns, and hierarchy. History mode reuses the same shell; only the bottom bar and data binding change.

---

## 1. Visual tokens

| Token | Value / rule |
|-------|----------------|
| Ground | Near-black / charcoal (`#0B0D10`–`#12151A` family) |
| Accent red | ≈ `#E31A22` (magnitude pills, Live active, selected window, ripples) |
| Text | White / off-white primary; muted gray secondary (UTC, meta) |
| Type | Clean modern geometric sans |
| Basemap | Dark world tiles (CartoDB dark or equivalent) |
| Chrome | Minimal; thin separators; no purple, no heavy glow, no card stacks |

---

## 2. Desktop shell (Live) — match mockup

```text
+------------------------------------------------------------------+
| SEISMO     ( Live | History )      [Magnitude ≥ 2.5 ▾] [Window 24h ▾] |
+-------------------+----------------------------------------------+
| Activity ✦        |                                              |
| [6.4] Place…      |           Dark world map                     |
| date · Local      |           red epicenter circles + rings      |
| …                 |           Leaflet popup when selected        |
|                   |                              [+][-][⌖][layers]|
| Updates every 10s |                              scale 1000 km   |
| Showing 1–15 of N |                                              |
+-------------------+----------------------------------------------+
| ⏱ Live Window   [1h][3h][6h][12h][24h][48h][7d]     range (UTC)  |
+------------------------------------------------------------------+
```

### 2.1 Top bar

| Element | Spec |
|---------|------|
| Brand | Bold uppercase **SEISMO**, top-left, white |
| Mode switch | Center pill: **Live** (red filled when active) \| **History** (outline when inactive) |
| Magnitude chip | Top-right dropdown, funnel icon, default label **`Magnitude ≥ 2.5`** |
| Window chip | Top-right dropdown, calendar icon, label mirrors selection e.g. **`Window 24h`** |

Additional filters (depth, radius, tsunami, place, mag max) open from the Magnitude / filter control — not as a second header row. Default applied filter: hide magnitude **&lt; 2.5**.

### 2.2 Activity sidebar (left)

| Element | Spec |
|---------|------|
| Title | **Activity** + small red pulse / seismograph icon |
| Row | Red square **magnitude** badge (white digits) · place · date · **`{time} Local`** |
| Order | Newest first (Live) |
| Page size | **15** per page |
| Footer left | Radio/tower icon + copy **`Updates every 10s`** (UI status cadence; server USGS poll remains 60s) |
| Footer right | **`Showing {from}–{to} of {total}`** (e.g. `Showing 1–15 of 42`) |
| Row click | Pan map to event and open popup (list does not scroll when map marker is clicked) |

### 2.3 Map (main)

| Element | Spec |
|---------|------|
| Default view | World |
| Markers | Filled red circles; **radius + redness increase with M**; higher M get concentric ripple rings |
| Marker click | **Popup only** — does not highlight/scroll Activity |
| Popup | Dark panel: large red magnitude · place · `{date} {time} Local` · smaller gray **`UTC …`** · close **×** |
| Controls | Bottom-right stack: zoom **+**, zoom **−**, locate, layers |
| Scale | Bottom-right scale bar (e.g. `1000 km`) |

### 2.4 Bottom bar (Live)

| Element | Spec |
|---------|------|
| Label | Clock icon + **Live Window** |
| Presets | **`1h` `3h` `6h` `12h` `24h` `48h` `7d`** — selected preset red filled (default **24h**) |
| Range readout | Calendar icon + absolute window in **UTC**, e.g. `May 17, 2025 10:42 — May 18, 2025 10:42 UTC` |

Header **Window** chip and bottom **Live Window** presets stay in sync.

---

## 3. History mode (same shell)

| Region | Change from Live |
|--------|------------------|
| Mode pill | **History** red/active; Live outline |
| Bottom bar | Replace Live Window presets with **smooth-drag time scrubber** (no play/pause) |
| Map / list | Bound to scrubber slice (± `SEISMO_HISTORY_SLICE_HOURS`, default 6) + filters |
| WS | Do not append to map; optional chip “N new — Live” |
| Activity footer | Pagination still `Showing x–y of z`; status may read idle / scrubbing instead of “Updates every 10s” |

---

## 4. Copy & time rules

- Locale: English via i18n keys; no hardcoded Blade strings.
- Event times in list/popup: **browser local**, labeled `Local`.
- UTC only as secondary (popup gray line; bottom range readout in Live).

---

## 5. Implementation checklist (accept Live UI)

- [x] Matches [mockups/seismo-desktop-mockup.png](./mockups/seismo-desktop-mockup.png) layout regions
- [x] SEISMO + Live/History + two header chips
- [x] Activity rows, 15/page, footer status + “Showing…”
- [x] Mag-scaled red markers + rings; popup fields as above
- [x] Map control stack + scale
- [x] Live Window presets exactly: 1h 3h 6h 12h 24h 48h 7d
- [x] Default mag ≥ 2.5 and window 24h

---

## 6. Implementation checklist (accept History UI)

- [x] Mode pill toggles Live ↔ History without page reload
- [x] History bottom bar: smooth-drag scrubber (no play/pause)
- [x] Map + Activity bound to scrubber slice ± `SEISMO_HISTORY_SLICE_HOURS` + filters
- [x] WS does not append to History map; “N new — Live” chip when events arrive
- [x] History Activity pagination + Idle / Scrubbing status copy
- [x] Window chip disabled in History; shows slice half-width label
