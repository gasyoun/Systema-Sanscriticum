# ARCHITECTURE — n8n server ops (context-ai.ru)

_Created: 30-07-2026 · Last updated: 30-07-2026_

Index: [PLAN_SYSTEMA_N8N_SERVER_OPS_2026H2.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/PLAN_SYSTEMA_N8N_SERVER_OPS_2026H2.md)

---

## 1. System context

```
                    Internet
                       │
              Caddy :443 (context-ai.ru)
                       │
              n8n container :5678 (loopback publish)
                       │
        ┌──────────────┼──────────────────┐
        │              │                  │
   webhooks      SSH to host         HTTP APIs
   (Zoom,        (yt-dlp, ffmpeg,    (YouTube, Rutube,
    Laravel,      bookbuilder,        Deepgram, TG, VK,
    bots)         /data/*)            Sheets, Drive)
        │              │
        ▼              ▼
  samskrte.ru     /data/{audio,clips,bookbuilder}
  Laravel         socks-nl → NL (YouTube egress)
```

**Boundary ruling (D9):** n8n orchestrates; heavy bytes stay on host under `/data` or temporary binaryData (to be pruned).

---

## 2. Components

| Component | Responsibility | Source of truth |
|---|---|---|
| n8n workflows | Automation graphs | Live DB; git = redacted export + templates |
| n8n credentials | OAuth/API keys | Live encrypted store; never git |
| Caddy | TLS + reverse proxy | `/opt/n8n/caddy/Caddyfile` |
| bookbuilder | libfl → OCR PDF | `/opt/bookbuilder` + workflow `СБОРКА КНИГ` |
| socks-nl + privoxy | YouTube egress | systemd units |
| Laravel Systema | Product triggers + storage of lessons/clips | `samskrte.ru` code + flags |
| Google Sheets | Ops ledgers (payments, titles, YT list, books) | Sheet IDs in nodes |

---

## 3. Canonical product contours

### 3.1 ZOOM → LMS (prod)

**Canonical workflow:** `ZOOM 1.4 (Final) + АДМИНКА ТЕСТ` (`1EIqqNzMl5NNIxST`).  
All other ZOOM* names are **legacy copies** (D7).

Interfaces:

- In: Zoom `recording.completed` webhook (+ secondary webhook)
- Out: YouTube, Rutube, Telegram, Google Drive/Sheets, `POST /api/lessons/from-zoom`, transcript endpoint
- Host: yt-dlp → `/data/audio` → Deepgram

### 3.2 Lecture clips (prod-ready UI, erroring)

Workflow `GGs0G2azzkLqLbJj` · Header Auth · async callback.  
Laravel: `DispatchLectureClipExtractionJob` · flags `CLIP_MARKETING_*`.  
Host: `/data/clips` + `/root/.clip-env`.

### 3.3 Payments sheet (money-adjacent)

Minimal: webhook `payments` → Google Sheets.  
Treat as **fence**; auth hardening human-gated.

### 3.4 Bookbuilder (product contour, security-first)

Sheet → Playwright order/fetch → `process_book.sh` → Drive.  
Secrets must leave node parameters (C01).

### 3.5 Systema content publish (partially missing on server)

Templates in git: schedule-sheet-sync, monthly-schedule-post, vk-calendar-post, social (planned).  
Live: monthly present but OFF; schedule/calendar/social missing.

---

## 4. Build-vs-reuse

| Piece | Verdict |
|---|---|
| New transcoder / ffmpeg wrapper | **Reuse** host tools + existing SSH patterns |
| New inventory exporter | **Thin script** once; do not rebuild catalog by hand |
| Second ZOOM pipeline | **Do not build** — archive copies |
| Laravel media processing | **Do not** — D9 / lecture D8 |
| Credential vault | Reuse n8n credentials + host env files; not a new vault product |

---

## 5. Data & retention

| Path | Contents | Policy |
|---|---|---|
| `/opt/n8n/storage/database.sqlite` | workflows, executions meta | Backup encrypted off-host |
| `.../storage/workflows/{id}` | binary media | Prune; prefer `/data` |
| `/data/audio` | yt-dlp extracts | Delete after transcript |
| `/data/clips` | clip sources | Delete after VK upload |
| `/data/bookbuilder/books` | page images + PDF | Operator retention |

---

## 6. Security architecture (target)

1. **No secrets in node strings** — credentials store or root-only env files.  
2. **Header Auth on all Laravel→n8n webhooks.**  
3. **Pinned n8n image.**  
4. **Least privilege SSH** (future: command allowlist).  
5. **Archive tags** reduce accidental activation of legacy graphs with stale secrets.

---

_Dr. Mārcis Gasūns_
