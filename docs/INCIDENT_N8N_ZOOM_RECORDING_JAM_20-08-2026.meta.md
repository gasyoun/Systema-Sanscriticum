# INCIDENT_N8N_ZOOM_RECORDING_JAM_20-08-2026 — metadoc

_Created: 26-08-2026 · Last updated: 26-08-2026_

**Purpose:** хронологический разбор двух волн отказа доставки записей (18–20.08 OpenRouter-класс; 23–26.08 early-death/binary-loss/token-TTL/passcode/trash классы) с финальным состоянием защиты трубы. Связка: операционные шаги живут в [RUNBOOK_N8N_RECORDING_STALL.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/RUNBOOK_N8N_RECORDING_STALL.md), этот документ — «почему так», с пруфами по execution_data.

**Provenance:** 20-08 (Grok 4.6, SOS-диагностика), 24-08 резолюция + retry lane (PR #2050) + care-получатели (PR #2054), 26-08 H3557: дедуп в БД + t.me-ссылки + scoped чистка + Meta/Bearer + теневая копия Drive + маркер старения (+ issue [#2132](https://github.com/gasyoun/Systema-Sanscriticum/issues/2132) на восстановление из корзины аккаунта Анатолия).

**Ranked backlog:** см. backlog RUNBOOK-метадока (общий контур).

**History:** 26-08 добавлен раздел «Alert doubling resolved» и фиксация трёхслойной причины потери записи гр.125 (global-rm → token TTL → passcode+retention).

_Dr. Mārcis Gasūns_
