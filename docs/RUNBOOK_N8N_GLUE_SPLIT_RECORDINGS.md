# RUNBOOK — склейка двух частей записи занятия (в т.ч. с двух Zoom-аккаунтов)

_Created: 17-11-2026 · Last updated: 17-11-2026_

**Audience:** дежурный агент / оператор. Триггеры: запись урока разбита на две сессии («начало записи час на одном аккаунте, завершение записи, ещё час на другом»), урок переехал между встречами/аккаунтами Zoom посреди занятия, труба отдала части как разные записи, гард остановил одну из частей.
**Хосты:** n8n `root@193.232.229.91` (контейнер `n8n-n8n-1`, SQLite `/home/node/.n8n/database.sqlite`, хост-путь `/opt/n8n/storage/database.sqlite`); Laravel `root@193.232.229.92` (`/var/www/html`, БД `laravel`).
**Live-воркфлоу:** `ZOOM + АДМИНКА`, id `1EIqqNzMl5NNIxST`. Спутники: [RUNBOOK_N8N_RECORDING_STALL.md](RUNBOOK_N8N_RECORDING_STALL.md) (штатные гэпы), PROTOCOL_N8N_POSTMAN_RECORDINGS_25-08-2026.md в Uprava (вебхук-конвейер, гарды, «Восстановление упавшего запуска»).

## 0. Что считаем «склейкой»

Две записи ОДНОГО занятия (разные recording-сессии, возможно разных встреч/аккаунтов Zoom) → одно видео: `part1` + `part2` → `ffmpeg concat -c copy` → `merged.mp4` → публикация через клон хвоста трубы (YouTube + плейлист + урок в админке + TG). Итог: одна ссылка, один урок, одна длительность.

## 1. Диагностика — собрать факты ДО любых действий

1. **Найти exec-ы обеих частей** (SQLite через `docker exec -e NODE_PATH=/usr/local/lib/node_modules/n8n/node_modules n8n-n8n-1 node script.js`; таблицы `execution_entity` + `execution_data`, ключ `executionId`):
   - список: `SELECT id,status,startedAt,waitTill FROM execution_entity WHERE workflowId='1EIqqNzMl5NNIxST' ORDER BY id DESC LIMIT 15`;
   - «короткие» success по 2–5 с = остановки гардов (Settings/Titles), они держат частичный runData.
2. **Вытащить body вебхука** из `execution_data.data` (n8n 2.x/3.x: плоский массив, значения-ссылки — числовые СТРОКИ-индексы; резолвить рекурсивно с глубиной ≥ 12 и фильтром «строка из цифр < длины массива»). Нужны: `event`, `payload.account_id`, `payload.object.{id,uuid,topic,start_time,duration,host_email,recording_files[]}`. `recording_files` содержит **подписанные `webhook_download` URL — они работают БЕЗ OAuth** (≤24 ч).
3. **Опознать урок и его название — из расписания, а не из темы Zoom** (тема часто «Вебинар»):
   `SELECT s.id,s.title,s.start,s.end,s.course_id,c.title,c.zoom_link FROM schedules s JOIN courses c ON c.id=s.course_id WHERE s.start BETWEEN '<день 05:00>' AND '<день 09:00>' AND s.deleted_at IS NULL;`
   Открытое занятие идёт ПАРАЛЛЕЛЬНО групповому (разные встречи) — сверять по времени и преподавателю.
4. **Реестр аккаунтов Zoom** — в коде ноды `Code in JavaScript1`: `account_id → ветка/cred` («Zoom ОРС», «Zoom Цыди»). Разные части могут принадлежать разным аккаунтам → разные `meeting_id` на одну и ту же лекцию.
5. **Проверить, что уже сделано трубой** для каждой части: `Normalize YT → uploadId`, `СОЗДАЁМ УРОК В АДМИНКЕ → lesson_id`, `DELETE FROM ZOOM → success`, выполненные ноды через runData.

## 2. Ловушки (каждая стоила крови)

