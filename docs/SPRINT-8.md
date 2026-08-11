# Sprint 8 — Alerts polish (implementation)

**Status:** complete  
**Plan:** [SPRINT.md](./SPRINT.md) · **UI spec:** [UI.md](./UI.md) §4, §6.1  
**Depends on:** Sprints 0–7 (v1.0 public monitor)  
**Unlocks:** Sprint 9 exports

---

## Goal

Stronger operator attention for significant events: M≥5.0 Activity/map emphasis, tsunami badge + window-scoped banner (USGS feed flag), optional Live sound (default off), i18n, and Pest coverage — without cluttering the mockup shell.

---

## Locked decisions

| Topic | Choice |
|-------|--------|
| Tsunami banner | Show when ≥1 tsunami-flagged event is in the **current filtered** Live window / History slice |
| Sound | Default off; Live only; M≥5.0 **or** tsunami on matched WebSocket events |
| History | Same visual treatment + banner; no sound |
| Alert threshold | `SEISMO_ALERT_MIN_MAGNITUDE` (default 5.0) |

---

## Exit criteria (met)

| Criterion | Evidence |
|-----------|----------|
| M≥5.0 + tsunami obvious without clutter | Strong Activity badge, slim banner, row badge, map popup line |
| Sound optional, muted by default | `localStorage` default false; header chip Live-only |
| Tests cover badge/banner rules | `tests/Feature/AlertChromeTest.php` (9 tests) |

---

## What shipped

| Path | Role |
|------|------|
| `config/seismo.php` | `alert_min_magnitude` (default 5.0) |
| `.env.example` | `SEISMO_ALERT_MIN_MAGNITUDE=5.0` |
| `app/Livewire/LiveMonitor.php` | `hasTsunamiInWindow()`, `alertMinMagnitude()`, `tsunami` in map/broadcast payloads |
| `resources/views/livewire/live-monitor.blade.php` | M5+ badge class, tsunami row badge, banner, sound toggle |
| `resources/css/app.css` | Strong mag badge, tsunami badge/banner, popup tsunami, sound chip |
| `resources/js/map.js` | Stronger M≥5/6 rings; tsunami in normalize/popup |
| `resources/js/app.js` | Web Audio tone on matched Live WS (M≥5 or tsunami) when sound enabled |
| `lang/en/seismo.php` | `tsunami_banner`, `tsunami_badge`, `popup_tsunami`, `sound_on/off`, `sound_toggle_aria` |
| `tests/Feature/AlertChromeTest.php` | Badge/banner/sound/map payload rules |
| `docs/UI.md` | Alert chrome spec + checklist §4, §6.1 |
| `docs/IDEA.md` | Roadmap v1.2 → Shipped |
| `docs/SPRINT.md` | Sprint 8 checkboxes + as-built link |
| `README.md` | v1.2 status + SPRINT-8 link |

---

## Alert behavior summary

```text
Filtered query (Live window / History slice)
  ├─ Any row M≥5.0     → .seismo-mag-badge--strong on Activity
  ├─ Any row tsunami   → .seismo-tsunami-badge on row; banner if ≥1 in set
  └─ Map               → stronger M≥5 rings; tsunami line in popup

Live WebSocket (after filter match)
  ├─ Ripple + Activity prepend (unchanged)
  └─ Sound if enabled AND (M≥5.0 OR tsunami)
```

---

## Manual smoke

1. Seed or wait for M≥5.0 event in 24h window — Activity badge uses strong styling.
2. Create/view tsunami-flagged event in window — banner appears; row shows **Tsunami** badge.
3. Change window/filter so no tsunami events remain — banner hides.
4. Live mode: toggle **Sound off** → **Sound on**; trigger `seismo:broadcast-test` with M≥5 or tsunami payload — short tone plays.
5. History mode: banner/badges still work; sound toggle hidden.

---

## Explicitly deferred (Sprint 9+)

| Item | Sprint |
|------|--------|
| CSV / GeoJSON exports | 9 |
| OS Notification API | out of scope |
| Multi-locale beyond `en` keys | post-v1 |
