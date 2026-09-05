_Created: 06-08-2026 · Last updated: 05-09-2026_

# Census: cabinet:probe soft vs critical (prod)

_Created: 06-08-2026 · Last updated: 07-08-2026 (H2335 soft sticky shipped)_

**Зачем:** решить, можно ли **форвардить** алерты преподавателям.  
**Ответ по цифрам:** **нет** — пока 100% «болезни» в истории = soft, critical = 0.

**Источник:** prod `cabinet_probe_runs` (read-only), снимок 06-08-2026 ~22:45 MSK.  
**Окно:** фактически **~5 суток** (01–06.08.2026), не 14: таблица prune до `history_keep=500` строк (~96 прогонов/сутки × 5).  
**Не путать:** строка в БД = каждый `cabinet:probe` (*/15). Сообщения в Telegram **реже** (cooldown 60 мин + fingerprint soft).

Grok 4.5 (`grok-4.5`). Связь: [H2325](https://github.com/gasyoun/Uprava/blob/main/handoffs/H2325-Grok_Systema-Sanscriticum_teacher-site-down-cheatsheet_06.08.26.md) · [SERVER_SOFT_ALERT_PLAYBOOK](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/SERVER_SOFT_ALERT_PLAYBOOK.md) · [TEACHER_SITE_DOWN_CHEATSHEET_RU](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/TEACHER_SITE_DOWN_CHEATSHEET_RU.md).

---

## 1. Итог (GO/NO-GO форвард преподам)

| Вопрос | Значение |
|---|---|
| Critical runs (кабинет «не работает») | **0** |
| Soft-only runs (сайт часто 200) | **107** / 500 (21%) |
| Soft-эпизоды (разрыв ≥1 ч) | **4** |
| Dominant cause | `guards/auto-deploy` fuse: `[blocked-preflight]` / `[rolled-back]` / dirty |
| Форвард teaching staff сейчас | **NO-GO** |
| Когда пересмотреть | critical «эпизоды» ≪ soft **и** soft-шум ops ≤ ~1 ложное/сутки несколько недель |

---

## 2. Сводка

| Метрика | Значение |
|---|---|
| Rows kept | 500 |
| oldest → newest | 2026-08-01 19:15 → 2026-08-06 22:30 UTC-ish DB |
| healthy | 393 |
| unhealthy | 107 |
| critical | **0** |
| soft-only (healthy=0, critical=0) | **107** |
| critical episodes (gap ≥2h) | 0 |
| soft episodes (gap ≥1h) | 4 |

**Вывод:** «5 раз в день» у ops — это **soft** (fuse/auto-deploy/dirty), не падение кабинета.  
Преподам такой поток = белый шум. Critical-форвард за этот срез **не с чем** включать (0 событий).

---

## 3. По дням

| Дата | runs | ok | soft-only | critical |
|---|---:|---:|---:|---:|
| 2026-08-01 | 22 | 18 | 4 | 0 |
| 2026-08-02 | 96 | 95 | 1 | 0 |
| 2026-08-03 | 96 | 96 | 0 | 0 |
| 2026-08-04 | 96 | 96 | 0 | 0 |
| 2026-08-05 | 96 | 38 | **58** | 0 |
| 2026-08-06 | 94 | 50 | **44** | 0 |

05–06.08: fuse висит → probe каждые 15 мин пишет soft (БД). TG при том же fingerprint режется cooldown; **смена fingerprint** (новый тег/текст fuse) снова шлёт soft.

---

## 4. Топ причин unhealthy (summary prefix)

| n | critical | soft | Summary (усечённо) |
|---:|---:|---:|---|
| 91 | 0 | 91 | `[soft] guards/auto-deploy` … `[blocked-preflight]` auto-retry … (05.08) |
| 3 | 0 | 3 | blocked-preflight 01.08 |
| 3×3 | 0 | 3 | `[rolled-back]` 05.08 (несколько штампов) |
| 1 | 0 | 1 | tracked dirty `config/marathon_landing_copy.php` |
| 1 | 0 | 1 | managed-file drift `systema-auto-deploy-run.sh` |
| 1 | 0 | 1 | rolled-back 06.08 |

**Класс:** ops / deploy guards. **Не** «ученики не сдают ДЗ».

---

## 5. Политика (зафиксировано 06-08-2026)

1. **Преподам:** без авто-форварда. Шпаргалка H2325 + `@rusamskrtam` по факту симптома.  
2. **Ops:** soft оставить; не смешивать с «сайт упал».  
3. **Перед любым teacher-forward:**  
   - только critical «Личный кабинет не работает» **или** Better Stack HTTP red;  
   - 1 сообщение на инцидент + 1 «встал»;  
   - отдельный chat id;  
   - повторный census: critical > 0 за 14d **и** soft-эпизоды ops терпимы.  
4. **Чинить шум (отдельно):** застрявший `auto_deploy.disabled` / dirty / fingerprint churn — playbook soft + remediator; не «добавить аудиторию».  
   **H2335 (07-08-2026):** soft TG sticky + normalized fuse fingerprint — same class ≤1 TG/сутки (reminder 24h), not every hour/timestamp rewrite.

---

## 6. Как повторить

На prod (read-only):

```bash
# scp docs/scripts or one-off PHP that bootstraps Laravel and aggregates cabinet_probe_runs
# Filters: ran_at last 14d; critical vs healthy=0&critical=0; group by DATE(ran_at)
```

Filament: **Здоровье кабинета** (история `cabinet_probe_runs`).

Ограничение: `CABINET_PROBE_HISTORY_KEEP` / config `history_keep` (500) — длиннее ~5 суток подряд не хранится.

_Dr. Mārcis Gasūns_
