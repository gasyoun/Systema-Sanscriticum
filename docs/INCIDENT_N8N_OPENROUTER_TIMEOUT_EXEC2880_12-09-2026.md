# INCIDENT — n8n ZOOM 1.4: OpenRouter deepseek-v4-pro не уложился в 600 с на оглавлении 101-минутной лекции → exec 2880 упал, хвост (урок в админке + TG + описания) не выполнен

_Created: 12-09-2026 · Last updated: 12-09-2026_

**Workflow:** `ZOOM 1.4 (Final) + АДМИНКА ТЕСТ` ([1EIqqNzMl5NNIxST](https://context-ai.ru/workflow/1EIqqNzMl5NNIxST)), host `root@193.232.229.91`.
**Урок:** «Грамматика хинди гр. 2, суббота 13:00 (2026)», meeting Zoom **86124823978**, Account_2 (host anatoly.artemenko@gmail.com), старт 12-09-2026 09:59 UTC, 101 мин, 334 МБ, 2 файла записи.
**Exec:** [2880](https://context-ai.ru/workflow/1EIqqNzMl5NNIxST/executions/2880) — упал на `AI Agent1`, `NodeOperationError: Request timed out.`
**Класс:** тот же конвейер, что [инцидент 10-09](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/INCIDENT_N8N_ENOSPC_HINDI_COVER_MERGE_DEADEND_10-09-2026.md), но новый механизм отказа.

## TL;DR

1. Вебхук `recording.completed` пришёл 11:52:12 UTC; весь конвейер **до AI-шага прошёл успешно**: DOWNLOAD (10,2 с) → YouTube upload (176,8 с) → обложка/плейлист → Rutube ×2 → аудио → транскрипт (`HTTP Request2`, 10,4 с).
2. `AI Agent1` (LangChain ToolsAgent, systemMessage: «детальное оглавление видео с таймкодами», prompt `={{ $json.transcript }}`) вызвал `OpenRouter Chat Model1` = **`deepseek/deepseek-v4-pro`** и не дождался ответа: **600 021 мс = ровно жёсткий cap 600 с** → `Request timed out.` → exec = error в 13:41:22 UTC, TG-алерт MG.
3. **Источник контента цел:** видео на YouTube и Rutube залито ДО падения; Zoom-оригинал жив (DELETE-чистки — в хвосте, не выполнялись); локальные temporaries не вычищены (~334 МБ, диск .91 80% — не критично).
4. **Потерян только хвост:** урок в админке, оглавление/описания на YT/Rutube, TXT на Drive, финальный TG-анонс. Студенты субботнего курса запись в кабинете не видят.
5. Полный ретрай exec **запрещён** — `Upload a video` без дедупа, будет дубль YouTube/Rutube (рунбук §7, тот же вывод GTD-строки 10-09).

## Таймлайн (UTC, 12-09-2026)

| Время | Событие |
|---|---|
| 09:59 | лекция начата (Zoom, Account_2) |
| ~11:40 | лекция окончена (101 мин) |
| 11:52:12 | вебхук `recording.completed` → exec **2880** стартовал |
| 11:52–13:31 | конвейер: DOWNLOAD → YouTube → обложка → Rutube ×2 → плейлист → аудио/транскрипт — все success |
| 13:31:22 | `AI Agent1` стартовал (startTime 1789219882676) |
| 13:41:22 | `OpenRouter Chat Model1` = 600 021 мс → timeout; `AI Agent1` = 600 182 мс → exec **error**, алерт |

## Доказательства (runData exec 2880, таблица `execution_data`)

- Хвост списка: `HTTP Request2 success 10412` → `Code in JavaScript3/4 success` → `OpenRouter Chat Model1 error 600021 ERR=Request timed out.` → `AI Agent1 error 600182 ERR=Request timed out.` (`lastNodeExecuted: AI Agent1`).
- Успешный хвост до AI: `DOWNLOAD 10201`, `Upload a video 176828`, `ЗАГРУЗКА НА РУТУБ 594` + `ЗАГРУЗКА НА РУТУБ1 365`, `ДОБАВЛЯЕМ В ПЛЕЙЛИСТ 259`, `Скачиваем аудио 33582/3251`.
- Нода модели: `model = deepseek/deepseek-v4-pro`, cred «OpenRouter account», `options = {}`, `retryOnFail` не задан.
- Сеть до OpenRouter на момент проверки (14:1x UTC) жива: `curl https://openrouter.ai/api/v1/models` = HTTP 403 за 0,06 с (TLS/WAF-уровень, канал в порядке) — таймаут не сетевой, а **время генерации > cap**.
- Декод-гоча этой версии n8n: таблица называется `execution_data` (не `execution_data_entity`); флэттен-формат — строковые цифры = ссылки в контейнер, но разыменование **однократное** (повторное разыменование литералов-цифр вроде meeting-id даёт IndexError).

## Почему упало именно сейчас

Из 7 «больших» прогонов конвейера (2667, 2723, 2814, 2848, 2863 — 8631–9667 с общего времени) этот — **первый таймаут AI-шага**. Гипотеза: `deepseek-v4-pro` (reasoning-класс) на самом длинном из свежих транскриптов (101 мин) генерировал оглавление >600 с; латентность провайдера плавает день ото дня. `retryOnFail` у ноды выключен — одной попытки не хватило.

## Что делать (порядок предпочтения)

1. **Хирургический реплей хвоста в UI (MG, ~15–30 мин, без риска дублей):** открыть [exec 2880](https://context-ai.ru/workflow/1EIqqNzMl5NNIxST/executions/2880) → `Copy to editor` → у `AI Agent1` в панели входа «Pin data» → `Execute step` (если снова timeout — повторить: латентность плавает, upload-ноды при этом НЕ перезапускаются) → после успеха пошагово `Execute step` по хвосту до финального TG. Попутно там же: `OpenRouter Chat Model1` → Settings → **Retry On Fail** (Max tries 2, Wait 60 000 мс) — закрывает класс.
2. **Агент-lane (~1–2 ч, по слову MG):** хирургический реплей через API по прецеденту 10-09 Play B (helpers `/root/h3687_*.sh`, ключ `/root/.n8n_api_key_h3687`).
3. **@DECIDE (MG):** модель оглавлений — оставить `deepseek-v4-pro` (+retry из п.1) или сменить на более быстрый класс: медленная генерация на длинных лекциях будет возвращаться.

## Остатки (human / GTD)

1. Хвост урока 86124823978 — вариант 1 или 2 выше (сегодня: субботний курс, студенты ждут).
2. Retry On Fail на `OpenRouter Chat Model1` — в том же UI-заходе.
3. Рунбук `RUNBOOK_N8N_RECORDING_STALL.md` §7: дописать класс «OpenRouter timeout на длинном транскрипте» (симптом: exec падает на `AI Agent1` после ~600 с при зелёных upload-нодах; полный ретрай запрещён).

_Dr. Mārcis Gasūns_