1. **«Дедуп» пишется ДО гарда Settings.** Упавшая у гарда часть уже имеет uuid-строку во вкладке «Дедуп» (Automation_DB) → ранбучное «переиграйте вебхук» молча уйдёт в «Respond to Webhook (дубль)». Перед переигрыванием — удалить/очистить её строку; при склейке — наоборот, строки НЕ трогать (они и защищают от повторной обработки).
2. **DELETE FROM ZOOM удаляет облачные копии встречи.** Если одна часть уже прошла полный хвост трубы — её Zoom-копия могла быть удалена (`DELETE FROM ZOOM → success`), и источником остаётся Rutube/YouTube. Скачивай все части РАНЬШЕ, чем добавишь Settings-строку (иначе поздний вебхук второй части уйдёт в трубу и снесёт облако).
3. **OAuth-рефреш Zoom в CLI-контексте n8n 3.x не работает** («Invalid access token» даже с живым refresh-токеном). OAuth-путь («Свежая ссылка») валиден только из ЖИВОГО процесса — проверять вебхук-триггером temp-workflow (import → `update:workflow --active=true` → `docker restart` → curl `http://127.0.0.1:5678/webhook/<path>`), НЕ через `n8n execute`.
4. **Свежий signed URL через API — только при живом OAuth.** Если оба Zoom-креда отдают «Invalid access token» (бывает после rotation-race параллельных прогонов или регрессии рефреша) — это блокер трубы для ВСЕХ ОРС-записей: MG переподключает «Reconnect» в UI n8n (Credentials → Zoom ОРС / Zoom Цыди). До реконнекта источник — только webhook_download URL.
5. **Задушенный signed URL.** Живой `webhook_download`-URL после DELETE может отдавать 200 на скорости ~13 КБ/с (200 МБ ≈ 4–5 ч): годится лишь как ночной фолбэк (`curl -C - --retry`), не как основной путь.
6. **YouTube-копия: 403 с датацентр-IP.** yt-dlp на .91 получает HTTP 403; качать копию надо с домашней машины, затем `scp` на .91 (нужны только видео-поток + аудио-поток, мерж — уже ffmpeg'ом на .91).
7. **Rutube отдаёт максимум 360p** для private-видео (m3u8-renditions 144/240/360), а `/api/video/{id}/` возвращает view-страницу, не CDN-файл. Как источник — только когда части нативного 360p.
8. **Устаревшая Titles-строка = кривое название всюду** (видео YT, Rutube, урок). Если преподаватель не обновил `last_topic`, труба тиражирует прошлую тему с прошлой датой. Склейка — повод заодно починить: `videos.update` (YT), `PATCH /api/video/{id}/` (Rutube), `UPDATE lessons SET title=…` (.92).
9. `pkill -f '<pattern>'` по ssh убивает твою же ssh-команду, если pattern встречается в её собственной командной строке — только через файл-скрипт на сервере.
10. Temp-workflow: после import — `docker restart n8n-n8n-1` (FINDINGS §546: живой процесс не перерегистрирует вебхуки); `cmdline`-порты брокера на каждый `n8n execute` — разные (5680+).

## 3. Источники видео — по убыванию приоритета

| # | Источник | Условие | Ограничение |
|---|---|---|---|
| 1 | `webhook_download` URL из `execution_data` нужного exec | URL жив (curl HEAD/200) | ≤24 ч, может быть задушен; подписан, OAuth не нужен |
| 2 | «Свежая ссылка» через Zoom API (живой процесс) | OAuth-кред жив | 200 МБ «как есть», полный битрейт |
| 3 | Rutube private-копия (yt-dlp `.../video/private/<id>/?p=<pass>`) | часть уже залита трубой | max 360p |
| 4 | YouTube-копия (yt-dlp с домашней машины → `scp`) | часть уже залита трубой | max 360p, IP-блок на .91 |
| 5 | Локальные биндинги трубы | почти никогда | «Execute a command»/«Чистка хранилища» чистят |

**Правило:** сначала `ffprobe` исходника известной части (часто Zoom пишет «shared_screen_with_speaker_view» в 640×360/400 кбит/с — тогда 360p-копия эквивалентна оригиналу и качество не теряется).

## 4. Скачивание

```sh
mkdir -p /opt/n8n/storage/tmp_merge
# Zoom signed URL (part):
curl -sL -o /opt/n8n/storage/tmp_merge/part2.mp4 "<webhook_download_url>"
# YT-пара (с домашней машины), затем на .91:
python -m yt_dlp -f "<video_id>+<audio_id>" -o "p1_%(format_id)s.%(ext)s" "https://youtu.be/<videoid>"
scp p1_*.mp4 p1_*.m4a root@193.232.229.91:/opt/n8n/storage/tmp_merge/
# мерж потоков на .91:
ffmpeg -y -i p1_134.mp4 -i p1_140.m4a -c copy part1.mp4
```
Диск-префлайт: нужно ≈ (сумма частей × 1) + merged; при < 3 ГБ свободных — чистить `tmp_merge` сразу после публикации.

## 5. Склейка (двухаккаунтный случай — ОБЯЗАТЕЛЬНА проверка совместимости)

```sh
ffprobe -v error -show_entries stream=codec_name,width,height,r_frame_rate,bit_rate /opt/n8n/storage/tmp_merge/part1.mp4
ffprobe -v error -show_entries stream=codec_name,width,height,r_frame_rate,bit_rate /opt/n8n/storage/tmp_merge/part2.mp4
# ОСНОВНОЙ путь — concat-фильтр (иммунен к разным таймстемпам частей):
ffmpeg -y -i /opt/n8n/storage/tmp_merge/part1.mp4 -i /opt/n8n/storage/tmp_merge/part2.mp4 \
  -filter_complex '[0:v][0:a][1:v][1:a]concat=n=2:v=1:a=1[v][a]' \
  -map '[v]' -map '[a]' -c:v libx264 -preset veryfast -crf 23 -c:a aac -b:a 128k \
  /opt/n8n/storage/tmp_merge/merged.mp4
ffprobe -v error -show_entries format=duration,size -of default=noprint_wrappers=1 /opt/n8n/storage/tmp_merge/merged.mp4
```
- **Demuxer-путь (`-f concat … -c copy`) НЕ использовать без проверки длительности:** на разнородных источниках (YT-dash + Zoom-вебхук) контейнерные таймстемпы несовместимы — 17-11-2026 дал `duration=33308 с` при сумме 8882 и `Non-monotonic DTS`; аудио-реэнкод не спас. Если части с одинаковых аккаунтов/настроек — `-c copy` быстр, но ОБЯЗАТЕЛЬНО сверь duration с суммой; разошлась — фильтр.
- Фильтр-путь: ~10 мин CPU на 2,5 ч 360p, +40% к размеру (crf 23; кейс 17-11: 304 МБ).
- **Разные разрешения** (1080p × 360p): сначала привести part1 к part2 (`scale`,`fps`), затем тем же фильтром; либо перекачать part1 в полном качестве (источники №1/№2).
- Длительность merged должна равняться сумме частей ±1 с.

## 6. Публикация: клон хвоста трубы (temp-webhook workflow)

1. Взять живой workflow из SQLite (`workflow_entity.nodes`), склонировать узлы вербатим: `Upload a video` (cred «ОРС YouTube»/«YouTube account»), `Normalize YT`, `Add a playlist item`, `СОЗДАЁМ УРОК В АДМИНКЕ` (cred `samskrte lesson-sync`), `TG финальная` (`zapisi-ORSbot`), Encoding Guard.
2. Собрать минимальный лейн: `Webhook → Read Binary File(/home/node/.n8n/tmp_merge/merged.mp4) → Upload a video (title статикой, unlisted) → Normalize YT → Add a playlist item → СОЗДАЁМ УРОК → TG → RespondToWebhook`.
3. Захардкодить в jsonBody урока: `course_id`, `group_id: null`, `block_number: null`, `title`, `topic: null`, `lesson_date` (= дата урока), `youtube_url: 'https://youtu.be/' + uploadId`, `rutube_url: null`, `duration_minutes` (= merged duration).
4. Import (CLI) → `update:workflow --active=true` → `docker restart n8n-n8n-1` → `curl -X POST http://127.0.0.1:5678/webhook/<path>`.
5. **Rutube в клон-лейне пропускается**: нода `ЗАГРУЗКА НА РУТУБ` тянет файл по `url` из Zoom — у склейки Zoom-URL нет. Остаток: перезалить вручную или Rutube-API отдельно (нужен прямой файл/URL).
6. **Транскрипт/оглавление** (`Скачиваем аудио → Deepgram → AI Agent → ОБНОВЛЯЕМ ВИДЕО`) в клон не входят — пост-хок прогнать «транскриб из таблицы + таймкоды» или оставить.

## 7. Settings/Titles-строки курса (для БУДУЩИХ встреч)

Порядок — ПОСЛЕ скачивания всех частей (см. ловушку 2):
1. Settings (вкладка `Settings`, Automation_DB): одна строка на `meeting_id`: `tg_chat_id` (курсовой чат или ЛС MG `427307131` для открытых/частных), `yt_channel` (`ors`/`hindi`), `yt_playlist_id`, `ru_playlist_id` (пусто = Rutube-плейлист пропускается, безопасно), `drive_folder_id`, `title`.
2. Titles (ключ — `chat_id`!): НЕ плодить второй ряд с тем же `chat_id` — перuse существующий; заполнить `course_id`, `group_id`, `block_number`, `last_topic` (название СЛЕДУЮЩЕГО урока), `description`.
3. Новые YT-плейлист и Drive-папку курса создавать temp-workflow'ом (ноды `youTube playlist create` / `googleDrive folder create`).
4. `ru_playlist_id` пустой допустим (узел Rutube-плейлиста onError=continue).

## 8. Верификация

1. Новый exec клон-лейна = `success`; `RespondToWebhook` вернул `uploadId` и `lesson_id`.
2. В админке: `SELECT id,course_id,title,lesson_date,youtube_url,duration_minutes FROM lessons WHERE id=<lesson_id>;` — курс, название, дата, ссылка, длительность.
3. YT: видео unlisted, в плейлисте (`PLfZ-…`), длительность = merged.
4. TG в ЛС MG/группу — доставлено.
5. `ffprobe` merged = сумма частей.
6. Только ПОСЛЕ этого — чистка Zoom-облака (`DELETE /v2/meetings/{id}/recordings`, вручную) и `tmp_merge` с диска.

## 9. Остатки, которые НЕ закрывает клон-лейн

- Rutube-копия склейки (см. §6.5).
- Транскрипт/оглавление в уроке (см. §6.6).
- Если Titles был устаревшим — ремонт названий: YT `videos.update`, Rutube `PATCH`, `UPDATE lessons`.
- Если одна часть ушла в трубу отдельным уроком — решить с MG, какой урок считать каноническим (замена `youtube_url` в существующем уроке = прямой `UPDATE` в .92; API обновления урока нет — только `from-zoom`/`transcript`).
- Любой сбой OAuth Zoom — «Reconnect» каждой затронутой креды в UI n8n силами MG; до этого труба стоит для ВСЕХ записей соответствующего аккаунта.

## 10. Worked example — 17-11-2026 (первый двухаккаунтный случай)

- Занятие: «Грамматика по Кочергиной гр.63 (#4, 17.11.26)» (schedule 2859, course 452; открытая параллель — курс 400 «Открытые занятия и вебинары»).
- **part 1**: встреча `88336454045` (аккаунт Цыди, `K4GUTP…`), 04:37–06:01 UTC (07:37–09:01 МСК), 84 мин, 200,8 МБ, 640×360 → exec 15818, прошёл трубу целиком: YT `nx9QTetLlJw` (название устарело из Titles: «Кочергина 1 (проверка) (#1, 20.10.26)»), Rutube private `463f46eb…`, урок 2130, DELETE FROM ZOOM success.
- **part 2**: встреча `85418878877` (аккаунт ОРС, `ZEyC…`, host anatoly.artemenko), 06:02–07:06 UTC (09:02–10:06 МСК), 64 мин, 192,8 МБ → вебхуки exec 15835 (остановлен гардом «в Settings НЕТ строки», запись в Zoom НЕ тронута) и 15836 (дедуп-дубль). Zoom-копия скачана по `webhook_download` URL без OAuth.
- Обрыв OAuth в тот день: оба Zoom-креда («ОРС» и «Цыди») в CLI и в живом процессе → «Invalid access token» → обновление обоих руками MG.
- Итог-план: part1 (YT 360p-пара с домашней машины; YT блокирует .91-IP, Rutube max 360p) + part2 (Zoom signed) → `ffmpeg concat -c copy` → merged 148 мин → клон-лейн (курс 400 + ЛС MG) + Settings/Titles-строки `85418878877`.

## Приложение — рабочие сниппеты

```sh
# дамп exec (внутри контейнера):
cat > /tmp/dump.js <<'EOF'
const sqlite3 = require('sqlite3');
const db = new sqlite3.Database('/home/node/.n8n/database.sqlite');
db.get('SELECT data FROM execution_data WHERE executionId=<ID>', (e,r)=>{
  require('fs').writeFileSync('/tmp/exec_<ID>.json', r.data); db.close();
});
EOF
docker cp /tmp/dump.js n8n-n8n-1:/tmp/dump.js && \
docker exec -e NODE_PATH=/usr/local/lib/node_modules/n8n/node_modules n8n-n8n-1 node /tmp/dump.js

# список exec-ов воркфлоу:
# SELECT id,status,startedAt,waitTill FROM execution_entity WHERE workflowId='1EIqqNzMl5NNIxST' ORDER BY id DESC LIMIT 15;

# temp-workflow из CLI: import → activate → restart → webhook-триггер изнутри хоста:
docker exec n8n-n8n-1 n8n import:workflow --input=/tmp/temp.json
docker exec n8n-n8n-1 n8n update:workflow --id=<ID> --active=true
docker restart n8n-n8n-1 && sleep 18 && curl -s http://127.0.0.1:5678/healthz
curl -s -X POST http://127.0.0.1:5678/webhook/<path>
```

_Dr. Mārcis Gasūns_
