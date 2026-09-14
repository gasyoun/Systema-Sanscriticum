# INCIDENT — n8n ZOOM 1.4: ENOSPC на .91 → ложный вердикт H3952_WEBHOOK_MISSING → реплей вскрыл Merge dead-end на не найденной обложке

_Created: 10-09-2026 · Last updated: 10-09-2026_

**Workflow:** `ZOOM 1.4 (Final) + АДМИНКА ТЕСТ` ([1EIqqNzMl5NNIxST](https://context-ai.ru/workflow/1EIqqNzMl5NNIxST)), host `root@193.232.229.91`.
**Урок:** «Хинди с Костиной | начальная №5», meeting Zoom **86359382106**, Account_2 (Zoom ОРС / Гасунс), старт 10-09-2026 09:19 UTC.
**Хендофф-фиксы:** [H4513](https://github.com/gasyoun/Uprava/blob/main/handoffs/H4513-OxAlpha_Systema-Sanscriticum_n8n-zoom-cover-bypass-and-infra-verdict_10.09.26.md).

## TL;DR

1. Диск .91 (корень, 49G) был **100%** — exec **2749** упал: «DOWNLOAD свежая (Гасунс)» и «HEAD вебхук-токен» умерли с `ENOSPC: no space left on device`.
2. Вердикт-движок [H3952] назвал `H3952_WEBHOOK_MISSING` — **ложно**: else-ветка VERDICT_JS трактует ошибку ноды (нет `statusCode` → 0) как «мёртвый токен». Запись в облаке была (`status: completed`, 251 МБ).
3. Освободили диск (`/srv/restore-tmp` 7,7G — мусор реставрации H3396 от 23-08 + `journalctl --vacuum-size=200M` 1,6G → 83%) и реплеили вебхук телом из exec 2749 (§3.1 рунбука) → exec **2754**.
4. 2754: YouTube залит ([youtu.be/6YCCPgeGwYc](https://youtu.be/6YCCPgeGwYc)), НО поиск обложки (`name = '2026-09-10.jpg'` в папке курса с листа) вернул пусто → «Обложки нет — пропуск» → **Merge (mode=combine) не собрал вход 0 → exec зелёно завершился на 28-й ноде**, молча пропустив Rutube×3, плейлист, субтитры, DeepSeek, СОЗДАЁМ УРОК В АДМИНКЕ1 и финальный TG. Второй класс тихого успеха за день.

## Таймлайн (UTC, 10-09-2026)

| Время | Событие |
|---|---|
| ~05:00 | `restic-forget --prune` (daily, .92) отработал — ЕЩЁ до основной утечки места |
| ≤10:27 | корень .91 достиг 100% (последний зелёный полный exec — 2723, закончился 22:15 UTC 09-09) |
| 10:27:04 | exec **2749** (вебхук записи): fresh-link ОК (MP4 completed), «DOWNLOAD свежая (Гасунс)» → `ENOSPC`, «HEAD вебхук-токен» → `ENOSPC` → вердикт `webhook_missing` → exec error + TG-алерт |
| 10:35–11:00 | диагностика: df 100%, du → `/srv/restic/systema` 24G (репо-хаб, НЕ трогать), `/srv/restore-tmp` 7,7G (мусор H3396), journal 1,8G |
| 10:58 | `rm -rf /srv/restore-tmp` + `journalctl --vacuum-size=200M` → **83%, 9,4G свободно** |
| 11:05 | реплей: тело вебхука извлечено из runData exec 2749 (n8n-флэттен: строковые цифры = ссылки на контейнеры, голые int = литералы) → `POST /webhook/86446208-…` → exec **2754** |
| 11:09 | 2754 = `success` за 3м43с: YouTube ОК, обложка НЕ найдена → Merge dead-end → **Rutube/плейлист/субтитры/таймкоды/урок/финальный TG пропущены молча** |
| 11:58 | exec **2763** — следующий урок дня (meeting 8632735838, не дубль), waiting до 13:46 UTC |

## Доказательства

- 2749 runData: «Свежая ссылка (Гасунс)» → `recording_files[1] = MP4 shared_screen_with_speaker_view, status completed, 251297067 bytes`; «HEAD вебхук-токен» → `{"error": "ENOSPC: no space left on device, write"}`.
- VERDICT_JS (scripts/n8n_zoom14_h3952_freshlink_verdicts.py, строка 219): `webhook_missing` — else-выпадение; HEAD-error неотличим от HEAD 4xx.
- Прецеденты полного пути: exec 2723 (64 ноды, non-Hindi) и 2356 (59 нод, Hindi с обложкой) — оба дошли до СОЗДАЁМ УРОК В АДМИНКЕ1; 2754 — 28 нод, стоп на Merge.
- Граф: вход 0 Merge приходит ТОЛЬКО с ветки обложки (`Add a playlist item (Hindi)` → `ЗАГРУЗКА НА РУТУБ` → Merge); «Обложки нет — пропуск» кормит только вход 2 → combine никогда не срабатывает на no-cover пути.
- «Upload a video (Hindi)» — plain upload **без дедупа** → полный реплей вебхука после 2754 = дубль YouTube (запрещено, runbook §7).

## Что сделано в ходе инцидента

1. Диск освобождён (см. выше); `df` мониторится — `.92`-плечо `restic-forget --prune` (daily 05:00 UTC) подберёт мусор утром 11-09. Скорость таяния ~0,6G/ч — ночь при 8,4G свободно переживаема.
2. Реплей 2754 доставил YouTube (приватный, unlisted, плейлист НЕ добавлен — см. пропуск).
3. Заминчен [H4513](https://github.com/gasyoun/Uprava/blob/main/handoffs/H4513-OxAlpha_Systema-Sanscriticum_n8n-zoom-cover-bypass-and-infra-verdict_10.09.26.md): рёбро «Обложки нет — пропуск» → «ЗАГРУЗКА НА РУТУБ» + вердикт `H3952_INFRASTRUCTURE_FAILURE` (VERDICT_JS + probe + тест + рунбук).

## Остатки (human / GTD)

1. **Хвост урока №5 вручную (Play B):** урок в Filament с `youtube_url = https://youtu.be/6YCCPgeGwYc`; Rutube — загрузка руками; обложка `2026-09-10.jpg` → папка «Хинди с Костиной» на Drive (для будущих прогонов).
2. **2763 под наблюдением:** если у второго урока дня обложки нет — тот же тихий пропуск после 13:46 UTC.
3. Free-disk алерт на .91 — отсутствует (repo-size-sanity и deadman не смотрят df) — GTD.

_Dr. Mārcis Gasūns_
